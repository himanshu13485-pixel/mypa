<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A number to ring, on a booking made by a stranger.
 *
 * The form asked for a name and an email, and the email is how the joining
 * link travels - so it stays required. But the person on the host's side of
 * the meeting often needs to reach whoever booked before it starts: a call
 * running late, a link that will not open, a question the note did not
 * answer. An email is a poor way to say "I am two minutes away".
 *
 * Optional, deliberately. A booking link's whole value is that it takes
 * seconds, and a required phone number is the field that makes somebody
 * close the tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings') || Schema::hasColumn('bookings', 'phone')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            // 32, as on a user's own mobile: long enough for a country code,
            // spaces and brackets, which is how people type a number.
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'phone')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
