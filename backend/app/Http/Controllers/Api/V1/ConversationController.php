<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Group;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $conversations = Conversation::visibleTo($me)
            ->with(['members.profile', 'members.settings', 'group:id,uuid,name'])
            ->withCount('members')
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->paginate(30);

        $conversations->getCollection()->transform(fn ($c) => $this->serialize($c, $request));

        return response()->json($conversations);
    }

    /** Start (or reopen) a direct conversation with a user by App ID. */
    public function store(Request $request, AppIdService $appIds): JsonResponse
    {
        $data = $request->validate(['app_id' => ['required', 'string', 'max:32']]);

        $me = $request->user();
        $target = $appIds->findVisibleUser($data['app_id'], $me);

        if (! $target || $target->id === $me->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        // Privacy: who can message me
        $pref = $target->settings?->privacyValue('who_can_message') ?? 'connections';
        if ($pref === 'nobody') {
            return response()->json(['message' => 'This user is not accepting messages.'], 403);
        }
        if ($pref === 'connections' && ! $appIds->areConnected($me, $target)) {
            return response()->json([
                'message' => 'You can only message your connections. Send a connection request first.',
            ], 403);
        }

        $conversation = Conversation::directBetween($me, $target);

        return response()->json([
            'message' => 'Conversation ready.',
            'data' => $this->serialize(
                $conversation->load(['members.profile', 'members.settings'])->loadCount('members'),
                $request,
            ),
        ], 201);
    }

    /** Open (or create) the conversation attached to a group. */
    public function forGroup(Request $request, Group $group): JsonResponse
    {
        abort_unless($group->members()->where('users.id', $request->user()->id)->exists(), 403);

        $conversation = Conversation::firstOrCreate(
            ['type' => 'group', 'group_id' => $group->id],
            ['name' => $group->name, 'created_by' => $request->user()->id],
        );

        // Keep conversation membership in sync with group membership.
        $memberIds = $group->members()->pluck('users.id');
        $conversation->members()->syncWithoutDetaching($memberIds);

        return response()->json([
            'data' => $this->serialize(
                $conversation->load(['members.profile', 'members.settings', 'group:id,uuid,name'])->loadCount('members'),
                $request,
            ),
        ]);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->hasMember($request->user()), 403);

        $conversation->members()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);

        return response()->json(['message' => 'Conversation marked read.']);
    }

    public function toggleMute(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->hasMember($request->user()), 403);

        $pivot = $conversation->members()->where('users.id', $request->user()->id)->first()->pivot;
        $conversation->members()->updateExistingPivot($request->user()->id, [
            'muted_at' => $pivot->muted_at ? null : now(),
        ]);

        return response()->json(['message' => $pivot->muted_at ? 'Conversation unmuted.' : 'Conversation muted.']);
    }

    public function toggleArchive(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->hasMember($request->user()), 403);

        $pivot = $conversation->members()->where('users.id', $request->user()->id)->first()->pivot;
        $conversation->members()->updateExistingPivot($request->user()->id, [
            'archived_at' => $pivot->archived_at ? null : now(),
        ]);

        return response()->json(['message' => $pivot->archived_at ? 'Conversation unarchived.' : 'Conversation archived.']);
    }

    protected function serialize(Conversation $conversation, Request $request): array
    {
        $me = $request->user();
        $other = $conversation->otherMember($me);
        $myPivot = $conversation->members->firstWhere('id', $me->id)?->pivot;

        $lastRead = $myPivot?->last_read_at;
        $unread = $conversation->messages()
            ->where('user_id', '!=', $me->id)
            ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
            ->count();

        $onlineVisible = ! $other
            || ($other->settings?->privacyValue('online_status_visibility') ?? 'connections') !== 'nobody';

        return [
            'uuid' => $conversation->uuid,
            'type' => $conversation->type,
            'name' => $conversation->type === 'direct'
                ? ($other?->name ?? 'Unknown user')
                : ($conversation->name ?? $conversation->group?->name ?? 'Group chat'),
            'group_uuid' => $conversation->group?->uuid,
            'other_user' => $other ? [
                'uuid' => $other->uuid,
                'username' => $other->username,
                'app_id' => $other->appId?->app_id,
                'photo_path' => $other->profile?->photo_path,
                'last_seen_visible' => $onlineVisible,
            ] : null,
            'members_count' => $conversation->members_count ?? $conversation->members->count(),
            'unread_count' => $unread,
            'is_muted' => $myPivot?->muted_at !== null,
            'is_archived' => $myPivot?->archived_at !== null,
            'last_message_at' => $conversation->last_message_at,
        ];
    }
}
