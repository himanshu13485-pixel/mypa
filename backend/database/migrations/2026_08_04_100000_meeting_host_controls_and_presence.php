<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Optional numeric passcode on top of the join code (Zoom-style).
            $table->string('passcode', 12)->nullable()->after('requires_approval');
            // Locked: nobody new gets in, not even with the code + passcode.
            $table->boolean('is_locked')->default(false)->after('passcode');
            // Host-forced spotlight; null = let the active speaker win.
            $table->uuid('spotlight_uuid')->nullable()->after('is_locked');
        });

        Schema::table('meeting_participants', function (Blueprint $table) {
            // Presence heartbeat. A tab that closes, crashes or loses the
            // network never calls /leave, so the reaper uses this instead.
            $table->timestamp('last_seen_at')->nullable()->after('joined_at');
            // host | cohost | participant — co-hosts share the host controls.
            $table->string('role', 12)->default('participant')->after('display_name');
            // Mirrored media state so a late joiner sees the room correctly.
            $table->boolean('mic_on')->default(true)->after('role');
            $table->boolean('cam_on')->default(true)->after('mic_on');
            $table->boolean('hand_raised')->default(false)->after('cam_on');

            $table->index(['meeting_id', 'status', 'last_seen_at'], 'meeting_presence_idx');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['passcode', 'is_locked', 'spotlight_uuid']);
        });

        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->dropIndex('meeting_presence_idx');
            $table->dropColumn(['last_seen_at', 'role', 'mic_on', 'cam_on', 'hand_raised']);
        });
    }
};
