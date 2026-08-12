<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When somebody was last using the app.
 *
 * There was no presence anywhere: the admin screen inferred "online" from
 * whether a login row was under an hour old and had no logout beside it, which
 * calls somebody online for an hour after they close the tab and forever if
 * they never press Sign out. Nothing else could ask the question at all.
 *
 * Indexed because the interesting query is always a comparison — everyone
 * active since a moment — never a lookup by value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_active_at');
        });
    }
};
