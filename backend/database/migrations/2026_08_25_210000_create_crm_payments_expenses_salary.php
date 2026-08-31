<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment inbox, Expenses, Salary slips.
 *
 * The payment inbox mirrors how the old accounts team actually worked: every
 * bank credit is logged first (often before anyone knows whose it is), then
 * "claimed" against an invoice — which here creates a real receipt row on
 * the invoice so the ledger and the inbox can never tell different stories.
 *
 * Expenses are the office spend register with the GST split the old CRM
 * tracked. Salary slips are one row per employee per month, with the bank
 * details snapshotted so an account change later never rewrites old slips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_payment_inbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->date('received_on');
            $table->foreignId('issuing_company_id')->nullable()->constrained('crm_issuing_companies')->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('crm_bank_accounts')->nullOnDelete();
            $table->string('payment_mode', 64)->nullable();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('INR');
            $table->text('details')->nullable();            // the raw bank/PG line
            $table->string('reference_no', 128)->nullable();
            $table->string('status', 16)->default('unclaimed'); // unclaimed | claimed
            $table->foreignId('claimed_invoice_id')->nullable()->constrained('crm_invoices')->nullOnDelete();
            $table->foreignId('invoice_payment_id')->nullable()->constrained('crm_invoice_payments')->nullOnDelete();
            $table->foreignId('claimed_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('claimed_at')->nullable();
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'received_on']);
        });

        Schema::create('crm_expenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->date('expense_date');
            $table->foreignId('issuing_company_id')->nullable()->constrained('crm_issuing_companies')->nullOnDelete();
            $table->string('vendor_name');
            $table->string('vendor_gstin', 32)->nullable();
            $table->string('category', 64)->nullable();
            $table->string('description', 512)->nullable();
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('igst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->boolean('bill_available')->default(false);
            $table->boolean('gst_claimed')->default(false);
            $table->string('payment_mode', 64)->nullable();
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'expense_date']);
            $table->index(['organization_id', 'category']);
        });

        Schema::create('crm_salary_slips', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->decimal('payable', 12, 2)->default(0);
            $table->decimal('additions', 12, 2)->default(0);   // incentives, arrears
            $table->decimal('deductions', 12, 2)->default(0);
            $table->string('deduction_note', 512)->nullable();
            $table->decimal('net_salary', 12, 2)->default(0);
            // Bank snapshot — slips must not change when the account does.
            $table->string('bank_name')->nullable();
            $table->string('account_holder')->nullable();
            $table->string('account_no', 64)->nullable();
            $table->string('ifsc', 32)->nullable();
            $table->string('status', 16)->default('pending'); // pending | paid
            $table->date('paid_on')->nullable();
            $table->string('payment_mode', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'member_id', 'year', 'month']);
            $table->index(['organization_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_salary_slips');
        Schema::dropIfExists('crm_expenses');
        Schema::dropIfExists('crm_payment_inbox');
    }
};
