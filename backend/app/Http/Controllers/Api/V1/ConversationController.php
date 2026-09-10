<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    /** The chat colours on offer. Mirrored in the client's chatThemes.ts. */
    public const THEMES = ['default', 'ocean', 'forest', 'violet', 'rose', 'sunset', 'graphite', 'midnight'];

    /**
     * The chats this person has, in the order they want them.
     *
     * Pinned first - the point of a pin is that the chat stops moving down
     * the list as other people talk - then the rest by when they last did.
     * Archived ones are not here at all unless asked for by name: archiving
     * a chat that still sat in the list would be a no-op with extra steps.
     */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $wantArchived = $request->boolean('archived');

        $conversations = Conversation::visibleTo($me)
            // Joined rather than queried per row: the ordering is by MY
            // membership, so it has to be in the same query that sorts.
            ->join('conversation_members as mine', function ($join) use ($me) {
                $join->on('mine.conversation_id', '=', 'conversations.id')
                    ->where('mine.user_id', '=', $me->id);
            })
            ->select('conversations.*')
            ->when(
                $wantArchived,
                fn ($q) => $q->whereNotNull('mine.archived_at'),
                fn ($q) => $q->whereNull('mine.archived_at'),
            )
            ->with(['members.profile', 'members.settings', 'group:id,uuid,name'])
            ->withCount('members')
            // 0 sorts before 1, so "is null = false" - the pinned ones - lead.
            ->orderByRaw('mine.pinned_at is null')
            ->orderByDesc('mine.pinned_at')
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.updated_at')
            ->paginate(30);

        $conversations->getCollection()->transform(fn ($c) => $this->serialize($c, $request));

        // So the list can offer the archive without opening it first.
        $archivedCount = \Illuminate\Support\Facades\DB::table('conversation_members')
            ->where('user_id', $me->id)
            ->whereNotNull('archived_at')
            ->count();

        return response()->json(array_merge($conversations->toArray(), [
            'archived_count' => $archivedCount,
        ]));
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

        /*
         * Chat membership IS group membership.
         *
         * syncWithoutDetaching only ever added, so somebody removed from the
         * group stayed in its chat for good: still reading it, still counted
         * in "3 members", and still able to post. sync() makes the room the
         * group and nothing else — it detaches exactly the people who are no
         * longer in it, which is the whole difference.
         */
        $memberIds = $group->members()->pluck('users.id');
        $conversation->members()->sync($memberIds);

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

        // Tell the senders. Without this the timestamp was recorded and never
        // went anywhere, so their ticks never changed — which is why every
        // message looked read the instant it was sent.
        broadcast(new \App\Events\MessageUpdated($conversation, '', 'read'))->toOthers();

        return response()->json(['message' => 'Conversation marked read.']);
    }

    /**
     * Someone is typing. Deliberately ephemeral — nothing is stored, the
     * signal just goes out to the other members and expires on their side.
     */
    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        // A block should stop the "typing…" line too, or it leaks presence to
        // someone who has been shut out.
        if ($conversation->blockBetween($me)) {
            return response()->json(['message' => 'ok']);
        }

        \App\Support\Realtime::toOthers(new \App\Events\UserTyping($conversation, $me));

        return response()->json(['message' => 'ok']);
    }

    /**
     * How long this conversation keeps what is said in it.
     *
     * Off by default, and off for every conversation that already exists: a
     * chat that quietly deletes its own history is the kind of surprise
     * nobody forgives, so it only ever starts because somebody in the room
     * chose it. The setting belongs to the conversation, not to one member —
     * a message that vanished for one person and not the other is not what
     * anybody means by disappearing messages.
     */
    public function setRetention(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->hasMember($request->user()), 403);

        $data = $request->validate([
            // Null keeps everything; the rest are the offered spans, in hours.
            'auto_delete_hours' => ['nullable', 'integer', Rule::in([24, 168, 720])],
        ]);

        $hours = $data['auto_delete_hours'] ?? null;
        $conversation->update(['auto_delete_hours' => $hours]);

        // Said out loud, in the room. Everyone whose words are now on a
        // timer deserves to be told, and to see who set it.
        $labels = [24 => '24 hours', 168 => '7 days', 720 => '30 days'];
        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'type' => 'text',
            'body' => $hours
                ? '🕓 ' . $request->user()->name . ' set messages to delete themselves after ' . $labels[$hours] . '.'
                : '🕓 ' . $request->user()->name . ' turned automatic deletion off — messages are kept from now on.',
        ]);

        return response()->json([
            'message' => $hours
                ? 'Messages older than ' . $labels[$hours] . ' will be deleted.'
                : 'Messages are kept.',
            'data' => ['auto_delete_hours' => $hours],
        ]);
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

    /**
     * Keep this chat at the top of my list.
     *
     * Mine alone - the other side's list is not reordered because I decided
     * this conversation matters. Uncapped on purpose: a limit of three is a
     * rule somebody has to discover by being refused, and a person who pins
     * everything has only made their list what it was.
     */
    public function togglePin(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->hasMember($request->user()), 403);

        $pivot = $conversation->members()->where('users.id', $request->user()->id)->first()->pivot;
        $pinned = $pivot->pinned_at === null;

        $conversation->members()->updateExistingPivot($request->user()->id, [
            'pinned_at' => $pinned ? now() : null,
        ]);

        return response()->json([
            'message' => $pinned ? 'Chat pinned to the top.' : 'Chat unpinned.',
            'data' => ['is_pinned' => $pinned],
        ]);
    }

    /**
     * What colour this chat is, for me.
     *
     * A theme is a reading preference, so it is stored per member and never
     * imposed on the other side. `apply_to_all` is here because the usual
     * reason to change one is that you want them all that way.
     */
    public function setTheme(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $data = $request->validate([
            // Null means the app's own colours - always an available answer.
            'theme' => ['nullable', 'string', Rule::in(self::THEMES)],
            'apply_to_all' => ['sometimes', 'boolean'],
        ]);

        $theme = $data['theme'] ?? null;

        if ($data['apply_to_all'] ?? false) {
            \Illuminate\Support\Facades\DB::table('conversation_members')
                ->where('user_id', $me->id)
                ->update(['theme' => $theme]);

            return response()->json([
                'message' => 'Every chat now uses this colour.',
                'data' => ['theme' => $theme, 'applied_to_all' => true],
            ]);
        }

        $conversation->members()->updateExistingPivot($me->id, ['theme' => $theme]);

        return response()->json([
            'message' => 'Chat colour saved.',
            'data' => ['theme' => $theme, 'applied_to_all' => false],
        ]);
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

    /**
     * Everyone in this conversation, and what they are in it.
     *
     * The roles come from the group, because for a group chat there is no
     * such thing as a separate chat role: the person an owner made an admin
     * is an admin here too. This list used to carry names and nothing else,
     * so the second admin of a group was indistinguishable from anybody
     * else — the badge was not missing from the screen, it had never been
     * sent to it.
     *
     * `can_remove` is answered per row rather than left to the client to work
     * out, because the rule is not "am I an admin": the owner can never be
     * removed, anybody may remove themselves, and only a manager may remove
     * somebody else. Three rules, decided in the one place that also
     * enforces them.
     */
    public function members(\Illuminate\Http\Request $request, \App\Models\Conversation $conversation): \Illuminate\Http\JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $group = $conversation->group;
        $iManage = $group?->canManage($me) ?? false;
        // Straight off the pivot table rather than through the relation: one
        // query, and no ambiguity about which `role` a joined select means.
        $roles = $group
            ? \Illuminate\Support\Facades\DB::table('group_members')
                ->where('group_id', $group->id)
                ->pluck('role', 'user_id')
            : collect();

        $members = $conversation->members()
            ->with(['profile:user_id,photo_path,avatar', 'settings'])
            ->orderBy('name')
            ->get()
            ->map(function ($u) use ($me, $group, $iManage, $roles) {
                $isOwner = $group && $u->id === $group->owner_id;
                $isMe = $u->id === $me->id;

                return [
                    'uuid' => $u->uuid,
                    'name' => $u->name,
                    'username' => $u->username,
                    'is_me' => $isMe,
                    'photo_path' => $u->profile?->photo_path,
                    'avatar' => $u->profile?->avatar,
                    // Null in a direct chat, where nobody is anybody's admin.
                    'role' => $group ? ($isOwner ? 'owner' : ($roles[$u->id] ?? 'member')) : null,
                    'presence' => $u->presenceFor($me),
                    'can_remove' => (bool) $group && ! $isOwner && ($isMe || $iManage),
                ];
            });

        return response()->json(['data' => $members]);
    }

    /**
     * Take somebody out of a group chat.
     *
     * Which means taking them out of the group. A chat attached to a group is
     * that group talking — its membership is the group's membership — and a
     * removal that only emptied the chat would leave the person still on the
     * team, still holding its tasks and its calendar, and back in the room
     * the moment anybody opened it, because forGroup() re-syncs from the
     * group. So this is not a second kind of membership: it is exactly
     * GroupController's removal, reachable from where the person is looking.
     *
     * Said out loud in the room afterwards. A group that quietly loses a
     * member is a group where nobody knows who did it.
     */
    public function removeMember(Request $request, Conversation $conversation, string $userUuid): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $group = $conversation->group;
        abort_if(! $group, 422, 'This conversation has no group to remove anybody from.');

        $member = $group->members()->where('uuid', $userUuid)->firstOrFail();

        $leavingSelf = $member->id === $me->id;
        abort_unless($leavingSelf || $group->canManage($me), 403);
        abort_if($member->id === $group->owner_id, 422, 'The owner cannot be removed. Delete the group instead.');

        $group->members()->detach($member->id);
        $conversation->members()->detach($member->id);

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => $me->id,
            'type' => 'text',
            'body' => $leavingSelf
                ? '👋 ' . $me->name . ' left the group.'
                : '👋 ' . $me->name . ' removed ' . $member->name . ' from the group.',
        ]);

        if (! $leavingSelf) {
            $member->notify(new \App\Notifications\SocialNotification(
                'group_removed',
                "{$me->name} removed you from the group “{$group->name}”.",
                ['group_uuid' => $group->uuid],
                '/groups',
            ));
        }

        return response()->json([
            'message' => $leavingSelf ? 'You left the group.' : $member->name . ' was removed.',
        ]);
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

        // "Last seen" has its own setting. It used to be answered with the
        // online-status one, so the Settings toggle for it did nothing at all.
        // Both also honour 'connections', which was previously ignored — only
        // 'nobody' had any effect.
        /*
         * Both halves of the question, and reciprocally.
         *
         * Whoever hides their own is not shown anybody else's — a setting
         * that takes without giving is an advantage, not a privacy setting.
         * The rule lives on the model so this screen and every other one
         * answer the same way.
         */
        $visibleTo = fn (string $key): bool => ! $other || $other->presenceVisibleTo($me, $key);

        $onlineVisible = $visibleTo('online_status_visibility');
        $lastSeenVisible = $visibleTo('last_seen_visibility');

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
                'avatar' => $other->profile?->avatar,
                'last_seen_visible' => $lastSeenVisible,
                'online_visible' => $onlineVisible,
                /*
                 * Where they are: 'online', 'away', 'offline', or null when
                 * they have chosen not to say.
                 *
                 * This list has always carried whether the dot may be shown
                 * and never what the dot should say, so the chat list showed
                 * no presence at all — the permission was computed and then
                 * had nothing to permit. presenceFor() answers both halves.
                 */
                'presence' => $onlineVisible ? $other->presenceState() : null,
                /*
                 * When they were last actually here.
                 *
                 * `last_seen_visible` has been computed on this line for as
                 * long as there has been a setting for it, and there has
                 * never been a value beside it — so "Who can see my last
                 * seen" in Settings governed something the app did not show
                 * anybody. This is that something.
                 *
                 * Null covers both refusals: they have hidden it, or they
                 * have never opened the app. Either way the line is left off
                 * rather than filled with a guess.
                 */
                'last_seen_at' => $lastSeenVisible ? $other->last_active_at : null,
            ] : null,
            'members_count' => $conversation->members_count ?? $conversation->members->count(),
            'unread_count' => $unread,
            'is_muted' => $myPivot?->muted_at !== null,
            'is_archived' => $myPivot?->archived_at !== null,
            'is_pinned' => $myPivot?->pinned_at !== null,
            // Mine, not the room's - the other side reads their own colour.
            'theme' => $myPivot?->theme,
            // Null unless somebody in the room set a span; the chat
            // header reads it to say what is happening to these words.
            'auto_delete_hours' => $conversation->auto_delete_hours,
            'last_message_at' => $conversation->last_message_at,
        ];
    }
}
