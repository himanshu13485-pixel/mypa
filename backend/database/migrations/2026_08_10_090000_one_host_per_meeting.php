<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put down the second host.
 *
 * When a host left a meeting that carried on, the controls were handed to a
 * successor — but the departing host's own row was left saying 'host'. Nothing
 * demoted it, so the meeting then had two: the successor, because host_id says
 * so, and the person who walked out, because their row still does.
 *
 * Rejoining that meeting showed both tiles labelled Host, and the returning
 * one was offered the host's controls — End for all, admitting people at the
 * door — every one of which the server then refused, since moderating asks
 * host_id or a co-host row and neither of those was true. The controls looked
 * broken rather than absent.
 *
 * The hand-over now steps the old host down, as the deliberate one always has.
 * This settles the rows that were already left behind, including any the
 * guest-ownership repair created when it promoted a successor the same way.
 * They become co-hosts: they ran the meeting a moment ago, and dropping them
 * to participant would take away moderation they are expected to still have.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('meeting_participants')
            ->join('meetings', 'meetings.id', '=', 'meeting_participants.meeting_id')
            ->where('meeting_participants.role', 'host')
            ->whereColumn('meeting_participants.user_id', '!=', 'meetings.host_id')
            ->update(['meeting_participants.role' => 'cohost']);
    }

    public function down(): void
    {
        // There is no telling which of these were demoted here and which were
        // co-hosts all along, and handing the title back would recreate the
        // very rows that put two hosts in a room.
    }
};
