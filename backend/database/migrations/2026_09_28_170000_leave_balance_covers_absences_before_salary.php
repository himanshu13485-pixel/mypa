<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of a month's absent days the leave balance paid for.
 *
 * When salaries are made, absent and unpaid-leave days are first paid from
 * the paid-leave account; only what it cannot cover is cut from the salary.
 * The slip keeps the number it was built with, so it explains itself later
 * - and the account's own ledger row says the same from the other side.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_salary_slips', 'leave_covered_days')) {
            Schema::table('crm_salary_slips', function (Blueprint $table) {
                $table->decimal('leave_covered_days', 5, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_salary_slips', 'leave_covered_days')) {
            Schema::table('crm_salary_slips', function (Blueprint $table) {
                $table->dropColumn('leave_covered_days');
            });
        }
    }
};
