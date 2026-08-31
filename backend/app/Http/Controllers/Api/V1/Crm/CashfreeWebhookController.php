<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\Crm\PaymentLink;
use App\Services\Crm\CashfreeGateway;
use App\Services\Crm\PaymentSettler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cashfree telling us a link was paid.
 *
 * This is an unauthenticated door, so nothing is believed until the signature
 * over the RAW body checks out against that company's own secret. Each
 * company has its own Cashfree account and therefore its own webhook URL,
 * which is why the organization is in the path.
 *
 * A paid link is settled through exactly the same service an Admin uses, so
 * money that arrives by gateway converts a proforma and marks the invoice
 * paid the same way money that arrives by NEFT does.
 */
class CashfreeWebhookController extends Controller
{
    public function __construct(
        private CashfreeGateway $cashfree,
        private PaymentSettler $settler,
    ) {
    }

    public function handle(Request $request, string $organizationUuid): JsonResponse
    {
        $organization = Organization::where('uuid', $organizationUuid)->first();
        if (! $organization) {
            // Say nothing useful to whoever is guessing.
            return response()->json(['message' => 'Ignored.'], 404);
        }

        $verified = $this->cashfree->verifyWebhook(
            $organization,
            (string) $request->header('x-webhook-timestamp'),
            $request->getContent(),
            $request->header('x-webhook-signature'),
        );

        if (! $verified) {
            return response()->json(['message' => 'Signature mismatch.'], 401);
        }

        $type = (string) $request->input('type');
        $data = (array) $request->input('data', []);
        $linkId = $data['link_id'] ?? null;

        if ($type !== 'PAYMENT_LINK_EVENT' || blank($linkId)) {
            // Some other event of theirs: acknowledged, ignored, not retried.
            return response()->json(['message' => 'Ignored.']);
        }

        $link = PaymentLink::with('invoice.client', 'invoice.convertedTo')
            ->where('organization_id', $organization->id)
            ->where('link_id', $linkId)
            ->first();

        if (! $link) {
            return response()->json(['message' => 'Unknown link.']);
        }

        $status = (string) ($data['link_status'] ?? '');
        $paid = round((float) ($data['link_amount_paid'] ?? 0), 2);

        // Cashfree retries until we answer, and a customer can pay once — so
        // a link already settled is acknowledged, never settled twice.
        if ($link->status === 'paid') {
            return response()->json(['message' => 'Already settled.']);
        }

        $link->fill([
            'amount_paid' => $paid,
            'last_event' => $data,
            'status' => match ($status) {
                'PAID' => 'paid',
                'PARTIALLY_PAID' => 'partially_paid',
                'EXPIRED' => 'expired',
                'CANCELLED' => 'cancelled',
                default => $link->status,
            },
        ]);

        if ($status !== 'PAID') {
            $link->save();

            return response()->json(['message' => 'Recorded.']);
        }

        $link->paid_at = now();
        $link->save();

        $document = $link->invoice;
        if (! $document) {
            return response()->json(['message' => 'Document gone.']);
        }

        // The money exists whoever it came from: it lands in the inbox like
        // any other credit, already matched and already settled.
        $entry = PaymentInboxEntry::create([
            'organization_id' => $organization->id,
            'received_on' => now()->toDateString(),
            'issuing_company_id' => $document->issuing_company_id,
            'payment_mode' => 'Payment Gateway',
            'amount' => $paid > 0 ? $paid : $link->amount,
            'currency' => $link->currency,
            'details' => 'Cashfree link ' . $link->link_id
                . (($data['order_id'] ?? null) ? ' · order ' . $data['order_id'] : ''),
            'reference_no' => $data['transaction_id'] ?? ($data['order_id'] ?? $link->link_id),
            'status' => 'pending',
            'settlement_mode' => 'gateway',
            'claimed_invoice_id' => $document->id,
            'claimed_member_id' => $document->member_id,
            'claimed_at' => now(),
        ]);

        ActivityLog::record(null, $organization->id, 'payment.link_paid', $document, array_filter([
            'number' => $document->number,
            'client' => $document->client?->company_name,
            'amount' => (float) $entry->amount,
            'link_id' => $link->link_id,
            'order_id' => $data['order_id'] ?? null,
        ]));

        // No user pressed anything, so no actor — the trail says as much.
        $this->settler->settle($entry, $document, null, null);

        return response()->json(['message' => 'Settled.']);
    }
}
