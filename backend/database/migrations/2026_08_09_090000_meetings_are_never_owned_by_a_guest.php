<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give back any meeting that ended up owned by a guest.
 *
 * When the host left a meeting that carried on, the controls were handed to
 * whoever had been in the room longest — and that was allowed to be a guest,
 * who has no account and is hidden from ordinary user queries. The meeting was
 * then owned by somebody the host relation could not resolve, so every later
 * read of it failed on "Attempt to read property uuid on null" and took out
 * the caller's whole meetings list. The host could not delete it either, since
 * deleting asks whether you own it.
 *
 * The hand-over now refuses guests. This puts the rows that already went that
 * way back with the earliest member who was actually in the meeting. A meeting
 * with no member left in it is left alone — there is nobody to give it to, and
 * reading it is no longer fatal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('meetings as m')
            ->join('users as u', 'u.id', '=', 'm.host_id')
            ->whereNotNull('u.guest_meeting_id')
            ->pluck('m.id');

        foreach ($orphans as $meetingId) {
            $successor = DB::table('meeting_participants as mp')
                ->join('users as u', 'u.id', '=', 'mp.user_id')
                ->where('mp.meeting_id', $meetingId)
                ->whereNull('u.guest_meeting_id')
                ->orderByRaw('mp.joined_at is null, mp.joined_at')
                ->value('u.id');

            if ($successor === null) {
                continue;
            }

            DB::table('meetings')->where('id', $meetingId)->update(['host_id' => $successor]);
            DB::table('meeting_participants')
                ->where('meeting_id', $meetingId)
                ->where('user_id', $successor)
                ->update(['role' => 'host']);
        }
    }

    public function down(): void
    {
        // Handing these back to the guests they were wrongly given to is not
        // something anybody wants, and the old owner is not recorded anywhere.
    }
};
