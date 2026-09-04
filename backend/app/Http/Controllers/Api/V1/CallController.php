<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CallSignal;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends Controller
{
    /** ICE server configuration for the frontend (STUN/TURN from env only). */
    public function config(): JsonResponse
    {
        $iceServers = [];

        if ($stun = config('mypa.webrtc.stun_url')) {
            $iceServers[] = ['urls' => $stun];
        } else {
            // Public Google STUN as a dev default; TURN must come from env.
            $iceServers[] = ['urls' => 'stun:stun.l.google.com:19302'];
        }

        if ($turn = config('mypa.webrtc.turn_url')) {
            // Comma-separated URLs are allowed so one relay can be offered over
            // UDP, TCP and TLS - restrictive networks often permit only 443.
            $urls = array_values(array_filter(array_map('trim', explode(',', $turn))));

            $iceServers[] = array_filter([
                'urls' => count($urls) === 1 ? $urls[0] : $urls,
                'username' => config('mypa.webrtc.turn_username'),
                'credential' => config('mypa.webrtc.turn_credential'),
            ]);
        }

        return response()->json(['data' => ['iceServers' => $iceServers]]);
    }

    /**
     * Start a call. Direct conversations ring the other member; group
     * conversations ring every member (mesh — each joiner connects
     * peer-to-peer with everyone already in).
     */
    public function initiate(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $data = $request->validate(['type' => ['required', 'in:audio,video']]);

        $callees = $conversation->type === 'direct'
            ? collect([$conversation->otherMember($me)])->filter()
            : $conversation->members()->where('users.id', '!=', $me->id)->get();
        abort_unless($callees->isNotEmpty(), 422, 'No one to call in this conversation.');

        if ($conversation->type === 'direct') {
            if ($block = $conversation->blockBetween($me)) {
                return response()->json([
                    'message' => $block === 'mine'
                        ? 'You have blocked this person. Unblock them to call.'
                        : 'This call could not be connected.',
                ], 403);
            }

            // Privacy: who can call me (direct calls only — group members opted
            // in by joining). 'connections' was previously ignored, so the
            // setting only ever worked as an on/off switch.
            $target = $callees->first();
            $pref = $target->settings?->privacyValue('who_can_call') ?? 'connections';
            if ($pref === 'nobody') {
                return response()->json(['message' => 'This user is not accepting calls.'], 403);
            }
            if ($pref === 'connections' && ! app(\App\Services\AppIdService::class)->areConnected($me, $target)) {
                return response()->json([
                    'message' => 'You can only call your connections. Send a connection request first.',
                ], 403);
            }
        }

        // Refuse if there is already an active call in this conversation.
        $active = $conversation->calls()->whereIn('status', ['ringing', 'ongoing'])->first();
        if ($active) {
            return response()->json(['message' => 'A call is already in progress.', 'data' => ['uuid' => $active->uuid]], 409);
        }

        $call = $conversation->calls()->create([
            'caller_id' => $me->id,
            'type' => $data['type'],
            'status' => 'ringing',
            'started_at' => now(),
        ]);
        $call->participants()->attach(
            [$me->id => ['status' => 'joined', 'joined_at' => now()]]
            + $callees->mapWithKeys(fn ($u) => [$u->id => ['status' => 'invited', 'joined_at' => null]])->all()
        );

        $loaded = $call->load(['conversation', 'caller']);
        foreach ($callees as $callee) {
            \App\Support\Realtime::send(new CallSignal($loaded, $me->uuid, $me->name, $callee->uuid, 'ring'));
        }
        $this->ring($loaded, $me, $callees, $conversation->type !== 'direct', $conversation->group?->name);

        return response()->json([
            'message' => 'Calling…',
            'data' => $this->serialize($call, $request),
        ], 201);
    }

    /**
     * Decline pressed on the Android notification.
     *
     * The 'signed' middleware has already proven this URL came from us,
     * unaltered and unexpired; the callee's uuid rides in the signature, which
     * is what stands in for a login here — the button lives in native code
     * that has no token to offer. Everything is tolerant of staleness: a
     * notification can be pressed seconds after the caller hung up, and that
     * deserves a shrug, not an error page on somebody's phone.
     */
    public function declineFromPush(Request $request, Call $call): JsonResponse
    {
        $me = \App\Models\User::where('uuid', (string) $request->query('user'))->first();
        if (! $me || ! $call->participants()->where('users.id', $me->id)->exists()) {
            return response()->json(['message' => 'ok']);
        }

        if ($call->status !== 'ringing') {
            // Already answered, already dead, or a group call in progress —
            // nothing here for a decline to do.
            return response()->json(['message' => 'ok']);
        }

        $isGroup = $call->conversation->type !== 'direct';
        $call->participants()->updateExistingPivot($me->id, ['status' => 'declined']);

        /*
         * The phone that was declined on clears its own notification in
         * DeclineReceiver. This is for the person's other devices, which
         * were rung too and have heard nothing about it.
         */
        $this->stopRinging($call->load(['conversation', 'caller']), collect([$me]), 'handled');

        if (! $isGroup) {
            $call->update(['status' => 'declined', 'ended_at' => now()]);
            $this->logCallToChat($call->fresh(['conversation']));
        }
        $loaded = $call->load(['conversation', 'caller']);
        \App\Support\Realtime::send(new CallSignal(
            $loaded,
            $me->uuid,
            $me->name,
            $call->caller->uuid,
            'decline',
            ['decliner_uuid' => $me->uuid, 'is_group' => $isGroup],
        ));

        /*
         * And to the decliner themselves.
         *
         * This decline came from the Android notification — native code,
         * outside the webview — so the app may still be sitting there ringing
         * behind a notification that has just vanished. Nothing else would
         * ever tell it: the ordinary decline is pressed inside the app, which
         * therefore already knows. That is the "notification disappears but it
         * still rings".
         */
        \App\Support\Realtime::send(new CallSignal(
            $loaded,
            $me->uuid,
            $me->name,
            $me->uuid,
            'decline',
            ['decliner_uuid' => $me->uuid, 'is_group' => $isGroup],
        ));

        return response()->json(['message' => 'Call declined.']);
    }

    /** Callee accepts or declines a ringing call. */
    public function respond(Request $request, Call $call): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($call, $me->id);

        $data = $request->validate(['action' => ['required', 'in:accept,decline']]);

        $isGroup = $call->conversation->type !== 'direct';

        // A call can be joined while ringing, or while ongoing (late group
        // joiners and people invited into a live call).
        abort_unless(
            in_array($call->status, ['ringing', 'ongoing']),
            409,
            'This call is no longer active.'
        );

        if ($data['action'] === 'accept') {
            if ($call->status === 'ringing') {
                $call->update(['status' => 'ongoing', 'answered_at' => $call->answered_at ?? now()]);
            }
            $call->participants()->updateExistingPivot($me->id, ['status' => 'joined', 'joined_at' => now()]);

            /*
             * Answering on the phone leaves the laptop ringing.
             *
             * The ring went to every device this person has and only the one
             * they picked up knows about it. 'handled' rather than 'missed':
             * they did not miss this call, they are on it, so nothing should
             * be left behind claiming otherwise.
             */
            $this->stopRinging($call->load(['conversation', 'caller']), collect([$me]), 'handled');

            // Everyone already in the call learns about the newcomer; the
            // newcomer gets the list of joined peers to send offers to.
            $joined = $call->participants()
                ->wherePivot('status', 'joined')
                ->where('users.id', '!=', $me->id)
                ->get(['users.id', 'users.uuid', 'users.name']);

            $loaded = $call->load(['conversation', 'caller']);
            foreach ($joined as $peer) {
                \App\Support\Realtime::send(new CallSignal($loaded, $me->uuid, $me->name, $peer->uuid, 'accept', [
                    'joiner_uuid' => $me->uuid,
                    'joiner_name' => $me->name,
                ]));
            }

            return response()->json([
                'message' => 'Call accepted.',
                'data' => $this->serialize($call->fresh(), $request) + [
                    'joined_peers' => $joined->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->name])->values(),
                ],
            ]);
        }

        // Decline: a ringing direct call dies; a live call keeps going.
        $call->participants()->updateExistingPivot($me->id, ['status' => 'declined']);

        // Declining on one device silences this person's others.
        $this->stopRinging($call->load(['conversation', 'caller']), collect([$me]), 'handled');
        if (! $isGroup && $call->status === 'ringing') {
            $call->update(['status' => 'declined', 'ended_at' => now()]);
            $this->logCallToChat($call->fresh(['conversation']));
        }
        \App\Support\Realtime::send(new CallSignal(
            $call->load(['conversation', 'caller']),
            $me->uuid,
            $me->name,
            $call->caller->uuid,
            'decline',
            ['decliner_uuid' => $me->uuid, 'is_group' => $isGroup],
        ));

        return response()->json([
            'message' => 'Call declined.',
            'data' => $this->serialize($call->fresh(), $request),
        ]);
    }

    /** Either side hangs up (or the caller cancels while ringing). */
    public function end(Request $request, Call $call): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($call, $me->id);

        $isGroup = $call->conversation->type !== 'direct';
        $call->participants()->updateExistingPivot($me->id, ['status' => 'left', 'left_at' => now()]);

        $remaining = $call->participants()
            ->wherePivot('status', 'joined')
            ->where('users.id', '!=', $me->id)
            ->get(['users.id', 'users.uuid']);

        $loaded = $call->load(['conversation', 'caller']);

        // Any call survives while at least two people remain in it.
        if ($remaining->count() >= 2 && $call->status === 'ongoing') {
            foreach ($remaining as $peer) {
                \App\Support\Realtime::send(new CallSignal($loaded, $me->uuid, $me->name, $peer->uuid, 'peer-left', [
                    'left_uuid' => $me->uuid,
                ]));
            }

            return response()->json(['message' => 'You left the call.', 'data' => $this->serialize($call->fresh(), $request)]);
        }

        if (in_array($call->status, ['ringing', 'ongoing'])) {
            /*
             * Read before the status changes: stillRinging() asks who is
             * 'invited', which is exactly the set whose phones are making a
             * noise at this moment.
             */
            $ringing = $this->stillRinging($call, $me->id);

            $call->update([
                'status' => $call->status === 'ringing' ? 'missed' : 'ended',
                'ended_at' => now(),
            ]);
            $this->logCallToChat($call->fresh(['conversation']));

            $this->stopRinging($loaded, $ringing, 'missed');
        }

        foreach ($remaining as $peer) {
            \App\Support\Realtime::send(new CallSignal($loaded, $me->uuid, $me->name, $peer->uuid, 'end'));
        }
        if ($remaining->isEmpty() && ! $isGroup) {
            $other = $call->participants()->where('users.id', '!=', $me->id)->first();
            if ($other) {
                \App\Support\Realtime::send(new CallSignal($loaded, $me->uuid, $me->name, $other->uuid, 'end'));
            }
        }

        return response()->json(['message' => 'Call ended.', 'data' => $this->serialize($call->fresh(), $request)]);
    }

    /**
     * Pull one more person into a ringing/ongoing call. The target is found by
     * username / email / App ID (mobile stays unsearchable) and simply starts
     * ringing; on accept they mesh with everyone already in.
     */
    public function invite(Request $request, Call $call): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($call, $me->id);
        abort_unless(in_array($call->status, ['ringing', 'ongoing']), 409, 'Call is not active.');

        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']]);

        $appIds = app(\App\Services\AppIdService::class);
        $target = $appIds->findVisibleUser($data['identifier'], $me);
        if (! $target || $target->id === $me->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $pivot = $call->participants()->where('users.id', $target->id)->first();
        if ($pivot && $pivot->pivot->status === 'joined') {
            return response()->json(['message' => "{$target->name} is already in the call."], 409);
        }

        // Privacy: who can call me. Pulling someone into a live call is still
        // calling them, so this matches the check in initiate() — 'connections'
        // used to be ignored here too.
        $pref = $target->settings?->privacyValue('who_can_call') ?? 'connections';
        if ($pref === 'nobody') {
            return response()->json(['message' => 'This user is not accepting calls.'], 403);
        }
        if ($pref === 'connections' && ! $appIds->areConnected($me, $target)) {
            return response()->json([
                'message' => "You can only add your connections to a call. Send {$target->name} a connection request first.",
            ], 403);
        }

        if ($pivot) {
            $call->participants()->updateExistingPivot($target->id, ['status' => 'invited']);
        } else {
            $call->participants()->attach([$target->id => ['status' => 'invited', 'joined_at' => null]]);
        }

        $loaded = $call->load(['conversation', 'caller']);
        \App\Support\Realtime::send(new CallSignal($loaded, $me->uuid, $me->name, $target->uuid, 'ring'));
        $this->ring($loaded, $me, collect([$target]), (bool) $call->conversation->group_id, $call->conversation->group?->name);

        return response()->json(['message' => "Ringing {$target->name}…"]);
    }

    /** Relay a WebRTC signalling payload (offer / answer / ICE candidate). */
    public function signal(Request $request, Call $call): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($call, $me->id);

        $data = $request->validate([
            'signal' => ['required', 'in:offer,answer,ice,share,record,media,rec-request,rec-allow,rec-deny'],
            'payload' => ['sometimes', 'array'],
            'to_uuid' => ['sometimes', 'uuid'],
        ]);

        abort_unless(in_array($call->status, ['ringing', 'ongoing']), 409, 'Call is not active.');

        // Mesh calls address a specific peer; 1:1 falls back to "the other side".
        $target = isset($data['to_uuid'])
            ? $call->participants()->where('users.uuid', $data['to_uuid'])->first()
            : $call->participants()->where('users.id', '!=', $me->id)->first();
        abort_unless($target && $target->id !== $me->id, 422, 'Unknown signalling target.');

        \App\Support\Realtime::send(new CallSignal(
            $call->load(['conversation', 'caller']),
            $me->uuid,
            $me->name,
            $target->uuid,
            $data['signal'],
            $data['payload'],
        ));

        return response()->json(['message' => 'Signal relayed.']);
    }

    /**
     * Presence ping for a live call.
     *
     * Without it, a browser that closes or loses the network stays "joined"
     * forever: nobody else's tile for that person ever goes away, and the call
     * never ends on its own. mypa:reap-meetings sweeps anyone who stops
     * calling this. Doubles as the poll that tells a client the call ended.
     */
    public function heartbeat(Request $request, Call $call): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($call, $me->id);

        if (! $call->isActive()) {
            return response()->json(['data' => ['status' => $call->status, 'participants' => []]]);
        }

        $call->participants()->updateExistingPivot($me->id, ['last_seen_at' => now()]);

        return response()->json(['data' => [
            'status' => $call->status,
            'participants' => $call->inCall()->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->name,
                'avatar' => $u->profile?->avatar,
            ])->values(),
        ]]);
    }

    /**
     * Ring a phone that is not looking at the app.
     *
     * Sent alongside the websocket signal rather than instead of it: an open
     * tab answers on the socket in milliseconds, and the push is what reaches
     * everyone else. Delivery failures are swallowed by the channel — a call
     * must not fail to start because a browser vendor's push service is slow.
     */
    /**
     * Tell devices that are still ringing to stop.
     *
     * The ring went out by push because the app may be closed, and a closed
     * app hears nothing on the websocket — so the 'end' signal sent alongside
     * this reaches an open tab and nobody else. Without this, the notification
     * stayed: on Android looping its ringtone under FLAG_INSISTENT until the
     * 45-second timeout, on the web sitting there under requireInteraction
     * until somebody clicked it, however long that took.
     *
     * Best-effort by construction. Ending a call must not fail because
     * somebody's push endpoint has gone stale, so every failure is logged and
     * swallowed exactly like the ring's.
     */
    protected function stopRinging(Call $call, \Illuminate\Support\Collection $users, string $reason): void
    {
        foreach ($users as $user) {
            if (! $user) {
                continue;
            }
            try {
                $user->notify(new \App\Notifications\CallOverNotification($call, $reason));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[call] cancel push failed', ['reason' => $e->getMessage()]);
            }
        }
    }

    /**
     * Everyone whose devices are still ringing for this call.
     *
     * 'invited' is the pivot state between being rung and doing something
     * about it, so it is exactly the set holding a live notification. The
     * person who caused the call to end is excluded — theirs is already gone.
     */
    protected function stillRinging(Call $call, ?int $exceptUserId = null): \Illuminate\Support\Collection
    {
        return $call->participants()
            ->wherePivot('status', 'invited')
            ->when($exceptUserId, fn ($q) => $q->where('users.id', '!=', $exceptUserId))
            ->get();
    }

    protected function ring(Call $call, \App\Models\User $from, \Illuminate\Support\Collection $to, bool $isGroup, ?string $groupName): void
    {
        foreach ($to as $callee) {
            try {
                $callee->notify(new \App\Notifications\IncomingCallNotification($call, $from->name, $isGroup, $groupName));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[call] ring push failed', ['reason' => $e->getMessage()]);
            }
        }
    }

    /**
     * Am I being rung right now?
     *
     * A ring is one fire-and-forget websocket event. If the browser's socket
     * happened to be down at that instant — a laptop that had slept, a tab
     * open since yesterday whose connection dropped, a wifi blip — the event
     * is gone: Reverb does not replay, and nothing here ever asked again. The
     * phone still rang, because push is a separate path that Google queues and
     * retries, which is exactly the reported symptom: their phone rings and the
     * desktop sits there.
     *
     * So the client asks. On load, when the tab is looked at again, and when
     * the socket reconnects — the three moments when it might have missed
     * something.
     *
     * Bounded by the same 45 seconds a ring push is given to live. A 'ringing'
     * call is never reaped (only 'ongoing' ones are, since a ring has no
     * heartbeat yet), so without that bound this would happily surface a call
     * from last Tuesday whose caller closed their tab.
     *
     * Only calls I was invited to and have not joined: my own outgoing calls
     * are 'joined' from the moment they are created.
     */
    public function incoming(Request $request): JsonResponse
    {
        $me = $request->user();

        $call = Call::with(['conversation', 'caller'])
            ->where('status', 'ringing')
            ->where('started_at', '>=', now()->subSeconds(Call::PRESENCE_TIMEOUT_SECONDS))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $me->id)
                ->where('call_participants.status', 'invited'))
            ->latest('started_at')
            ->first();

        if (! $call || ! $call->conversation || ! $call->caller) {
            return response()->json(['data' => null]);
        }

        /*
         * Deliberately the same shape the websocket delivers, down to the
         * 'signal' key, so the client hands it to the very same handler. Two
         * shapes for one thing is how the two drift apart and the recovery
         * path quietly stops matching the path it is meant to recover.
         */
        return response()->json(['data' => [
            'call_uuid' => $call->uuid,
            'conversation_uuid' => $call->conversation->uuid,
            'call_type' => $call->type,
            'from_uuid' => $call->caller->uuid,
            'from_name' => $call->caller->name,
            'signal' => 'ring',
            'payload' => [],
        ]]);
    }

    /**
     * One history, two kinds of call.
     *
     * A Netvork call is between two accounts and the app knows everything
     * about it. A phone call left on somebody's own SIM and the app knows
     * only that it was dialled, plus whatever the caller said afterwards.
     * Both belong in the same list — a person's day contains both, and
     * remembering which screen a call is filed under is not their job.
     *
     * Every row says which it was, because the difference matters when you
     * read it: a Netvork call's four minutes were counted by the server, and
     * a phone call's four minutes were typed in by the person who made it.
     *
     * Merged in PHP rather than in SQL. A union across two tables with
     * different shapes, then paginated, is a query nobody will be able to
     * change safely later — and the page size here is twenty rows.
     */
    public function history(Request $request): JsonResponse
    {
        $me = $request->user();

        $channel = $request->query('channel');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $rows = collect();

        if ($channel !== 'phone') {
            $rows = $rows->concat($this->netvorkHistory($request, $me));
        }

        if ($channel !== 'netvork') {
            $rows = $rows->concat($this->phoneHistory($me));
        }

        $sorted = $rows->sortByDesc('sort_at')->values();

        $slice = $sorted->forPage($page, $perPage)->values()
            ->map(function (array $row) {
                unset($row['sort_at']);

                return $row;
            });

        return response()->json([
            'data' => $slice,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($sorted->count() / $perPage)),
            'per_page' => $perPage,
            'total' => $sorted->count(),
        ]);
    }

    /** Calls placed on a SIM, which the app only ever half knows about. */
    protected function phoneHistory($me): \Illuminate\Support\Collection
    {
        return \App\Models\PhoneCall::where('user_id', $me->id)
            ->latest('placed_at')
            // Deep enough that the merged page is always full, shallow enough
            // that this never becomes the reason the screen is slow.
            ->limit(200)
            ->get()
            ->map(fn (\App\Models\PhoneCall $call) => $call->serialize() + [
                'sort_at' => $call->placed_at,
                'is_outgoing' => true,
                'started_at' => $call->placed_at,
            ]);
    }

    /** The app's own calls, which it knows the whole of. */
    protected function netvorkHistory(Request $request, $me): \Illuminate\Support\Collection
    {
        return Call::whereHas('participants', fn ($p) => $p->where('users.id', $me->id))
            /*
             * type and name, not the uuid alone.
             *
             * serialize() asks the conversation whether it is a group and what
             * it is called. Selecting only id and uuid left both null, and a
             * null type is not 'direct' — so every call in the history came
             * back marked as a group call. Nothing depended on it, so nothing
             * showed it, until something did.
             */
            ->with(['caller:id,uuid,name', 'participants:id,uuid,name', 'conversation:id,uuid,type,name'])
            ->latest('started_at')
            ->limit(200)
            ->get()
            ->map(fn (Call $c) => $this->serialize($c, $request) + ['sort_at' => $c->started_at]);
    }

    protected function serialize(Call $call, Request $request): array
    {
        $me = $request->user();
        $other = $call->relationLoaded('participants')
            ? $call->participants->firstWhere(fn ($u) => $u->id !== $me->id)
            : $call->participants()->where('users.id', '!=', $me->id)->first();

        $conversation = $call->conversation ?? $call->conversation()->first();

        return [
            'uuid' => $call->uuid,
            /*
             * Which kind of call this was. The phone rows carry 'phone', and
             * a list where only one side is labelled is a list where the
             * unlabelled side means nothing.
             */
            'channel' => 'netvork',
            // Counted by the server, unlike a phone call's.
            'duration_is_reported' => false,
            'conversation_uuid' => $conversation?->uuid,
            'is_group' => $conversation ? $conversation->type !== 'direct' : false,
            'group_name' => $conversation && $conversation->type !== 'direct' ? $conversation->name : null,
            'type' => $call->type,
            'status' => $call->status,
            'is_outgoing' => $call->caller_id === $me->id,
            'is_missed' => $call->status === 'missed' && $call->caller_id !== $me->id,
            'other_user' => $other ? ['uuid' => $other->uuid, 'name' => $other->name] : null,
            // Who is actually in it right now, and can I walk back in? The
            // calls list showed "ongoing" with no count and no way to rejoin,
            // even though respond() has always accepted a late joiner.
            'joined_count' => $call->relationLoaded('participants')
                ? $call->participants->where('pivot.status', 'joined')->count()
                : $call->participants()->wherePivot('status', 'joined')->count(),
            'joined_names' => $call->relationLoaded('participants')
                ? $call->participants->where('pivot.status', 'joined')->pluck('name')->values()
                : $call->participants()->wherePivot('status', 'joined')->pluck('name'),
            'is_active' => $call->isActive(),
            'started_at' => $call->started_at,
            'answered_at' => $call->answered_at,
            'ended_at' => $call->ended_at,
            'duration_seconds' => $call->durationSeconds(),
        ];
    }

    /**
     * Drop a summary line into the conversation so calls (answered, missed,
     * declined) leave a visible record in the chat history.
     */
    protected function logCallToChat(Call $call): void
    {
        $conversation = $call->conversation ?? $call->conversation()->first();
        if (! $conversation) {
            return;
        }

        $icon = $call->type === 'video' ? '📹' : '📞';
        $label = ucfirst($call->type);
        $body = match ($call->status) {
            'ended' => sprintf('%s %s call · %s', $icon, $label, $this->fmtDuration($call->durationSeconds())),
            'missed' => sprintf('%s Missed %s call', $icon, strtolower($label)),
            'declined' => sprintf('%s %s call declined', $icon, $label),
            default => null,
        };
        if (! $body) {
            return;
        }

        $message = $conversation->messages()->create([
            'user_id' => $call->caller_id,
            'type' => 'text',
            'body' => $body,
        ]);
        $conversation->update(['last_message_at' => now()]);

        broadcast(new MessageSent($message->load(['user', 'conversation'])));

        /*
         * A missed call is the only one of these worth a notification.
         *
         * The ring itself is a push, but a ring is deliberately short-lived —
         * it carries a 45-second TTL, because a call announced ten minutes
         * late is worse than one never announced. So a phone that was off,
         * out of signal, or simply not picked up in time was left with a line
         * in a chat nobody had a reason to open. This is the part that
         * survives: somebody tried to reach you, and here is who.
         *
         * Answered and declined calls say nothing. Both mean the person was
         * already there for it.
         */
        if ($call->status !== 'missed') {
            return;
        }

        $caller = $call->caller ?? $call->caller()->first();
        if (! $caller) {
            return;
        }

        $kind = $call->type === 'video' ? 'video call' : 'call';

        foreach ($call->participants()->where('users.id', '!=', $caller->id)->get() as $person) {
            $person->notify(new \App\Notifications\SocialNotification(
                'missed_call',
                "Missed {$kind} from {$caller->name}.",
                ['call_uuid' => $call->uuid, 'conversation_uuid' => $conversation->uuid],
                '/calls',
                // Per call: two people trying you in the same hour are two
                // callbacks you owe, not one.
                'missed-' . $call->uuid,
            ));
        }
    }

    protected function fmtDuration(?int $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    protected function authorizeParticipant(Call $call, int $userId): void
    {
        abort_unless(
            $call->participants()->where('users.id', $userId)->exists(),
            403,
            'You are not part of this call.'
        );
    }
}
