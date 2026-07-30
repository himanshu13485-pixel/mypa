<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppId;
use App\Models\Group;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = Group::withMember($request->user())
            ->withCount(['members', 'tasks'])
            ->orderBy('name')
            ->get()
            ->map(fn ($g) => $this->serialize($g, $request));

        return response()->json(['data' => $groups]);
    }

    public function store(Request $request): JsonResponse
    {
        $entitlements = app(\App\Services\SubscriptionEntitlementService::class);
        if (! $entitlements->canCreateGroup($request->user())) {
            $upgrade = $entitlements->planWithHigherLimit(
                'max_groups',
                (int) $entitlements->planFor($request->user())->limit('max_groups'),
            );

            return response()->json([
                'message' => 'You have reached your plan\'s group limit.'
                    . ($upgrade ? " Upgrade to {$upgrade->name} for more groups." : ''),
                'upgrade_plan' => $upgrade?->slug,
            ], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'in:' . implode(',', Group::TYPES)],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $group = DB::transaction(function () use ($request, $data) {
            $group = Group::create($data + ['owner_id' => $request->user()->id]);
            $group->members()->attach($request->user()->id, ['role' => 'owner']);

            return $group;
        });

        return response()->json([
            'message' => 'Group created.',
            'data' => $this->serialize($group->loadCount(['members', 'tasks']), $request),
        ], 201);
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        $this->authorizeMember($request, $group);

        $group->load(['members.appId', 'members.profile'])->loadCount('tasks');

        return response()->json([
            'data' => $this->serialize($group, $request) + [
                'members' => $group->members->map(fn ($u) => [
                    'uuid' => $u->uuid,
                    'name' => $u->name,
                    'app_id' => $u->appId?->app_id,
                    'photo_path' => $u->profile?->photo_path,
                    'role' => $u->pivot->role,
                    'joined_at' => $u->pivot->created_at,
                ]),
            ],
        ]);
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'in:' . implode(',', Group::TYPES)],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
        ]);

        $group->update($data);

        return response()->json([
            'message' => 'Group updated.',
            'data' => $this->serialize($group->fresh()->loadCount(['members', 'tasks']), $request),
        ]);
    }

    public function destroy(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->owner_id === $request->user()->id, 403, 'Only the owner can delete a group.');

        // Group tasks/events/notes/files revert to personal items of their creators.
        $group->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    // --- Members ------------------------------------------------------------

    public function addMember(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);

        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:32'],
            'role' => ['sometimes', 'in:admin,manager,member,viewer'],
        ]);

        $target = app(\App\Services\AppIdService::class)->findVisibleUser($data['app_id'], $request->user());

        if (! $target) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        if ($group->members()->where('users.id', $target->id)->exists()) {
            return response()->json(['message' => 'This user is already a member.'], 409);
        }

        $entitlements = app(\App\Services\SubscriptionEntitlementService::class);
        if (! $entitlements->canAddGroupMember($request->user(), $group)) {
            return response()->json([
                'message' => "The group owner's plan has reached its member limit. Upgrade the plan to add more members.",
            ], 422);
        }

        $group->members()->attach($target->id, [
            'role' => $data['role'] ?? 'member',
            'added_by' => $request->user()->id,
        ]);

        $target->notify(new \App\Notifications\SocialNotification(
            'group_added',
            "{$request->user()->name} added you to the group “{$group->name}”.",
            ['group_uuid' => $group->uuid],
            '/groups',
        ));

        return response()->json(['message' => $target->name . ' added to the group.'], 201);
    }

    public function updateMember(Request $request, Group $group, string $userUuid): JsonResponse
    {
        abort_unless($group->canManage($request->user()), 403);

        $data = $request->validate(['role' => ['required', 'in:admin,manager,member,viewer']]);

        $member = $group->members()->where('uuid', $userUuid)->firstOrFail();

        abort_if($member->id === $group->owner_id, 422, "The owner's role cannot be changed.");

        $group->members()->updateExistingPivot($member->id, ['role' => $data['role']]);

        return response()->json(['message' => 'Member role updated.']);
    }

    public function removeMember(Request $request, Group $group, string $userUuid): JsonResponse
    {
        $member = $group->members()->where('uuid', $userUuid)->firstOrFail();
        $me = $request->user();

        // Members may leave on their own; managers may remove others; owner never removable.
        $leavingSelf = $member->id === $me->id;
        abort_unless($leavingSelf || $group->canManage($me), 403);
        abort_if($member->id === $group->owner_id, 422, 'The owner cannot be removed. Delete the group instead.');

        $group->members()->detach($member->id);

        return response()->json(['message' => $leavingSelf ? 'You left the group.' : 'Member removed.']);
    }

    // --- Group content ------------------------------------------------------

    public function tasks(Request $request, Group $group): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $this->authorizeMember($request, $group);

        $tasks = Task::where('group_id', $group->id)
            ->with(['category', 'group', 'user:id,uuid,name', 'assignees:id,uuid,name'])
            ->orderByRaw("status = 'completed'")
            ->orderBy('due_at')
            ->paginate(50);

        return \App\Http\Resources\TaskResource::collection($tasks);
    }

    // --- Helpers ------------------------------------------------------------

    protected function serialize(Group $group, Request $request): array
    {
        return [
            'uuid' => $group->uuid,
            'name' => $group->name,
            'type' => $group->type,
            'description' => $group->description,
            'icon' => $group->icon,
            'color' => $group->color,
            'is_owner' => $group->owner_id === $request->user()->id,
            'my_role' => $group->roleOf($request->user()),
            'members_count' => $group->members_count ?? $group->members()->count(),
            'tasks_count' => $group->tasks_count ?? 0,
            'created_at' => $group->created_at,
        ];
    }

    protected function authorizeMember(Request $request, Group $group): void
    {
        abort_unless(
            $group->members()->where('users.id', $request->user()->id)->exists(),
            403,
            'You are not a member of this group.'
        );
    }
}
