<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The calendar was the one dated thing in the app that never said anything.
 *
 * Tasks have reminders, bills have reminders and alarms, meetings learned to
 * announce themselves ten minutes ahead, habits and goals nudge on a
 * schedule, and project entries carry their own reminder_at. Events had
 * starts_at and nothing that read it — so an appointment could be put in the
 * calendar for Tuesday at nine and, when Tuesday came, the app said nothing at
 * all. The only reminder a calendar entry ever produced was the one sent when
 * somebody was invited to it, days earlier.
 *
 * Same shape as meetings.reminded_at, deliberately: one nullable timestamp is
 * all it takes to make a sweep idempotent, and matching the column that
 * already exists means the two commands can be read against each other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('reminded_at')->nullable()->after('repeat_config');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
