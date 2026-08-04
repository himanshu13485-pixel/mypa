<?php

namespace App\Console\Commands;

use App\Events\MeetingSignal;
use App\Models\Meeting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Presence janitor for meeting rooms.
 *
 * Leaving cleanly hits /leave and the room empties itself, but a closed tab,
 * a crashed browser or a dropped connection never sends that. Those ghosts
 * would keep a meeting "active" forever and clutter everyone's roster, so we
 * lean on the heartbeat instead: anyone silent past the grace window is
 * treated as gone, and a room with nobody left in it ends.
 */
class ReapStaleMeetings extends Command
{
    protected $signature = 'mypa:reap-meetings';

    protected $description = 'Drop meeting participants that stopped sending a heartbeat, and end empty meetings';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(Meeting::PRESENCE_TIMEOUT_SECONDS);
        $dropped = 0;
        $ended = 0;

        Meeting::where('status', 'active')->chunkById(100, function ($meetings) use ($cutoff, &$dropped, &$ended) {
            foreach ($meetings as $meeting) {
                $dropped += $this->dropGhosts($meeting, $cutoff);

                // Nobody home -> the meeting is over. Guard on started_at so a
                // room created seconds ago isn't ended before anyone arrives.
                $stillIn = $meeting->participants()->wherePivot('status', 'joined')->count();
                if ($stillIn === 0 && $meeting->started_at && $meeting->started_at->lt($cutoff)) {
                    $meeting->update(['status' => 'ended', 'ended_at' => now(), 'spotlight_uuid' => null]);
                    $ended++;
                }
            }
        });

        $this->info("Dropped {$dropped} stale participant(s); ended {$ended} empty meeting(s).");

        return self::SUCCESS;
    }

    /** Mark silent participants as left and tell the room they went. */
    protected function dropGhosts(Meeting $meeting, \Illuminate\Support\Carbon $cutoff): int
    {
        $stale = $meeting->participants()
            ->wherePivot('status', 'joined')
            // A fresh joiner has no heartbeat yet — fall back to joined_at.
            ->where(function ($q) use ($cutoff) {
                $q->where('meeting_participants.last_seen_at', '<', $cutoff)
                    ->orWhere(fn ($n) => $n->whereNull('meeting_participants.last_seen_at')
                        ->where('meeting_participants.joined_at', '<', $cutoff));
            })
            ->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        DB::table('meeting_participants')
            ->where('meeting_id', $meeting->id)
            ->whereIn('user_id', $stale->pluck('id'))
            ->update(['status' => 'left', 'left_at' => now()]);

        $remaining = $meeting->participants()->wherePivot('status', 'joined')->get();
        foreach ($stale as $ghost) {
            foreach ($remaining as $peer) {
                \App\Support\Realtime::send(new MeetingSignal(
                    $meeting,
                    $ghost->uuid,
                    $ghost->pivot->display_name ?? $ghost->name,
                    $peer->uuid,
                    'leave',
                ));
            }
        }

        return $stale->count();
    }
}
