<?php

namespace App\Console\Commands;

use App\Events\CallSignal;
use App\Events\MeetingSignal;
use App\Models\Call;
use App\Models\Meeting;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Presence janitor for meeting rooms and calls.
 *
 * Leaving cleanly hits /leave (or /end) and the room empties itself, but a
 * closed tab, a crashed browser or a dropped connection never sends that.
 * Those ghosts would keep a meeting or call alive forever and leave a dead
 * tile on everyone else's screen, so we lean on the heartbeat instead: anyone
 * silent past the grace window is treated as gone, and a room with nobody
 * left in it ends.
 */
class ReapStaleMeetings extends Command
{
    protected $signature = 'mypa:reap-meetings';

    protected $description = 'Drop meeting and call participants that stopped sending a heartbeat, and end empty rooms';

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

        [$callsDropped, $callsEnded] = $this->reapCalls();
        $guests = $this->reapGuests();
        $abandoned = $this->reapAbandoned();

        $this->info("Dropped {$dropped} stale participant(s); ended {$ended} empty meeting(s).");
        $this->info("Calls: dropped {$callsDropped}; ended {$callsEnded}.");
        $this->info("Guests: removed {$guests} expired pass(es).");
        $this->info("Removed {$abandoned} abandoned meeting(s) nobody ever opened.");

        return self::SUCCESS;
    }

    /**
     * Delete meetings that were made and never used.
     *
     * Pressing "New meeting" creates the room before the lobby is shown, so
     * anyone who then changes their mind — closes the tab, presses Cancel —
     * leaves a row behind that nothing ever cleaned up and, until now, nothing
     * could delete. Over weeks the list stops being your meetings and becomes
     * a log of every time you tapped the button.
     *
     * Deliberately narrow. Only rooms with no title, no time, no password and
     * nobody who ever joined, and only after a day — long enough that a link
     * shared for later still works, and anything you took the trouble to name
     * or set a time on is never touched.
     */
    protected function reapAbandoned(): int
    {
        $meetings = Meeting::abandoned(now()->subDay())->limit(500)->get();

        foreach ($meetings as $meeting) {
            $meeting->delete();
        }

        return $meetings->count();
    }

    /**
     * Delete guest accounts whose half hour ran out a while ago.
     *
     * The pass stops working the moment it expires; this is only tidying, so a
     * spent guest row does not sit in the users table for ever. The hour of
     * grace means a guest refreshing just as their time runs out still gets
     * the "your 30 minutes are up" answer rather than a bare 401.
     */
    protected function reapGuests(): int
    {
        return User::withoutGlobalScope('withoutMeetingGuests')
            ->whereNotNull('guest_meeting_id')
            ->where('guest_expires_at', '<', now()->subHour())
            ->delete();
    }

    /**
     * The same sweep for calls. Only 'ongoing' calls are touched — a 'ringing'
     * one is waiting for somebody to answer and has no heartbeat yet.
     */
    protected function reapCalls(): array
    {
        $cutoff = now()->subSeconds(Call::PRESENCE_TIMEOUT_SECONDS);
        $dropped = 0;
        $ended = 0;

        Call::where('status', 'ongoing')->chunkById(100, function ($calls) use ($cutoff, &$dropped, &$ended) {
            foreach ($calls as $call) {
                $stale = $call->participants()
                    ->wherePivot('status', 'joined')
                    ->where(function ($q) use ($cutoff) {
                        $q->where('call_participants.last_seen_at', '<', $cutoff)
                            ->orWhere(fn ($n) => $n->whereNull('call_participants.last_seen_at')
                                ->where('call_participants.joined_at', '<', $cutoff));
                    })
                    ->get();

                if ($stale->isNotEmpty()) {
                    DB::table('call_participants')
                        ->where('call_id', $call->id)
                        ->whereIn('user_id', $stale->pluck('id'))
                        ->update(['status' => 'left', 'left_at' => now()]);
                    $dropped += $stale->count();

                    $loaded = $call->load(['conversation', 'caller']);
                    foreach ($stale as $ghost) {
                        foreach ($call->inCall() as $peer) {
                            Realtime::send(new CallSignal($loaded, $ghost->uuid, $ghost->name, $peer->uuid, 'peer-left', [
                                'left_uuid' => $ghost->uuid,
                            ]));
                        }
                    }
                }

                // A call needs two people. One person alone is not a call.
                if ($call->inCall()->count() < 2 && $call->answered_at && $call->answered_at->lt($cutoff)) {
                    $call->update(['status' => 'ended', 'ended_at' => now()]);
                    $ended++;
                    $loaded = $call->load(['conversation', 'caller']);
                    foreach ($call->participants()->wherePivot('status', 'joined')->get() as $peer) {
                        Realtime::send(new CallSignal($loaded, $peer->uuid, $peer->name, $peer->uuid, 'end'));
                    }
                    DB::table('call_participants')
                        ->where('call_id', $call->id)->where('status', 'joined')
                        ->update(['status' => 'left', 'left_at' => now()]);
                }
            }
        });

        return [$dropped, $ended];
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
