<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a scheduled meeting as already reminded.
 *
 * The reminder command runs every minute; without somewhere to write "done",
 * a meeting starting in ten minutes would be announced sixty times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
