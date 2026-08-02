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
            $iceServers[] = array_filter([
                'urls' => $turn,
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
            // Privacy: who can call me (direct calls only — group members opted in by joining).
            $pref = $callees->first()->settings?->privacyValue('who_can_call') ?? 'connections';
            if ($pref === 'nobody') {
                return response()->json(['message' => 'This user is not accepting calls.'], 403);
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
            broadcast(new CallSignal($loaded, $me->uuid, $callee->uuid, 'ring'));
        }

        return response()->json([
            'message' => 'Calling…',
            'data' => $this->serialize($call, $request),
        ], 201);
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

            // Everyone already in the call learns about the newcomer; the
            // newcomer gets the list of joined peers to send offers to.
            $joined = $call->participants()
                ->wherePivot('status', 'joined')
                ->where('users.id', '!=', $me->id)
                ->get(['users.id', 'users.uuid', 'users.name']);

            $loaded = $call->load(['conversation', 'caller']);
            foreach ($joined as $peer) {
                broadcast(new CallSignal($loaded, $me->uuid, $peer->uuid, 'accept', [
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
        if (! $isGroup && $call->status === 'ringing') {
            $call->update(['status' => 'declined', 'ended_at' => now()]);
            $this->logCallToChat($call->fresh(['conversation']));
        }
        broadcast(new CallSignal(
            $call->load(['conversation', 'caller']),
            $me->uuid,
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
                broadcast(new CallSignal($loaded, $me->uuid, $peer->uuid, 'peer-left', [
                    'left_uuid' => $me->uuid,
                ]));
            }

            return response()->json(['message' => 'You left the call.', 'data' => $this->serialize($call->fresh(), $request)]);
        }

        if (in_array($call->status, ['ringing', 'ongoing'])) {
            $call->update([
                'status' => $call->status === 'ringing' ? 'missed' : 'ended',
                'ended_at' => now(),
            ]);
            $this->logCallToChat($call->fresh(['conversation']));
        }

        foreach ($remaining as $peer) {
            broadcast(new CallSignal($loaded, $me->uuid, $peer->uuid, 'end'));
        }
        if ($remaining->isEmpty() && ! $isGroup) {
            $other = $call->participants()->where('users.id', '!=', $me->id)->first();
            if ($other) {
                broadcast(new CallSignal($loaded, $me->uuid, $other->uuid, 'end'));
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

        $target = app(\App\Services\AppIdService::class)->findVisibleUser($data['identifier'], $me);
        if (! $target || $target->id === $me->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $pivot = $call->participants()->where('users.id', $target->id)->first();
        if ($pivot && $pivot->pivot->status === 'joined') {
            return response()->json(['message' => "{$target->name} is already in the call."], 409);
        }

        // Privacy: who can call me.
        $pref = $target->settings?->privacyValue('who_can_call') ?? 'connections';
        if ($pref === 'nobody') {
            return response()->json(['message' => 'This user is not accepting calls.'], 403);
        }

        if ($pivot) {
            $call->participants()->updateExistingPivot($target->id, ['status' => 'invited']);
        } else {
            $call->participants()->attach([$target->id => ['status' => 'invited', 'joined_at' => null]]);
        }

        broadcast(new CallSignal($call->load(['conversation', 'caller']), $me->uuid, $target->uuid, 'ring'));

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

        broadcast(new CallSignal(
            $call->load(['conversation', 'caller']),
            $me->uuid,
            $target->uuid,
            $data['signal'],
            $data['payload'],
        ));

        return response()->json(['message' => 'Signal relayed.']);
    }

    public function history(Request $request): JsonResponse
    {
        $me = $request->user();

        $calls = Call::whereHas('participants', fn ($p) => $p->where('users.id', $me->id))
            ->with(['caller:id,uuid,name', 'participants:id,uuid,name', 'conversation:id,uuid'])
            ->latest('started_at')
            ->paginate(20);

        $calls->getCollection()->transform(fn ($c) => $this->serialize($c, $request));

        return response()->json($calls);
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
            'conversation_uuid' => $conversation?->uuid,
            'is_group' => $conversation ? $conversation->type !== 'direct' : false,
            'group_name' => $conversation && $conversation->type !== 'direct' ? $conversation->name : null,
            'type' => $call->type,
            'status' => $call->status,
            'is_outgoing' => $call->caller_id === $me->id,
            'is_missed' => $call->status === 'missed' && $call->caller_id !== $me->id,
            'other_user' => $other ? ['uuid' => $other->uuid, 'name' => $other->name] : null,
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
