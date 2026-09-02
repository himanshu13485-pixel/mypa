<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A group you can be pointed at, rather than typed into.
 *
 * Adding forty people one name at a time makes the admin a queue. A link
 * moves the work to the person joining - either admitting them outright or
 * putting them in front of an admin as a single yes or no, which is a far
 * smaller job than looking each of them up.
 */
class GroupInviteController extends Controller
{
    /** The link as it stands, for whoever runs the group. */
    public function show(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);

        return response()->json(['data' => $this->serialize($group)]);
    }

    /**
     * Turn the link on, off, or change what following it does.
     *
     * Off clears the token rather than setting a flag beside it: a token
     * that still resolves while a boolean says "disabled" is one bug away
     * from being a door that was never actually shut.
     */
    public function update(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'in:' . implode(',', Group::INVITE_MODES)],
        ]);

        if (array_key_exists('mode', $data)) {
            $group->update(['invite_mode' => $data['mode']]);
        }

        if (array_key_exists('enabled', $data)) {
            if ($data['enabled']) {
                if (! $group->invite_token) {
                    $group->rotateInviteToken();
                }
            } else {
                $group->update(['invite_token' => null]);
            }
        }

        return response()->json(['data' => $this->serialize($group->fresh())]);
    }

    /**
     * A new link, and the old one stops working.
     *
     * The only honest way to take back a URL already forwarded to people you
     * cannot name.
     */
    public function rotate(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);

        $group->rotateInviteToken();

        return response()->json(['data' => $this->serialize($group->fresh())]);
    }

    /**
     * What is behind a link, for whoever is holding it.
     *
     * Signed in, because joining a group is something an account does. It
     * says only what somebody needs in order to decide whether to walk in.
     */
    public function preview(Request $request, string $token): JsonResponse
    {
        $group = Group::where('invite_token', $token)->first();
        abort_unless($group, 404, 'This invite link is no longer active.');

        $me = $request->user();

        return response()->json(['data' => [
            'uuid' => $group->uuid,
            'name' => $group->name,
            'type' => $group->type,
            'description' => $group->description,
            'member_count' => $group->members()->count(),
            'mode' => $group->invite_mode,
            'already_member' => $group->members()->where('users.id', $me->id)->exists(),
            'already_requested' => GroupJoinRequest::where('group_id', $group->id)
                ->where('user_id', $me->id)->where('status', 'pending')->exists(),
        ]]);
    }

    /**
     * Following the link through.
     *
     * An open group admits; a request group records the ask and tells the
     * people who can answer it. Either way this is idempotent - somebody who
     * taps twice has not asked twice.
     */
    public function join(Request $request, string $token): JsonResponse
    {
        $group = Group::where('invite_token', $token)->first();
        abort_unless($group, 404, 'This invite link is no longer active.');

        $me = $request->user();

        if ($group->members()->where('users.id', $me->id)->exists()) {
            return response()->json([
                'message' => 'You are already in this group.',
                'data' => ['status' => 'member', 'group_uuid' => $group->uuid],
            ]);
        }

        if ($group->invite_mode === 'request') {
            return $this->ask($group, $me);
        }

        if (! app(SubscriptionEntitlementService::class)->canAddGroupMember($group->owner, $group)) {
            return response()->json([
                'message' => 'This group is full. Ask an admin to make room, or to upgrade the plan.',
            ], 422);
        }

        $this->admit($group, $me);

        return response()->json([
            'message' => 'You have joined ' . $group->name . '.',
            'data' => ['status' => 'member', 'group_uuid' => $group->uuid],
        ], 201);
    }

    /** Record the ask, or leave a standing one alone. */
    protected function ask(Group $group, User $me): JsonResponse
    {
        $existing = GroupJoinRequest::where('group_id', $group->id)->where('user_id', $me->id)->first();

        if ($existing && $existing->status === 'pending') {
            return response()->json([
                'message' => 'You have already asked to join. An admin will decide.',
                'data' => ['status' => 'pending', 'group_uuid' => $group->uuid],
            ]);
        }

        /*
         * A decided row is reopened rather than duplicated.
         *
         * Somebody declined in March may be welcome in June, and making them
         * unreachable forever over one old no is a worse failure than showing
         * an admin a familiar name a second time.
         */
        if ($existing) {
            $existing->update(['status' => 'pending', 'decided_by' => null, 'decided_at' => null]);
            $joinRequest = $existing;
        } else {
            $joinRequest = GroupJoinRequest::create([
                'group_id' => $group->id,
                'user_id' => $me->id,
                'status' => 'pending',
            ]);
        }

        $this->tellManagers($group, $me->name . ' asked to join ' . $this->quoted($group->name) . '.');

        return response()->json([
            'message' => 'Your request has been sent to the group admins.',
            'data' => [
                'status' => 'pending',
                'group_uuid' => $group->uuid,
                'request_uuid' => $joinRequest->uuid,
            ],
        ], 201);
    }

    /** Who is waiting, for whoever can answer. */
    public function pending(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);

        $waiting = $group->joinRequests()->with('user.profile')->where('status', 'pending')
            ->orderBy('created_at')->get();

        return response()->json(['data' => $waiting->map(fn ($r) => [
            'uuid' => $r->uuid,
            'name' => $r->user?->name,
            'username' => $r->user?->username,
            'avatar' => $r->user?->profile?->avatar,
            'photo_path' => $r->user?->profile?->photo_path,
            'asked_at' => $r->created_at->toIso8601String(),
        ])->values()]);
    }

    /** Yes or no, and the person waiting is told either way. */
    public function decide(Request $request, Group $group, GroupJoinRequest $joinRequest): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);
        abort_unless($joinRequest->group_id === $group->id, 404);
        abort_unless($joinRequest->status === 'pending', 409, 'This request has already been decided.');

        $data = $request->validate(['action' => ['required', 'in:approve,decline']]);
        $approved = $data['action'] === 'approve';

        if ($approved && ! app(SubscriptionEntitlementService::class)->canAddGroupMember($group->owner, $group)) {
            return response()->json([
                'message' => "The group owner's plan has reached its member limit. Upgrade the plan to admit more people.",
            ], 422);
        }

        $joinRequest->update([
            'status' => $approved ? 'approved' : 'declined',
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        if ($approved) {
            $this->admit($group, $joinRequest->user, $request->user());
        } elseif ($joinRequest->user) {
            /*
             * Told, not left wondering.
             *
             * Somebody who asked and heard nothing asks again next week,
             * which costs the admin more than the one line it takes to
             * say no.
             */
            $joinRequest->user->notify(new SocialNotification(
                'group_declined',
                'Your request to join ' . $this->quoted($group->name) . ' was not accepted.',
                ['group_uuid' => $group->uuid],
                '/groups',
            ));
        }

        return response()->json(['message' => $approved ? 'Added to the group.' : 'Request declined.']);
    }

    /** Put somebody in the group, and let the group chat know they are there. */
    protected function admit(Group $group, User $user, ?User $by = null): void
    {
        $group->members()->syncWithoutDetaching([
            $user->id => ['role' => 'member', 'added_by' => $by?->id],
        ]);

        app(GroupController::class)->syncConversationFor($group);

        $user->notify(new SocialNotification(
            'group_added',
            $by
                ? $by->name . ' let you into ' . $this->quoted($group->name) . '.'
                : 'You joined ' . $this->quoted($group->name) . '.',
            ['group_uuid' => $group->uuid],
            '/groups',
        ));
    }

    /** The people who can answer a request, told there is one. */
    protected function tellManagers(Group $group, string $line): void
    {
        $group->members()
            ->wherePivotIn('role', Group::MANAGER_ROLES)
            ->get()
            ->each(fn (User $manager) => $manager->notify(new SocialNotification(
                'group_join_request',
                $line,
                ['group_uuid' => $group->uuid],
                '/groups',
            )));
    }

    protected function quoted(string $text): string
    {
        return '"' . $text . '"';
    }

    protected function serialize(Group $group): array
    {
        return [
            'enabled' => (bool) $group->invite_token,
            'mode' => $group->invite_mode,
            'url' => $group->invite_token
                ? rtrim((string) config('mypa.frontend_url'), '/') . '/j/' . $group->invite_token
                : null,
            'pending_count' => $group->joinRequests()->where('status', 'pending')->count(),
        ];
    }
}
