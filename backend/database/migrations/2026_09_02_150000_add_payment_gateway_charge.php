<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the gateway kept.
 *
 * A client who pays 11,800 through a payment gateway has discharged 11,800
 * of debt, even though only 10,800 reaches the bank. Recording the 10,800
 * against the invoice would leave a phantom 1,000 owing for ever and would
 * understate the sale; the 1,000 is the company's cost of taking the money,
 * not a discount the client received.
 *
 * So the receipt keeps the gross in `amount` — that is what settles the
 * invoice — and names the deduction beside it. Net to bank is arithmetic,
 * never a second stored number that could disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_invoice_payments', function (Blueprint $table) {
            $table->decimal('charge_amount', 14, 2)->default(0)->after('amount');
            $table->string('charge_note', 191)->nullable()->after('charge_amount');
            // The expense this charge was booked as, so removing the receipt
            // takes the cost with it rather than orphaning it.
            $table->foreignId('charge_expense_id')->nullable()->after('charge_note')
                ->constrained('crm_expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_invoice_payments', function (Blueprint $table) {
            $table->dropForeign(['charge_expense_id']);
            $table->dropColumn(['charge_amount', 'charge_note', 'charge_expense_id']);
        });
    }
};
