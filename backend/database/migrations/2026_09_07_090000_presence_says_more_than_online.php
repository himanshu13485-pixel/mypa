<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three states instead of two.
 *
 * `last_active_at` records that the app made a request, which is not the same
 * question as whether a person is there: a chat tab polls every twenty seconds
 * all night, so by that measure nobody is ever away. It stays — it is the only
 * signal a client without a heartbeat gives — but the browser now says what it
 * actually knows, and that is what these two columns hold.
 *
 * `presence_state` is what the client last reported about itself: online while
 * somebody is touching it, away once they have stopped, offline as the tab
 * closes. `presence_updated_at` is when it said so, because a report is only
 * worth trusting while it is recent — a browser that stopped beating (asleep,
 * crashed, killed) must decay on its own rather than stay green for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('presence_state', 8)->nullable()->after('last_active_at');
            $table->timestamp('presence_updated_at')->nullable()->index()->after('presence_state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['presence_state', 'presence_updated_at']);
        });
    }
};
