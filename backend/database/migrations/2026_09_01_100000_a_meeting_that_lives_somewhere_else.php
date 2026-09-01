<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Not everybody runs their meetings on Netvork.
 *
 * A host who lives in Google Meet all day was being handed a room their
 * guests would have to learn, on top of the one they already use. So the
 * booking page now says which of the two it books into, and a booking
 * remembers the answer it was given rather than asking the page later —
 * the host may switch providers next week, and a meeting already in
 * somebody's diary must not quietly move to a different room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_pages', function (Blueprint $table) {
            // 'netvork' or 'google_meet'. A string, not an enum: the next one
            // of these (Zoom, Teams) should not need a schema change.
            $table->string('meeting_provider', 16)->default('netvork');

            // Only meaningful when the provider is not Netvork's own.
            $table->string('external_meeting_url', 512)->nullable();
        });

        Schema::table('bookings', function (Blueprint $table) {
            /*
             * Where this particular booking is met, decided when it was made.
             *
             * Null means the Netvork room, which is reachable through
             * meeting_id and needs no copy. A value here is a link somewhere
             * else, and is the whole answer.
             */
            $table->string('meeting_url', 512)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_pages', function (Blueprint $table) {
            $table->dropColumn(['meeting_provider', 'external_meeting_url']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('meeting_url');
        });
    }
};
