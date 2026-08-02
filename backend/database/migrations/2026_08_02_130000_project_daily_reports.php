<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Daily email report: only on days the ledger actually changed.
            $table->boolean('daily_report')->default(false)->after('is_archived');
            $table->string('report_format', 8)->default('excel')->after('daily_report'); // excel | pdf
            $table->timestamp('last_reported_at')->nullable()->after('report_format');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['daily_report', 'report_format', 'last_reported_at']);
        });
    }
};
