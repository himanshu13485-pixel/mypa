<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Events\MeetingSignal;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Meeting;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What is running on the platform right now, and the ability to stop it.
 *
 * "Live" means active *and* still occupied, judged by the same presence
 * heartbeat the room itself uses — so a browser closed without leaving does
 * not keep a meeting looking busy, and an admin is not chasing ghosts.
 */
class LiveMeetingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Meeting::query()
            ->where('status', 'active')
            ->with('host:id,uuid,name,username')
            ->orderBy('started_at')
            ->get()
            ->map(function (Meeting $m) {
                // Not inRoom(): that only asks who never pressed Leave, so it
                // still counts browsers that were closed on the room until the
                // reaper next sweeps. An admin looking at "live right now"
                // wants the heartbeat, which is what the room itself trusts.
                $present = $m->participants()
                    ->wherePivot('status', 'joined')
                    ->wherePivot('last_seen_at', '>=', now()->subSeconds(Meeting::PRESENCE_TIMEOUT_SECONDS))
                    ->get();

                return [
                    'uuid' => $m->uuid,
                    'code' => $m->code,
                    'title' => $m->title,
                    'type' => $m->type,
                    'host' => $m->host?->only(['uuid', 'name', 'username']),
                    'started_at' => $m->started_at,
                    // How long it has been running is what tells an admin
                    // something was left going by accident.
                    'running_minutes' => $m->started_at ? (int) $m->started_at->diffInMinutes(now()) : 0,
                    'participants' => $present->count(),
                    'participant_names' => $present->pluck('name')->values(),
                    'is_locked' => (bool) $m->is_locked,
                ];
            })
            // Active but empty is a room the reaper has not swept yet. It is
            // not live in any sense an admin cares about.
            ->filter(fn (array $r) => $r['participants'] > 0)
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'live_meetings' => $rows->count(),
                'people_in_meetings' => $rows->sum('participants'),
            ],
        ]);
    }

    /**
     * End a meeting for everyone in it.
     *
     * Deliberately the same shape as the host's own "end for all": each person
     * still in the room gets the signal their client already listens for, so
     * they are returned to the meetings list instead of sitting in a room whose
     * record has quietly gone.
     */
    public function destroy(Request $request, Meeting $meeting): JsonResponse
    {
        if ($meeting->status === 'ended') {
            return response()->json(['message' => 'That meeting has already ended.'], 409);
        }

        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $me = $request->user();
        $joined = $meeting->participants()->wherePivot('status', 'joined')->get();

        $meeting->update(['status' => 'ended', 'ended_at' => now(), 'spotlight_uuid' => null]);

        foreach ($joined as $peer) {
            Realtime::send(new MeetingSignal($meeting, $me->uuid, $me->name, $peer->uuid, 'end'));
        }

        $meeting->participants()->newPivotStatement()
            ->where('meeting_id', $meeting->id)->where('status', 'joined')
            ->update(['status' => 'left', 'left_at' => now()]);

        AuditLog::create([
            'actor_id' => $me->id,
            'action' => 'meeting.force_end',
            'subject_type' => Meeting::class,
            'subject_id' => $meeting->id,
            'details' => [
                'code' => $meeting->code,
                'host_id' => $meeting->host_id,
                'participants' => $joined->count(),
                'reason' => $data['reason'] ?? null,
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Meeting ended for everyone.']);
    }
}
