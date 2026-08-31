<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holds and cancellations on a spread incentive.
 *
 * A spread plan pays each sale's incentive in monthly installments computed
 * live from the ledger. Sometimes a specific client's installments must
 * stop — the client is disputing, the money is uncertain — without touching
 * anything else the employee earns. That exception is state, and this is
 * where it lives:
 *
 *   hold, one month     — skip that installment; it pays the NEXT month
 *                         automatically, marked as an arrear release
 *   hold, all remaining — installments stop and pile up; releasing the hold
 *                         pays everything withheld as one arrear and lets
 *                         the schedule continue
 *   cancel              — remaining installments stop for good; regaining
 *                         resumes FUTURE months, but the months spent
 *                         cancelled are gone
 *
 * Months are 'YYYY-MM' strings naming the EARNED month an installment
 * belongs to (the anchor), not the payroll month it lands in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_incentive_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->string('kind', 16);                    // hold | cancel
            $table->string('from_month', 7);               // first anchor month affected
            // Set on a one-month hold; null means "and everything after".
            $table->string('only_month', 7)->nullable();
            $table->string('status', 16)->default('active');   // active | released
            // The anchor month from which withheld installments pay out.
            $table->string('released_month', 7)->nullable();
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'member_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_incentive_holds');
    }
};
