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
            'passcode' => ['sometimes', 'nullable', 'string', 'min:4', 'max:12', 'alpha_num'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $meeting = Meeting::create([
            'host_id' => $request->user()->id,
            'code' => Meeting::generateCode(),
            'title' => $data['title'] ?? null,
            'type' => $data['type'] ?? 'video',
            'is_screen' => (bool) ($data['is_screen'] ?? false),
            'requires_approval' => (bool) ($data['requires_approval'] ?? true),
            'passcode' => ($data['passcode'] ?? null) ?: null,
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
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'passcode' => ['sometimes', 'nullable', 'string', 'max:12'],
            'mic_on' => ['sometimes', 'boolean'],
            'cam_on' => ['sometimes', 'boolean'],
        ]);

        $moderator = $meeting->canModerate($me);
        $myPivot = $meeting->participants()->where('users.id', $me->id)->first()?->pivot;

        // Being removed means removed: the link alone must not let someone
        // walk straight back in. A host who changes their mind re-admits them
        // (that flips the status back to "admitted").
        abort_if(
            $myPivot?->status === 'removed',
            403,
            'You were removed from this meeting.',
        );

        // Locked room: the host shut the door. Moderators still get back in
        // (e.g. after a refresh), everyone else is turned away.
        abort_if($meeting->is_locked && ! $moderator, 423, 'This meeting is locked — the host is not letting anyone else in.');

        // Passcode gate, checked before the waiting room so a wrong code never
        // reaches the host as a knock.
        if ($meeting->passcode && ! $moderator) {
            abort_unless(
                hash_equals($meeting->passcode, (string) ($data['passcode'] ?? '')),
                403,
                'That passcode is not right.',
            );
        }

        // Waiting room: non-hosts must be admitted first (unless bypassed or
        // previously admitted/inside).
        if ($meeting->requires_approval && ! $moderator) {
            $everAdmitted = $myPivot && in_array($myPivot->status, ['joined', 'left', 'admitted'], true);
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

        $joined = $meeting->inRoom($me->id);

        // Keep an existing co-host promotion across a refresh.
        $myRole = $meeting->host_id === $me->id ? 'host' : $meeting->roleFor($me);
        $meeting->participants()->syncWithoutDetaching([
            $me->id => [
                'status' => 'joined',
                'role' => $myRole,
                'joined_at' => now(),
                'last_seen_at' => now(),
                'left_at' => null,
                'mic_on' => (bool) ($data['mic_on'] ?? true),
                'cam_on' => (bool) ($data['cam_on'] ?? true),
                'hand_raised' => false,
                'display_name' => $data['display_name'] ?? null,
            ],
        ]);

        // Tell everyone already inside that a participant arrived (for the roster).
        $myName = $data['display_name'] ?? $me->name;
        foreach ($joined as $peer) {
            broadcast(new MeetingSignal($meeting, $me->uuid, $myName, $peer->uuid, 'join'));
        }

        $meeting = $meeting->fresh()->load('host:id,uuid,name');

        return response()->json([
            'message' => 'Joined.',
            'data' => $this->serialize($meeting, $request) + [
                'joined_peers' => $joined->map(fn ($u) => $this->rosterEntry($meeting, $u))->values(),
                'heartbeat_seconds' => (int) floor(Meeting::PRESENCE_TIMEOUT_SECONDS / 3),
            ],
        ]);
    }

    /**
     * Presence ping. Without this a closed tab or dead connection would leave
     * a ghost in the room forever; mypa:reap-meetings sweeps anyone who stops
     * calling it. Doubles as the poll that tells a client the meeting ended
     * or that someone is knocking.
     */
    public function heartbeat(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();

        if ($meeting->status === 'ended') {
            return response()->json(['data' => ['status' => 'ended', 'participants' => [], 'waiting' => []]]);
        }

        $touched = $meeting->participants()
            ->where('users.id', $me->id)
            ->wherePivot('status', 'joined')
            ->exists();
        abort_unless($touched, 409, 'You are not in this meeting.');

        $meeting->participants()->updateExistingPivot($me->id, ['last_seen_at' => now()]);

        $roster = $meeting->inRoom()->map(fn ($u) => $this->rosterEntry($meeting, $u))->values();

        // Moderators also get the knock list, so a refresh doesn't lose the
        // people still waiting outside.
        $waiting = $meeting->canModerate($me)
            ? $meeting->participants()->wherePivot('status', 'waiting')->get()
                ->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->pivot->display_name ?? $u->name])->values()
            : collect();

        return response()->json(['data' => [
            'status' => $meeting->status,
            'is_locked' => $meeting->is_locked,
            'spotlight_uuid' => $meeting->spotlight_uuid,
            'participants' => $roster,
            'waiting' => $waiting,
        ]]);
    }

    public function leave(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        $meeting->participants()->syncWithoutDetaching([
            $me->id => ['status' => 'left', 'left_at' => now(), 'hand_raised' => false],
        ]);

        $remaining = $meeting->inRoom($me->id);
        foreach ($remaining as $peer) {
            broadcast(new MeetingSignal($meeting, $me->uuid, $me->name, $peer->uuid, 'leave'));
        }

        // Room empties out -> meeting ends by itself.
        if ($remaining->isEmpty()) {
            if ($meeting->status === 'active') {
                $meeting->update(['status' => 'ended', 'ended_at' => now(), 'spotlight_uuid' => null]);
            }

            return response()->json(['message' => 'Left the meeting.']);
        }

        // The host walked out but the meeting carries on: hand the controls to
        // a co-host, or failing that to whoever has been here longest, so the
        // room is never left with nobody able to moderate it.
        if ($meeting->host_id === $me->id) {
            $successor = $remaining
                ->sortBy(fn ($u) => [$u->pivot->role === 'cohost' ? 0 : 1, (string) $u->pivot->joined_at])
                ->first();

            $meeting->update(['host_id' => $successor->id]);
            $meeting->participants()->updateExistingPivot($successor->id, ['role' => 'host']);
            $this->tellRoom($meeting, $me, $me->name, 'role', [
                'uuid' => $successor->uuid,
                'role' => 'host',
                'previous_host' => $me->uuid,
                'auto' => true,
            ]);
        }

        return response()->json(['message' => 'Left the meeting.']);
    }

    /** Host ends the meeting for everyone. */
    public function end(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless($meeting->host_id === $me->id, 403, 'Only the host can end the meeting for everyone.');

        $meeting->update(['status' => 'ended', 'ended_at' => now(), 'spotlight_uuid' => null]);

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
        abort_unless($meeting->canModerate($request->user()), 403, 'Only the host or a co-host can admit people.');

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
        abort_unless($meeting->canModerate($request->user()), 403, 'Only the host or a co-host can change this.');
        $data = $request->validate(['requires_approval' => ['required', 'boolean']]);
        $meeting->update(['requires_approval' => $data['requires_approval']]);

        return response()->json([
            'message' => $data['requires_approval']
                ? 'Approval required: new joiners now wait for you.'
                : 'Open access: anyone with the link joins directly.',
        ]);
    }

    /**
     * Everything a host (or co-host) can do to the room and the people in it.
     * One endpoint because they all share the same permission check and the
     * same "tell the room what changed" tail.
     */
    public function hostAction(Request $request, Meeting $meeting): JsonResponse
    {
        $me = $request->user();
        abort_unless($meeting->canModerate($me), 403, 'Only the host or a co-host can do that.');

        $data = $request->validate([
            'action' => ['required', 'in:mute,mute_all,ask_unmute,stop_video,remove,lock,unlock,promote,demote,transfer_host,spotlight,clear_spotlight'],
            'user_uuid' => ['required_unless:action,mute_all,lock,unlock,clear_spotlight', 'nullable', 'uuid'],
        ]);

        $action = $data['action'];
        $myName = $meeting->participants()->where('users.id', $me->id)->first()?->pivot->display_name ?? $me->name;

        // Room-wide switches first — they need no target.
        if (in_array($action, ['lock', 'unlock'], true)) {
            $meeting->update(['is_locked' => $action === 'lock']);
            $this->tellRoom($meeting, $me, $myName, 'lock', ['locked' => $action === 'lock']);

            return response()->json(['message' => $action === 'lock'
                ? 'Meeting locked — nobody else can join.'
                : 'Meeting unlocked.']);
        }

        if ($action === 'clear_spotlight') {
            $meeting->update(['spotlight_uuid' => null]);
            $this->tellRoom($meeting, $me, $myName, 'spotlight', ['uuid' => null]);

            return response()->json(['message' => 'Spotlight cleared.']);
        }

        if ($action === 'mute_all') {
            $meeting->participants()->newPivotStatement()
                ->where('meeting_id', $meeting->id)
                ->where('status', 'joined')
                ->where('user_id', '!=', $me->id)
                ->update(['mic_on' => false]);
            $this->tellRoom($meeting, $me, $myName, 'host-mute', ['all' => true]);

            return response()->json(['message' => 'Everyone else has been muted.']);
        }

        // Everything below targets one participant.
        $target = $meeting->participants()->where('users.uuid', $data['user_uuid'])->first();
        abort_unless($target, 422, 'That participant is not in this meeting.');
        abort_if($target->id === $me->id, 422, 'That one is for other people, not you.');
        // A co-host cannot act on the host.
        abort_if($target->id === $meeting->host_id, 403, 'You cannot do that to the host.');

        $targetName = $target->pivot->display_name ?? $target->name;

        switch ($action) {
            case 'mute':
                $meeting->participants()->updateExistingPivot($target->id, ['mic_on' => false]);
                broadcast(new MeetingSignal($meeting, $me->uuid, $myName, $target->uuid, 'host-mute', []));
                $message = "{$targetName} muted.";
                break;

            case 'ask_unmute':
                broadcast(new MeetingSignal($meeting, $me->uuid, $myName, $target->uuid, 'host-ask-unmute', []));
                $message = "Asked {$targetName} to unmute.";
                break;

            case 'stop_video':
                $meeting->participants()->updateExistingPivot($target->id, ['cam_on' => false]);
                broadcast(new MeetingSignal($meeting, $me->uuid, $myName, $target->uuid, 'host-stop-video', []));
                $message = "{$targetName}'s camera stopped.";
                break;

            case 'remove':
                broadcast(new MeetingSignal($meeting, $me->uuid, $myName, $target->uuid, 'removed', []));
                $meeting->participants()->updateExistingPivot($target->id, [
                    'status' => 'removed', 'left_at' => now(),
                ]);
                // The rest of the room drops their peer connection to them.
                foreach ($meeting->inRoom($me->id) as $peer) {
                    if ($peer->id === $target->id) {
                        continue;
                    }
                    broadcast(new MeetingSignal($meeting, $target->uuid, $targetName, $peer->uuid, 'leave', []));
                }
                $message = "{$targetName} was removed.";
                break;

            case 'promote':
            case 'demote':
                $role = $action === 'promote' ? 'cohost' : 'participant';
                $meeting->participants()->updateExistingPivot($target->id, ['role' => $role]);
                $this->tellRoom($meeting, $me, $myName, 'role', ['uuid' => $target->uuid, 'role' => $role]);
                $message = $action === 'promote' ? "{$targetName} is now a co-host." : "{$targetName} is no longer a co-host.";
                break;

            case 'transfer_host':
                abort_unless($meeting->host_id === $me->id, 403, 'Only the host can hand over the meeting.');
                $meeting->update(['host_id' => $target->id]);
                $meeting->participants()->updateExistingPivot($target->id, ['role' => 'host']);
                $meeting->participants()->updateExistingPivot($me->id, ['role' => 'cohost']);
                $this->tellRoom($meeting, $me, $myName, 'role', [
                    'uuid' => $target->uuid, 'role' => 'host', 'previous_host' => $me->uuid,
                ]);
                $message = "{$targetName} is now the host.";
                break;

            case 'spotlight':
                $meeting->update(['spotlight_uuid' => $target->uuid]);
                $this->tellRoom($meeting, $me, $myName, 'spotlight', ['uuid' => $target->uuid]);
                $message = "{$targetName} is spotlighted for everyone.";
                break;

            default:
                abort(422, 'Unknown action.');
        }

        return response()->json(['message' => $message]);
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

        // Counts against the sharer's storage quota, like any other upload.
        if (! app(\App\Services\SubscriptionEntitlementService::class)->canUploadBytes($me, (int) $upload->getSize())) {
            return response()->json([
                'message' => 'Sharing this file would take you over your storage limit.',
            ], 422);
        }

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
            'emoji' => ['required', 'in:thumbsup,clap,heart,laugh,wow,party,hand,hand_down,yes,no,slower,faster'],
        ]);

        // A raised hand outlives the burst animation, so it is state, not an
        // event — persist it for the roster and for anyone joining later.
        if (in_array($data['emoji'], ['hand', 'hand_down'], true)) {
            $meeting->participants()->updateExistingPivot($me->id, ['hand_raised' => $data['emoji'] === 'hand']);
        }

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

        // Mirror mic/cam state onto the pivot so the roster (and anyone who
        // joins later) shows it, instead of only whoever was online to hear it.
        if ($data['signal'] === 'media') {
            $meeting->participants()->updateExistingPivot($me->id, [
                'mic_on' => (bool) ($data['payload']['mic'] ?? true),
                'cam_on' => (bool) ($data['payload']['cam'] ?? true),
            ]);
        }

        $myPivot = $meeting->participants()->where('users.id', $me->id)->first()?->pivot;
        broadcast(new MeetingSignal($meeting, $me->uuid, $myPivot?->display_name ?? $me->name, $target->uuid, $data['signal'], $data['payload'] ?? []));

        return response()->json(['message' => 'ok']);
    }

    /** One participant as the room sees them. */
    protected function rosterEntry(Meeting $meeting, \App\Models\User $user): array
    {
        $pivot = $user->pivot;

        return [
            'uuid' => $user->uuid,
            'name' => $pivot->display_name ?? $user->name,
            'role' => $meeting->host_id === $user->id ? 'host' : ($pivot->role ?? 'participant'),
            'mic_on' => (bool) ($pivot->mic_on ?? true),
            'cam_on' => (bool) ($pivot->cam_on ?? true),
            'hand_raised' => (bool) ($pivot->hand_raised ?? false),
        ];
    }

    /** Fan a signal out to everyone currently in the room. */
    protected function tellRoom(Meeting $meeting, \App\Models\User $from, string $fromName, string $signal, array $payload = []): void
    {
        foreach ($meeting->inRoom($from->id) as $peer) {
            broadcast(new MeetingSignal($meeting, $from->uuid, $fromName, $peer->uuid, $signal, $payload));
        }
    }

    protected function serialize(Meeting $meeting, Request $request): array
    {
        $me = $request->user();
        $myRole = $meeting->roleFor($me);
        $canModerate = in_array($myRole, ['host', 'cohost'], true);

        return [
            'uuid' => $meeting->uuid,
            'code' => $meeting->code,
            'title' => $meeting->title,
            'type' => $meeting->type,
            'is_screen' => $meeting->is_screen,
            'requires_approval' => $meeting->requires_approval,
            'is_locked' => $meeting->is_locked,
            'has_passcode' => (bool) $meeting->passcode,
            // Only a moderator sees the actual passcode — they are the one
            // who has to pass it on to invitees.
            'passcode' => $canModerate ? $meeting->passcode : null,
            'spotlight_uuid' => $meeting->spotlight_uuid,
            'my_role' => $myRole,
            'can_moderate' => $canModerate,
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
