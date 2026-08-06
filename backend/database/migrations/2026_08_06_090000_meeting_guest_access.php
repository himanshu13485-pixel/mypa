<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joining a meeting without an account.
 *
 * A guest is stored as a user so that everything a meeting already does —
 * the participant pivot, presence, signalling, the waiting room, removal —
 * keeps working untouched. What makes them a guest is guest_meeting_id: it
 * ties them to exactly one meeting, and a global scope on the User model
 * keeps rows carrying it out of every ordinary query, so a guest can never
 * turn up in a search, a suggestion or an admin list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('guest_meeting_id')->nullable()->after('status')
                ->constrained('meetings')->cascadeOnDelete();
            // When the guest's pass runs out. Also what the reaper sweeps on.
            $table->timestamp('guest_expires_at')->nullable()->after('guest_meeting_id');
            // Hashed, never stored raw — it is a credential like any other.
            $table->string('guest_token', 64)->nullable()->unique()->after('guest_expires_at');
            $table->index('guest_expires_at');
        });

        Schema::table('meetings', function (Blueprint $table) {
            // Off unless the host asks for it, so no existing meeting silently
            // becomes reachable without an account.
            $table->boolean('guest_access')->default(false)->after('passcode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['guest_meeting_id']);
            $table->dropColumn(['guest_meeting_id', 'guest_expires_at', 'guest_token']);
        });
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('guest_access');
        });
    }
};
