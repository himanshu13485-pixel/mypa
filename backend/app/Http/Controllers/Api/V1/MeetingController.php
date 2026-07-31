<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MeetingSignal;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Meet-style link meetings: create a meeting, share its code/link, anyone
 * signed in with the code can join. Media runs over the same WebRTC mesh
 * as calls; this controller handles rooms, membership, and signalling relay.
 */
class MeetingController extends Controller
{
    /** My meetings: hosted or attended, active/upcoming first. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $meetings = Meeting::with(['host:id,uuid,name', 'participants:id,uuid,name'])
            ->withCount(['participants as joined_count' => fn ($q) => $q->where('meeting_participants.status', 'joined')])
            ->where(fn ($q) => $q->where('host_id', $me->id)
                ->orWhereHas('participants', fn ($p) => $p->where('users.id', $me->id)))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'scheduled' THEN 1 ELSE 2 END")
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($m) => $this->serialize($m, $request));

        return response()->json(['data' => $meetings]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'in:audio,video'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $meeting = Meeting::create([
            'host_id' => $request->user()->id,
            'code' => Meeting::generateCode(),
            'title' => $data['title'] ?? null,
            'type' => $data['type'] ?? 'video',
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);

        return response()->json([
            'message' => 'Meeting created — share the code or link to invite people.',
            'data' => $this->serialize($meeting->load('host:id,uuid,name'), $request),
        ], 201);
    }

    /** Look up a meeting by its code (the "open the link" step). */
    public function show(Request $request, Meeting $meeting): JsonResponse
    {
        return response()->json(['data' => $this->serialize($meeting->load('host:id,uuid,name'), $request)]);
    }

    /** Join: anyone signed in with the code. Returns peers already in the room. */
    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_if($meeting->status === 'ended', 410, 'This meeting has ended.');

        if ($meeting->status !== 'active') {
            $meeting->update(['status' => 'active', 'started_at' => $meeting->started_at ?? now()]);
        }

        $joined = $meeting->participants()
            ->wherePivot('status', 'joined')
            ->where('users.id', '!=', $me->id)
            ->get();

        $meeting->participants()->syncWithoutDetaching([
            $me->id => ['status' => 'joined', 'joined_at' => now(), 'left_at' => null],
        ]);

        // Tell everyone already inside that a participant arrived (for the roster).
        foreach ($joined as $peer) {
            broadcast(new MeetingSignal($meeting, $me->uuid, $me->name, $peer->uuid, 'join'));
        }

        return response()->json([
            'message' => 'Joined.',
            'data' => $this->serialize($meeting->fresh()->load('host:id,uuid,name'), $request) + [
                'joined_peers' => $joined->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->name])->values(),
            ],
        ]);
    }

    public function leave(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        $meeting->participants()->syncWithoutDetaching([
            $me->id => ['status' => 'left', 'left_at' => now()],
        ]);

        $remaining = $meeting->participants()->wherePivot('status', 'joined')->where('users.id', '!=', $me->id)->get();
        foreach ($remaining as $peer) {
            broadcast(new MeetingSignal($meeting, $me->uuid, $me->name, $peer->uuid, 'leave'));
        }

        // Room empties out -> meeting ends by itself.
        if ($remaining->isEmpty() && $meeting->status === 'active') {
            $meeting->update(['status' => 'ended', 'ended_at' => now()]);
        }

        return response()->json(['message' => 'Left the meeting.']);
    }

    /** Host ends the meeting for everyone. */
    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless($meeting->host_id === $me->id, 403, 'Only the host can end the meeting for everyone.');

        $meeting->update(['status' => 'ended', 'ended_at' => now()]);

        $joined = $meeting->participants()->wherePivot('status', 'joined')->where('users.id', '!=', $me->id)->get();
        foreach ($joined as $peer) {
            broadcast(new MeetingSignal($meeting, $me->uuid, $me->name, $peer->uuid, 'end'));
        }
        $meeting->participants()->newPivotStatement()
            ->where('meeting_id', $meeting->id)->where('status', 'joined')
            ->update(['status' => 'left', 'left_at' => now()]);

        return response()->json(['message' => 'Meeting ended for everyone.']);
    }

    /** Relay WebRTC signalling to one specific participant. */
    public function signal(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless($meeting->status === 'active', 409, 'Meeting is not active.');
        abort_unless(
            $meeting->participants()->where('users.id', $me->id)->wherePivot('status', 'joined')->exists(),
            403,
            'Join the meeting first.'
        );

        $data = $request->validate([
            'signal' => ['required', 'in:offer,answer,ice'],
            'payload' => ['required', 'array'],
            'to_uuid' => ['required', 'uuid'],
        ]);

        $target = $meeting->participants()
            ->where('users.uuid', $data['to_uuid'])
            ->wherePivot('status', 'joined')
            ->first();
        abort_unless($target && $target->id !== $me->id, 422, 'That participant is not in the meeting.');

        broadcast(new MeetingSignal($meeting, $me->uuid, $me->name, $target->uuid, $data['signal'], $data['payload']));

        return response()->json(['message' => 'ok']);
    }

    protected function serialize(Meeting $meeting, Request $request): array
    {
        return [
            'uuid' => $meeting->uuid,
            'code' => $meeting->code,
            'title' => $meeting->title,
            'type' => $meeting->type,
            'status' => $meeting->status,
            'scheduled_at' => $meeting->scheduled_at?->toIso8601String(),
            'started_at' => $meeting->started_at?->toIso8601String(),
            'host' => ['uuid' => $meeting->host->uuid, 'name' => $meeting->host->name],
            'is_host' => $meeting->host_id === $request->user()->id,
            'joined_count' => $meeting->joined_count ?? null,
            'ended_at' => $meeting->ended_at?->toIso8601String(),
            'duration_seconds' => $meeting->started_at && $meeting->ended_at
                ? max(0, (int) $meeting->started_at->diffInSeconds($meeting->ended_at))
                : null,
            'participants' => $meeting->relationLoaded('participants')
                ? $meeting->participants->map(fn ($p) => $p->name)->unique()->values()
                : [],
            'created_at' => $meeting->created_at->toIso8601String(),
        ];
    }
}
