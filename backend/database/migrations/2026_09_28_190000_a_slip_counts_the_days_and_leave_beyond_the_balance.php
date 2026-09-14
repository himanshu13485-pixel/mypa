<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A slip says how the month was counted, and what leave beyond the balance cost.
 *
 * present_days is what the attendance showed; leave_overdrawn_days is leave
 * taken past the paid-leave balance in that month, cut from the salary as
 * days without pay. The account's own ledger carries the matching row, so
 * the balance is not charged a second time the next month.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_salary_slips', 'present_days')) {
            Schema::table('crm_salary_slips', function (Blueprint $table) {
                $table->decimal('present_days', 5, 2)->nullable();
            });
        }
        if (! Schema::hasColumn('crm_salary_slips', 'leave_overdrawn_days')) {
            Schema::table('crm_salary_slips', function (Blueprint $table) {
                $table->decimal('leave_overdrawn_days', 5, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['present_days', 'leave_overdrawn_days'] as $column) {
            if (Schema::hasColumn('crm_salary_slips', $column)) {
                Schema::table('crm_salary_slips', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
