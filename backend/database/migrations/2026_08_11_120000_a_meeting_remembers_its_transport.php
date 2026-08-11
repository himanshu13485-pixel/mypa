<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a meeting's transport is written down.
 *
 * It used to be worked out fresh every time anybody asked, from the number of
 * people in the room at that instant — and each person is told which transport
 * to use exactly once, on the way in. So a threshold of four told the first
 * four "mesh" and the fifth "sfu", and the room quietly became two rooms that
 * could not see each other.
 *
 * Recording the answer is what makes escalation possible rather than dangerous.
 * The room's transport becomes a fact about the meeting instead of a fresh
 * opinion per request, so everyone can be told the same thing at the same time
 * and move together.
 *
 * Null means undecided — nobody has joined yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // A plain string rather than an enum: adding a third transport
            // later should not need a migration on a table this size, and the
            // only writer is one method in LiveKitTokenService.
            $table->string('transport', 8)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('transport');
        });
    }
};
