<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things that belong to the money trail.
 *
 * A receipt in the Payments inbox gets its payment id (PAY-000123) the moment
 * it is logged, not only once it lands on an invoice - so the id people quote
 * from the Payments screen is the same one the invoice shows after settling.
 * Receipts already settled take the id their invoice payment has; the rest
 * are numbered after the highest id in use anywhere.
 *
 * And an approved office-money claim (a mobile recharge, a travel bill) is
 * paid back through the person's salary. The approval remembers which slip
 * paid it, so it is paid once; the slip keeps what it reimbursed, line by
 * line.
 *
 * Each step checks before it acts: MySQL cannot roll a half-run migration
 * back, and a rerun must not trip over its own first attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_payment_inbox', 'payment_no')) {
            Schema::table('crm_payment_inbox', function (Blueprint $table) {
                $table->string('payment_no', 24)->nullable()->unique();
            });
        }

        $settled = DB::table('crm_payment_inbox as e')
            ->join('crm_invoice_payments as p', 'p.id', '=', 'e.invoice_payment_id')
            ->whereNull('e.payment_no')
            ->whereNotNull('p.payment_no')
            ->get(['e.id', 'p.payment_no']);
        foreach ($settled as $row) {
            DB::table('crm_payment_inbox')->where('id', $row->id)->update(['payment_no' => $row->payment_no]);
        }

        $highest = 0;
        foreach (['crm_payment_inbox', 'crm_invoice_payments'] as $table) {
            foreach (DB::table($table)->where('payment_no', 'like', 'PAY-%')->pluck('payment_no') as $number) {
                $highest = max($highest, (int) substr($number, 4));
            }
        }
        foreach (DB::table('crm_payment_inbox')->whereNull('payment_no')->orderBy('id')->pluck('id') as $id) {
            DB::table('crm_payment_inbox')->where('id', $id)
                ->update(['payment_no' => 'PAY-' . str_pad((string) ++$highest, 6, '0', STR_PAD_LEFT)]);
        }

        if (! Schema::hasColumn('crm_salary_slips', 'reimbursements')) {
            Schema::table('crm_salary_slips', function (Blueprint $table) {
                $table->decimal('reimbursements', 12, 2)->default(0);
                $table->json('reimbursement_lines')->nullable();
            });
        }

        if (! Schema::hasColumn('crm_approvals', 'reimbursed_slip_id')) {
            Schema::table('crm_approvals', function (Blueprint $table) {
                $table->foreignId('reimbursed_slip_id')->nullable()
                    ->constrained('crm_salary_slips')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_approvals', 'reimbursed_slip_id')) {
            Schema::table('crm_approvals', function (Blueprint $table) {
                $table->dropConstrainedForeignId('reimbursed_slip_id');
            });
        }
        if (Schema::hasColumn('crm_salary_slips', 'reimbursements')) {
            Schema::table('crm_salary_slips', function (Blueprint $table) {
                $table->dropColumn(['reimbursements', 'reimbursement_lines']);
            });
        }
        if (Schema::hasColumn('crm_payment_inbox', 'payment_no')) {
            Schema::table('crm_payment_inbox', function (Blueprint $table) {
                $table->dropUnique(['payment_no']);
                $table->dropColumn('payment_no');
            });
        }
    }
};
