<?php

namespace App\Services\Crm;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turning money that has landed into a receipt.
 *
 * Three doors lead here — an Admin settling a matched credit, a claim the
 * company settles on the spot, and a payment gateway telling us a link was
 * paid — and all three must produce the same books, so the work lives once.
 *
 * If the money came in against a proforma, as most of it does, that proforma
 * becomes a tax invoice first and the receipt is written on the invoice.
 */
class PaymentSettler
{
    public function __construct(private InvoiceConverter $converter)
    {
    }

    /**
     * @param  User|null  $user  whoever pressed the button; null for a gateway
     * @param  Member|null  $actor  their CRM membership, for the trail
     * @param  float  $charge  what the gateway or bank kept out of the payment
     *                         before the credit landed. The bank line is the
     *                         NET, so the client's gross is net + charge —
     *                         which is what actually settles the invoice.
     */
    public function settle(
        PaymentInboxEntry $entry,
        Invoice $document,
        ?User $user,
        ?Member $actor,
        float $charge = 0.0,
        ?string $chargeNote = null,
    ): Invoice {
        if ($document->status === 'cancelled') {
            abort(422, $document->number . ' is cancelled.');
        }

        $proforma = null;
        $invoice = $document;

        if ($document->kind === 'proforma') {
            $proforma = $document;
            $invoice = $document->convertedTo
                ?? $this->converter->convert($document, $user, $actor);
        }

        $charge = round(max(0.0, $charge), 2);

        DB::transaction(function () use ($entry, $invoice, $proforma, $user, $charge, $chargeNote) {
            $payment = $invoice->payments()->create([
                // The bank line is what was left after the charge, so the
                // client's payment was that much larger.
                'amount' => round((float) $entry->amount + $charge, 2),
                'charge_amount' => $charge,
                'charge_note' => $charge > 0 ? $chargeNote : null,
                'bank_account_id' => $entry->bank_account_id,
                'payment_mode' => $entry->payment_mode,
                'reference_no' => $entry->reference_no,
                'received_at' => $entry->received_on->toDateString(),
                'note' => $proforma
                    ? 'Claimed from payment inbox against ' . $proforma->number
                    : 'Claimed from payment inbox',
                'created_by' => $user?->id,
            ]);

            if ($charge > 0) {
                GatewayCharge::apply($payment->load('invoice'), $user?->id);
            }

            $entry->update([
                'status' => 'claimed',
                'claimed_invoice_id' => $invoice->id,
                'invoice_payment_id' => $payment->id,
                'source_proforma_id' => $proforma?->id,
                'settled_by' => $user?->id,
                'settled_at' => now(),
            ]);
        });

        $invoice->refreshPaymentStatus();

        ActivityLog::record($actor, $invoice->organization_id, 'payment.settled', $invoice, array_filter([
            'number' => $invoice->number,
            'client' => $invoice->client?->company_name,
            'amount' => round((float) $entry->amount + $charge, 2),
            'charge' => $charge > 0 ? $charge : null,
            'mode' => $entry->settlement_mode,
            'from_proforma' => $proforma?->number,
            'payment_status' => $invoice->fresh()->payment_status,
            'by' => $actor?->user?->name ?? ($entry->settlement_mode === 'gateway' ? 'Payment gateway' : null),
        ]));

        return $invoice;
    }
}
