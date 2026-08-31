<?php

namespace App\Services\Crm;

use App\Models\Crm\Expense;
use App\Models\Crm\InvoicePayment;

/**
 * Booking what the gateway or the bank kept.
 *
 * The charge is a cost of collecting the money, so it belongs in the spend
 * register where the P&L will find it — not netted quietly off a sale. The
 * invoice still shows the client paid in full, because they did.
 *
 * One expense per receipt, created and removed with it, so the two can
 * never drift apart.
 */
class GatewayCharge
{
    /** The spend category these land under. */
    public const CATEGORY = 'Payment Gateway Charges';

    /**
     * Book (or re-book, or clear) the charge on a receipt. Returns the
     * receipt, refreshed.
     */
    public static function apply(InvoicePayment $payment, ?int $byUserId = null): InvoicePayment
    {
        $invoice = $payment->invoice;
        $charge = round((float) $payment->charge_amount, 2);

        // No charge, or no longer one: the cost goes with it.
        if ($charge <= 0) {
            $payment->chargeExpense?->delete();
            $payment->update(['charge_expense_id' => null, 'charge_note' => null]);

            return $payment->fresh();
        }

        $label = $payment->charge_note ?: ($payment->payment_mode
            ? $payment->payment_mode . ' charge'
            : 'Payment gateway charge');

        $values = [
            'organization_id' => $invoice->organization_id,
            'expense_date' => $payment->received_at?->toDateString() ?? now()->toDateString(),
            'issuing_company_id' => $invoice->issuing_company_id,
            'invoice_id' => $invoice->id,
            // A gateway is not a registered vendor — the name is snapshotted
            // exactly as a client commission's payee is.
            'vendor_name' => $label,
            'category' => self::CATEGORY,
            'description' => 'Charge on ' . $invoice->number . ' — client paid '
                . number_format((float) $payment->amount, 2) . ', bank received '
                . number_format($payment->netAmount(), 2),
            'base_amount' => $charge,
            'total_amount' => $charge,
            // The money never sat in the account, so the bill is already settled.
            'amount_paid' => $charge,
            'payment_status' => 'paid',
            'payment_mode' => $payment->payment_mode,
            'created_by' => $byUserId,
        ];

        if ($payment->chargeExpense) {
            $payment->chargeExpense->update($values);
        } else {
            $expense = Expense::create($values);
            $payment->update(['charge_expense_id' => $expense->id]);
        }

        return $payment->fresh();
    }

    /** Remove the receipt's charge along with the receipt itself. */
    public static function release(InvoicePayment $payment): void
    {
        $payment->chargeExpense?->delete();
    }
}
