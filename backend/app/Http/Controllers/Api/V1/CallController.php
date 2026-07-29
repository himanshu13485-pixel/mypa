<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CallSignal;
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

    /** Start a call in a direct conversation; rings the other member. */
    public function initiate(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);
        abort_unless($conversation->type === 'direct', 422, 'Group calls are not available yet.');

        $data = $request->validate(['type' => ['required', 'in:audio,video']]);

        $callee = $conversation->otherMember($me);
        abort_unless($callee, 422, 'No one to call in this conversation.');

        // Privacy: who can call me
        $pref = $callee->settings?->privacyValue('who_can_call') ?? 'connections';
        if ($pref === 'nobody') {
            return response()->json(['message' => 'This user is not accepting calls.'], 403);
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
        $call->participants()->attach([
            $me->id => ['status' => 'joined', 'joined_at' => now()],
            $callee->id => ['status' => 'invited', 'joined_at' => null],
        ]);

        broadcast(new CallSignal($call->load(['conversation', 'caller']), $me->uuid, $callee->uuid, 'ring'));

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

        abort_unless($call->status === 'ringing', 409, 'This call is no longer ringing.');

        if ($data['action'] === 'accept') {
            $call->update(['status' => 'ongoing', 'answered_at' => now()]);
            $call->participants()->updateExistingPivot($me->id, ['status' => 'joined', 'joined_at' => now()]);
        } else {
            $call->update(['status' => 'declined', 'ended_at' => now()]);
            $call->participants()->updateExistingPivot($me->id, ['status' => 'declined']);
        }

        broadcast(new CallSignal(
            $call->load(['conversation', 'caller']),
            $me->uuid,
            $call->caller->uuid,
            $data['action'] === 'accept' ? 'accept' : 'decline',
        ));

        return response()->json([
            'message' => $data['action'] === 'accept' ? 'Call accepted.' : 'Call declined.',
            'data' => $this->serialize($call->fresh(), $request),
        ]);
    }

    /** Either side hangs up (or the caller cancels while ringing). */
    public function end(Request $request, Call $call): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($call, $me->id);

        if (in_array($call->status, ['ringing', 'ongoing'])) {
            $call->update([
                'status' => $call->status === 'ringing' ? 'missed' : 'ended',
                'ended_at' => now(),
            ]);
        }
        $call->participants()->updateExistingPivot($me->id, ['status' => 'left', 'left_at' => now()]);

        $other = $call->participants()->where('users.id', '!=', $me->id)->first();
        if ($other) {
            broadcast(new CallSignal($call->load(['conversation', 'caller']), $me->uuid, $other->uuid, 'end'));
        }

        return response()->json(['message' => 'Call ended.', 'data' => $this->serialize($call->fresh(), $request)]);
    }

    /** Relay a WebRTC signalling payload (offer / answer / ICE candidate). */
    public function signal(Request $request, Call $call): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($call, $me->id);

        $data = $request->validate([
            'signal' => ['required', 'in:offer,answer,ice'],
            'payload' => ['required', 'array'],
        ]);

        abort_unless(in_array($call->status, ['ringing', 'ongoing']), 409, 'Call is not active.');

        $other = $call->participants()->where('users.id', '!=', $me->id)->first();
        abort_unless($other, 422);

        broadcast(new CallSignal(
            $call->load(['conversation', 'caller']),
            $me->uuid,
            $other->uuid,
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

        return [
            'uuid' => $call->uuid,
            'conversation_uuid' => $call->conversation?->uuid ?? $call->conversation()->first()?->uuid,
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

    protected function authorizeParticipant(Call $call, int $userId): void
    {
        abort_unless(
            $call->participants()->where('users.id', $userId)->exists(),
            403,
            'You are not part of this call.'
        );
    }
}
