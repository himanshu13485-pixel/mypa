<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_participants', function (Blueprint $table) {
            // Same idea as meeting_participants: a browser that closes, crashes
            // or loses the network never calls /end, so it stays "joined" in
            // the call forever and everyone else keeps a dead tile for it.
            $table->timestamp('last_seen_at')->nullable()->after('joined_at');

            $table->index(['call_id', 'status', 'last_seen_at'], 'call_presence_idx');
        });
    }

    public function down(): void
    {
        Schema::table('call_participants', function (Blueprint $table) {
            $table->dropIndex('call_presence_idx');
            $table->dropColumn('last_seen_at');
        });
    }
};
