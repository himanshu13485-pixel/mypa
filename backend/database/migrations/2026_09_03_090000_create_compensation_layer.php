<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compensation layer: CTC structures, incentive plans, and loans.
 *
 * A salary was a single number on the employee record. The company's own
 * sheet says otherwise: a month's pay is a CTC broken into components
 * (basic, HRA, allowances), statutory money on both sides of the table
 * (PF, ESI, EDLI, welfare fund), an incentive computed from the sales
 * ledger under a per-employee plan, and loans working their way back out.
 *
 * Structures and plans are dated rows, never edits-in-place: a raise or a
 * changed slab starts a new row from its month, and every old payslip
 * keeps the structure it was computed under.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What the employee is paid, component by component.
        Schema::create('crm_salary_structures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->date('effective_from');
            // The whole cost of the seat, and its two headline parts.
            $table->decimal('ctc_monthly', 12, 2)->default(0);
            $table->decimal('basic', 12, 2)->default(0);
            $table->decimal('hra', 12, 2)->default(0);
            // Everything else — conveyance, medical, telephone, LTA, special,
            // fixed incentive… — as {key: label, amount} rows, because every
            // company slices this differently.
            $table->json('components')->nullable();
            // Which statutory schemes this employee is inside.
            $table->boolean('has_pf')->default(false);
            $table->boolean('has_esi')->default(false);
            $table->boolean('has_welfare')->default(true);
            $table->decimal('pt_amount', 8, 2)->default(0);       // professional tax, monthly
            $table->decimal('tds_monthly', 12, 2)->default(0);    // standing TDS, editable per slip
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'effective_from']);
        });

        // How the employee earns above the salary — everyone has a plan,
        // even if the plan is "none".
        Schema::create('crm_incentive_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->date('effective_from');
            // none | flat_percent | slab | percent_minus_base
            $table->string('kind', 32)->default('none');
            // The knobs of that kind: percent, base_amount, slabs
            // [{upto, percent}], slab_mode whole|marginal, team_percent.
            $table->json('config')->nullable();
            // Earned in month M, paid on the slip of month M + offset.
            $table->unsignedTinyInteger('release_offset_months')->default(1);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'effective_from']);
        });

        // Money the company put out and expects back.
        Schema::create('crm_loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->string('kind', 16)->default('loan');          // loan | advance
            $table->decimal('amount', 12, 2);
            $table->decimal('monthly_installment', 12, 2)->default(0);
            $table->date('taken_on');
            $table->string('note', 512)->nullable();
            $table->string('status', 16)->default('open');        // open | closed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'member_id', 'status']);
        });

        Schema::create('crm_loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('crm_loans')->cascadeOnDelete();
            // Set when the repayment came out of a payslip; a repayment made
            // in cash stands on its own.
            $table->foreignId('salary_slip_id')->nullable()->constrained('crm_salary_slips')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('repaid_on');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // The slip carries its whole story, not just its totals.
        Schema::table('crm_salary_slips', function (Blueprint $table) {
            $table->json('earnings')->nullable()->after('lop_days');
            $table->json('deduction_lines')->nullable()->after('earnings');
            $table->decimal('incentive_amount', 12, 2)->default(0)->after('deduction_lines');
            $table->json('incentive_breakdown')->nullable()->after('incentive_amount');
            // The month the incentive was earned in, 'YYYY-MM'.
            $table->string('incentive_month', 7)->nullable()->after('incentive_breakdown');
            $table->decimal('net_without_incentive', 12, 2)->nullable()->after('net_salary');
        });
    }

    public function down(): void
    {
        Schema::table('crm_salary_slips', function (Blueprint $table) {
            $table->dropColumn([
                'earnings', 'deduction_lines', 'incentive_amount',
                'incentive_breakdown', 'incentive_month', 'net_without_incentive',
            ]);
        });
        Schema::dropIfExists('crm_loan_repayments');
        Schema::dropIfExists('crm_loans');
        Schema::dropIfExists('crm_incentive_plans');
        Schema::dropIfExists('crm_salary_structures');
    }
};
