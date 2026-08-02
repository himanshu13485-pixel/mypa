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
            ->where('is_screen', $request->boolean('screen'))
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
            'is_screen' => ['sometimes', 'boolean'],
            'requires_approval' => ['sometimes', 'boolean'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $meeting = Meeting::create([
            'host_id' => $request->user()->id,
            'code' => Meeting::generateCode(),
            'title' => $data['title'] ?? null,
            'type' => $data['type'] ?? 'video',
            'is_screen' => (bool) ($data['is_screen'] ?? false),
            'requires_approval' => (bool) ($data['requires_approval'] ?? true),
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
        $data = $request->validate(['display_name' => ['sometimes', 'nullable', 'string', 'max:50']]);

        // Waiting room: non-hosts must be admitted first (unless bypassed or
        // previously admitted/inside).
        if ($meeting->requires_approval && $meeting->host_id !== $me->id) {
            $pivot = $meeting->participants()->where('users.id', $me->id)->first()?->pivot;
            $everAdmitted = $pivot && in_array($pivot->status, ['joined', 'left', 'admitted'], true);
            if (! $everAdmitted) {
                $meeting->participants()->syncWithoutDetaching([
                    $me->id => [
                        'status' => 'waiting',
                        'display_name' => $data['display_name'] ?? null,
                    ],
                ]);
                broadcast(new MeetingSignal(
                    $meeting,
                    $me->uuid,
                    $data['display_name'] ?? $me->name,
                    $meeting->host->uuid,
                    'knock',
                ));

                return response()->json([
                    'message' => 'Waiting for the host to let you in.',
                    'data' => ['waiting' => true],
                ], 202);
            }
        }

        if ($meeting->status !== 'active') {
            $meeting->update(['status' => 'active', 'started_at' => $meeting->started_at ?? now()]);
        }

        $joined = $meeting->participants()
            ->wherePivot('status', 'joined')
            ->where('users.id', '!=', $me->id)
            ->get();

        $meeting->participants()->syncWithoutDetaching([
            $me->id => [
                'status' => 'joined',
                'joined_at' => now(),
                'left_at' => null,
                'display_name' => $data['display_name'] ?? null,
            ],
        ]);

        // Tell everyone already inside that a participant arrived (for the roster).
        $myName = $data['display_name'] ?? $me->name;
        foreach ($joined as $peer) {
            broadcast(new MeetingSignal($meeting, $me->uuid, $myName, $peer->uuid, 'join'));
        }

        return response()->json([
            'message' => 'Joined.',
            'data' => $this->serialize($meeting->fresh()->load('host:id,uuid,name'), $request) + [
                'joined_peers' => $joined->map(fn ($u) => [
                    'uuid' => $u->uuid,
                    'name' => $u->pivot->display_name ?? $u->name,
                ])->values(),
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

    /** Host lets a waiting person in (or turns them away). */
    public function admit(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->host_id === $request->user()->id, 403, 'Only the host can admit people.');

        $data = $request->validate([
            'user_uuid' => ['required', 'uuid'],
            'allow' => ['required', 'boolean'],
        ]);

        $target = \App\Models\User::where('uuid', $data['user_uuid'])->firstOrFail();
        $meeting->participants()->syncWithoutDetaching([
            $target->id => ['status' => $data['allow'] ? 'admitted' : 'denied'],
        ]);

        broadcast(new MeetingSignal(
            $meeting,
            $request->user()->uuid,
            $request->user()->name,
            $target->uuid,
            $data['allow'] ? 'admitted' : 'denied',
        ));

        return response()->json(['message' => $data['allow'] ? "{$target->name} admitted." : "{$target->name} turned away."]);
    }

    /** Host toggles the waiting room on/off mid-meeting (the bypass). */
    public function setApproval(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->host_id === $request->user()->id, 403, 'Only the host can change this.');
        $data = $request->validate(['requires_approval' => ['required', 'boolean']]);
        $meeting->update(['requires_approval' => $data['requires_approval']]);

        return response()->json([
            'message' => $data['requires_approval']
                ? 'Approval required: new joiners now wait for you.'
                : 'Open access: anyone with the link joins directly.',
        ]);
    }

    /** In-meeting chat: to everyone, or privately to one participant. */
    public function chat(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless($meeting->status === 'active', 409, 'Meeting is not active.');
        $myPivot = $meeting->participants()->where('users.id', $me->id)->wherePivot('status', 'joined')->first()?->pivot;
        abort_unless($myPivot !== null, 403, 'Join the meeting first.');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'to_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $fromName = $myPivot->display_name ?? $me->name;
        $payload = ['message' => $data['message'], 'private' => ! empty($data['to_uuid'])];

        if (! empty($data['to_uuid'])) {
            $target = $meeting->participants()->where('users.uuid', $data['to_uuid'])->wherePivot('status', 'joined')->first();
            abort_unless($target && $target->id !== $me->id, 422, 'That participant is not in the meeting.');
            broadcast(new MeetingSignal($meeting, $me->uuid, $fromName, $target->uuid, 'chat', $payload));
        } else {
            $others = $meeting->participants()->wherePivot('status', 'joined')->where('users.id', '!=', $me->id)->get();
            foreach ($others as $peer) {
                broadcast(new MeetingSignal($meeting, $me->uuid, $fromName, $peer->uuid, 'chat', $payload));
            }
        }

        return response()->json(['message' => 'sent']);
    }

    /**
     * Share a file/image in the meeting chat. The file lives with the meeting
     * (participants-only download) and a chat signal announces it - to
     * everyone or privately.
     */
    public function chatFile(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless($meeting->status === 'active', 409, 'Meeting is not active.');
        $myPivot = $meeting->participants()->where('users.id', $me->id)->wherePivot('status', 'joined')->first()?->pivot;
        abort_unless($myPivot !== null, 403, 'Join the meeting first.');

        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10 MB
            'to_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $upload = $data['file'];
        \App\Support\UploadGuard::assertSafe($upload);
        $path = $upload->store('meeting-files/' . $meeting->id, 'local');
        $file = \App\Models\MeetingFile::create([
            'meeting_id' => $meeting->id,
            'user_id' => $me->id,
            'path' => $path,
            'name' => $upload->getClientOriginalName(),
            'mime' => $upload->getClientMimeType(),
            'size' => $upload->getSize(),
        ]);

        $fromName = $myPivot->display_name ?? $me->name;
        $payload = [
            'message' => '',
            'private' => ! empty($data['to_uuid']),
            'file' => [
                'uuid' => $file->uuid,
                'name' => $file->name,
                'mime' => $file->mime,
                'size' => $file->size,
            ],
        ];

        if (! empty($data['to_uuid'])) {
            $target = $meeting->participants()->where('users.uuid', $data['to_uuid'])->wherePivot('status', 'joined')->first();
            abort_unless($target && $target->id !== $me->id, 422, 'That participant is not in the meeting.');
            broadcast(new MeetingSignal($meeting, $me->uuid, $fromName, $target->uuid, 'chat', $payload));
        } else {
            $others = $meeting->participants()->wherePivot('status', 'joined')->where('users.id', '!=', $me->id)->get();
            foreach ($others as $peer) {
                broadcast(new MeetingSignal($meeting, $me->uuid, $fromName, $peer->uuid, 'chat', $payload));
            }
        }

        return response()->json(['message' => 'Shared.', 'data' => $payload['file']]);
    }

    /** Download a chat file - meeting participants only. */
    public function chatFileDownload(Request $request, Meeting $meeting, \App\Models\MeetingFile $file)
    {
        abort_unless($file->meeting_id === $meeting->id, 404);
        abort_unless(
            $meeting->participants()->where('users.id', $request->user()->id)->exists()
                || $meeting->host_id === $request->user()->id,
            403
        );

        return \Illuminate\Support\Facades\Storage::disk('local')->download($file->path, $file->name);
    }

    /** Broadcast an emoji reaction (or raised hand) to everyone in the room. */
    public function react(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless($meeting->status === 'active', 409, 'Meeting is not active.');
        $myPivot = $meeting->participants()->where('users.id', $me->id)->wherePivot('status', 'joined')->first()?->pivot;
        abort_unless($myPivot !== null, 403, 'Join the meeting first.');

        $data = $request->validate([
            'emoji' => ['required', 'in:thumbsup,clap,heart,laugh,wow,party,hand,hand_down'],
        ]);

        $others = $meeting->participants()->wherePivot('status', 'joined')->where('users.id', '!=', $me->id)->get();
        foreach ($others as $peer) {
            broadcast(new MeetingSignal(
                $meeting,
                $me->uuid,
                $myPivot->display_name ?? $me->name,
                $peer->uuid,
                'react',
                ['emoji' => $data['emoji']],
            ));
        }

        return response()->json(['message' => 'ok']);
    }

    /** Change what I am called in THIS meeting; everyone inside sees it live. */
    public function rename(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless(
            $meeting->participants()->where('users.id', $me->id)->wherePivot('status', 'joined')->exists(),
            403,
            'Join the meeting first.'
        );

        $data = $request->validate(['display_name' => ['required', 'string', 'max:50']]);
        $name = trim($data['display_name']);
        abort_if($name === '', 422, 'Name cannot be empty.');

        $meeting->participants()->updateExistingPivot($me->id, ['display_name' => $name]);

        $others = $meeting->participants()->wherePivot('status', 'joined')->where('users.id', '!=', $me->id)->get();
        foreach ($others as $peer) {
            broadcast(new MeetingSignal($meeting, $me->uuid, $name, $peer->uuid, 'rename', ['name' => $name]));
        }

        return response()->json(['message' => "You will appear as {$name} in this meeting."]);
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
            'signal' => ['required', 'in:offer,answer,ice,share,record,media,rec-request,rec-allow,rec-deny'],
            'payload' => ['sometimes', 'array'],
            'to_uuid' => ['required', 'uuid'],
        ]);

        $target = $meeting->participants()
            ->where('users.uuid', $data['to_uuid'])
            ->wherePivot('status', 'joined')
            ->first();
        abort_unless($target && $target->id !== $me->id, 422, 'That participant is not in the meeting.');

        $myPivot = $meeting->participants()->where('users.id', $me->id)->first()?->pivot;
        broadcast(new MeetingSignal($meeting, $me->uuid, $myPivot?->display_name ?? $me->name, $target->uuid, $data['signal'], $data['payload'] ?? []));

        return response()->json(['message' => 'ok']);
    }

    protected function serialize(Meeting $meeting, Request $request): array
    {
        return [
            'uuid' => $meeting->uuid,
            'code' => $meeting->code,
            'title' => $meeting->title,
            'type' => $meeting->type,
            'is_screen' => $meeting->is_screen,
            'requires_approval' => $meeting->requires_approval,
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
