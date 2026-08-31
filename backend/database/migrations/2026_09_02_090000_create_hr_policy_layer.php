<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR layer: holidays, the leave account, probation, and the day count
 * a salary is actually built from.
 *
 * Attendance used to be a pile of punch rows — a day with no row said
 * nothing, so "absent" and "on approved leave" and "public holiday" all
 * looked identical. These tables give the calendar the two things it was
 * missing: the days nobody was meant to work, and the days somebody was
 * excused from working.
 *
 * The leave account is a ledger, not a number. A balance you can only read
 * is a balance nobody can argue with; a ledger says when each day was
 * earned, when it was spent, and what was paid out for what was left.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The days the office is shut, declared a financial year at a time.
        Schema::create('crm_holidays', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('name', 191);
            // The Indian financial year the date falls in, by its start year:
            // 2026 means 1 Apr 2026 – 31 Mar 2027.
            $table->unsignedSmallInteger('financial_year');
            // A restricted holiday: the office is open, but taking it is free.
            $table->boolean('is_optional')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'holiday_date']);
            $table->index(['organization_id', 'financial_year']);
        });

        // Every day of paid leave earned, spent, or cashed out.
        Schema::create('crm_leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->unsignedSmallInteger('financial_year');
            // credit (monthly accrual or a manual grant), debit (leave taken),
            // encash (paid out at year end).
            $table->string('kind', 16);
            $table->decimal('days', 6, 2);
            $table->date('effective_on');
            // 'YYYY-MM' for an accrual, so the same month is never credited
            // twice however often the job runs.
            $table->string('period', 7)->nullable();
            $table->foreignId('leave_id')->nullable()->constrained('crm_leaves')->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->nullable();   // only an encashment has one
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['member_id', 'kind', 'period']);
            $table->index(['organization_id', 'member_id', 'financial_year']);
        });

        Schema::table('crm_members', function (Blueprint $table) {
            // Null means "whatever the HR Policy says" — one knob moves
            // everybody, and a longer probation is a deliberate exception.
            $table->unsignedSmallInteger('probation_days')->nullable()->after('joined_at');
        });

        Schema::table('crm_leaves', function (Blueprint $table) {
            // Split at the moment of approval: what the balance covered, and
            // what it did not and so comes off the salary.
            $table->decimal('paid_days', 6, 2)->default(0)->after('days');
            $table->decimal('unpaid_days', 6, 2)->default(0)->after('paid_days');
        });

        Schema::table('crm_salary_slips', function (Blueprint $table) {
            // What the month was actually worth, and how it was arrived at.
            $table->unsignedTinyInteger('month_days')->nullable()->after('monthly_salary');
            $table->decimal('payable_days', 6, 2)->nullable()->after('month_days');
            $table->decimal('lop_days', 6, 2)->default(0)->after('payable_days');
        });
    }

    public function down(): void
    {
        Schema::table('crm_salary_slips', function (Blueprint $table) {
            $table->dropColumn(['month_days', 'payable_days', 'lop_days']);
        });
        Schema::table('crm_leaves', function (Blueprint $table) {
            $table->dropColumn(['paid_days', 'unpaid_days']);
        });
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('probation_days');
        });
        Schema::dropIfExists('crm_leave_ledger');
        Schema::dropIfExists('crm_holidays');
    }
};
