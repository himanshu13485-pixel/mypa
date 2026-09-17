<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same remarks, for the people paid outside the payroll.
 *
 * Offline employees have their own register and their own months, and the
 * questions asked about them are the same ones: why this amount, which
 * account it went from, what was odd about the month. One table serves both,
 * because it is one idea.
 *
 * scope is what keeps the two registers apart. A remark about "this month"
 * belongs to the screen it was written on - the payroll's own month note has
 * no business appearing above the offline list, and the other way round.
 * Per-person rows are already unambiguous, but they carry it too so that one
 * column answers the question everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_salary_notes')) {
            return;
        }

        Schema::table('crm_salary_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_salary_notes', 'scope')) {
                $table->string('scope', 8)->default('salary')->after('month');
            }
        });

        // Separately, and guarded on its own: MySQL cannot roll back half a
        // migration, so a re-run after a failure must not trip over the
        // column it already added.
        Schema::table('crm_salary_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_salary_notes', 'offline_employee_id')) {
                $table->foreignId('offline_employee_id')->nullable()->after('member_id')
                    ->constrained('crm_offline_employees')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crm_salary_notes')) {
            return;
        }

        Schema::table('crm_salary_notes', function (Blueprint $table) {
            if (Schema::hasColumn('crm_salary_notes', 'offline_employee_id')) {
                $table->dropConstrainedForeignId('offline_employee_id');
            }
        });

        Schema::table('crm_salary_notes', function (Blueprint $table) {
            if (Schema::hasColumn('crm_salary_notes', 'scope')) {
                $table->dropColumn('scope');
            }
        });
    }
};
