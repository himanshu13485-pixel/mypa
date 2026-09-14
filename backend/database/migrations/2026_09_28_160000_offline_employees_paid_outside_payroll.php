<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline employees: people the company pays who are not on the rolls.
 *
 * No Netvork account, no CRM seat, no salary structure - an Employee ID, a
 * name and what they are paid a month. Each month's payment is its own row,
 * kept here and counted in the P&L as Offline Salary, and never touched by
 * the payroll run.
 *
 * Each table is checked before it is made: MySQL cannot roll a half-run
 * migration back, and a rerun must not trip over its own first attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_offline_employees')) {
            Schema::create('crm_offline_employees', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->string('employee_code', 64);
                $table->string('name', 191);
                $table->decimal('monthly_amount', 12, 2)->default(0);
                $table->string('status', 16)->default('active');   // active | inactive
                $table->string('note', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['organization_id', 'employee_code'], 'crm_offline_emp_org_code');
            });
        }

        if (! Schema::hasTable('crm_offline_salaries')) {
            Schema::create('crm_offline_salaries', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('offline_employee_id')->constrained('crm_offline_employees')->cascadeOnDelete();
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->decimal('amount', 12, 2)->default(0);
                $table->date('paid_on')->nullable();
                $table->string('note', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // One payment a month per person.
                $table->unique(['offline_employee_id', 'year', 'month'], 'crm_offline_pay_emp_month');
                $table->index(['organization_id', 'year', 'month'], 'crm_offline_pay_org_month');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_offline_salaries');
        Schema::dropIfExists('crm_offline_employees');
    }
};
