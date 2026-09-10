<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a person decides about a chat for themselves.
 *
 * Both belong on the membership row rather than on the conversation: pinning
 * a chat to the top of your list, and choosing what colour it is, are your
 * arrangements and nobody else's. The other side of the same conversation
 * keeps their own order and their own colours - unlike the disappearing
 * messages span, which is the room's decision and lives on the room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('archived_at');
            $table->string('theme', 24)->nullable()->after('pinned_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->dropColumn(['pinned_at', 'theme']);
        });
    }
};
