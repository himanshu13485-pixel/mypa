<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // Same-day alarm: due time + how many minutes before it to ring.
            $table->time('due_time')->nullable()->after('due_on');
            $table->unsignedSmallInteger('remind_minutes_before')->nullable()->after('remind_days_before');
            $table->timestamp('alarm_sent_at')->nullable()->after('last_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn(['due_time', 'remind_minutes_before', 'alarm_sent_at']);
        });
    }
};
