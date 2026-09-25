<?php

use App\Models\Crm\Expense;
use Illuminate\Database\Migrations\Migration;

/**
 * The commissions and gateway charges already filed against foreign sales.
 *
 * When bills learnt to hold a currency, every row already in the register
 * was called rupees - which was right for the ones somebody typed, and
 * wrong for the ones the app itself had made. A commission off a $1,200
 * invoice, and the gateway's cut of a $600 payment, were in dollars the
 * day they were written; nobody chose rupees for them, the column simply
 * had not existed yet. A ₹27 charge on a USD invoice is not a small
 * rounding matter either - read as rupees it is a fiftieth of its cost.
 *
 * So a cost tied to a document takes that document's currency and its
 * frozen rate, exactly as a new one now does. Bills with no document
 * behind them are left alone: they were entered by hand, and a person
 * entering a figure into a rupee register meant rupees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Expense::query()
            ->whereNotNull('invoice_id')
            ->with('invoice:id,currency,fx_currency,fx_rate,total,total_fx')
            ->chunkById(200, function ($expenses) {
                foreach ($expenses as $expense) {
                    $currency = strtoupper((string) ($expense->invoice?->currency ?: 'INR'));
                    if ($currency === 'INR' || $currency === strtoupper((string) $expense->currency)) {
                        continue;
                    }

                    $rate = $expense->invoice->rupeeRate();

                    $expense->forceFill([
                        'currency' => $currency,
                        'fx_rate' => $rate > 0 ? $rate : null,
                        'total_inr' => round((float) $expense->total_amount * ($rate ?: 1), 2),
                    ])->saveQuietly();
                }
            });
    }

    /**
     * Back to rupees, which is the only thing the column said before.
     *
     * Nothing else can be restored - there was no record of what these
     * were in, which is the whole reason this migration exists.
     */
    public function down(): void
    {
        Expense::query()
            ->whereNotNull('invoice_id')
            ->where('currency', '!=', 'INR')
            ->chunkById(200, function ($expenses) {
                foreach ($expenses as $expense) {
                    $expense->forceFill([
                        'currency' => 'INR',
                        'fx_rate' => null,
                        'total_inr' => $expense->total_amount,
                    ])->saveQuietly();
                }
            });
    }
};
