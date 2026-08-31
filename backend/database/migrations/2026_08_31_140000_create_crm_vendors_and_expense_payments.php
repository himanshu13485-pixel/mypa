<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vendors, and money owed to them.
 *
 * Spending used to be typed as a loose vendor name on every bill, so the
 * same supplier arrived spelled three ways and nobody could say what the
 * company owed them. A vendor is now registered once — exactly as a client
 * is — and a bill points at that record.
 *
 * A bill is also no longer assumed to be settled the moment it is entered:
 * it carries what has actually been paid against it, so a balance and a due
 * date are real. Payments are separate rows, the same shape as receipts on
 * an invoice, so a wrong entry is removed rather than edited over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_vendors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('company_name');
            $table->string('contact_person')->nullable();
            $table->string('designation', 128)->nullable();
            $table->string('address', 512)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('pincode', 16)->nullable();
            $table->string('country', 128)->nullable();
            $table->string('telephone', 64)->nullable();
            $table->string('mobile', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('gst_no', 32)->nullable();
            $table->string('pan_no', 16)->nullable();
            // What the company buys from them, and how quickly it must pay.
            $table->string('category', 64)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            // Where the money goes — kept here so a payment run needs no
            // hunting through emails.
            $table->string('bank_name', 128)->nullable();
            $table->string('bank_account_no', 64)->nullable();
            $table->string('bank_ifsc', 32)->nullable();
            $table->string('bank_branch', 128)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('notes', 1024)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'company_name']);
        });

        Schema::table('crm_expenses', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('issuing_company_id')
                ->constrained('crm_vendors')->nullOnDelete();
            // When the bill falls due, and how much of it has gone out.
            $table->date('due_date')->nullable()->after('expense_date');
            $table->decimal('amount_paid', 14, 2)->default(0)->after('total_amount');
            $table->string('payment_status', 16)->default('unpaid')->after('amount_paid');
            $table->index(['organization_id', 'payment_status']);
        });

        Schema::create('crm_expense_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('expense_id')->constrained('crm_expenses')->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->string('payment_mode', 64)->nullable();
            $table->string('reference_no', 128)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('crm_bank_accounts')->nullOnDelete();
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['expense_id', 'paid_on']);
        });

        $this->backfill();
    }

    /**
     * Every supplier already named on a bill becomes a registered vendor, so
     * the register is not empty on the first morning. Commission payouts are
     * left alone — the payee there is a client, not a supplier.
     */
    private function backfill(): void
    {
        $rows = DB::table('crm_expenses')
            ->select('organization_id', 'vendor_name')
            ->selectRaw('max(vendor_gstin) as vendor_gstin, max(created_by) as created_by')
            ->whereNotNull('vendor_name')
            ->where('vendor_name', '!=', '')
            ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'Client Commission'))
            ->groupBy('organization_id', 'vendor_name')
            ->get();

        foreach ($rows as $row) {
            $vendorId = DB::table('crm_vendors')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'organization_id' => $row->organization_id,
                'company_name' => $row->vendor_name,
                'gst_no' => $row->vendor_gstin,
                'status' => 'active',
                'created_by' => $row->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('crm_expenses')
                ->where('organization_id', $row->organization_id)
                ->where('vendor_name', $row->vendor_name)
                ->where(fn ($q) => $q->whereNull('category')->orWhere('category', '!=', 'Client Commission'))
                ->update(['vendor_id' => $vendorId]);
        }

        // The old register had no notion of an unpaid bill — everything in it
        // was money already spent, so it starts life settled.
        DB::table('crm_expenses')->update([
            'amount_paid' => DB::raw('total_amount'),
            'payment_status' => 'paid',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_expense_payments');
        Schema::table('crm_expenses', function (Blueprint $table) {
            $table->dropForeign(['vendor_id']);
            $table->dropIndex(['organization_id', 'payment_status']);
            $table->dropColumn(['vendor_id', 'due_date', 'amount_paid', 'payment_status']);
        });
        Schema::dropIfExists('crm_vendors');
    }
};
