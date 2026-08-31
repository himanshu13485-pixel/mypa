<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\PaymentInboxEntry;
use App\Notifications\CrmNotification;
use App\Services\Crm\PaymentSettler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * The payment inbox: every credit that lands in a bank account is logged
 * here first, whoever it belongs to. Claiming an entry allocates it to an
 * invoice — which creates the actual receipt row on that invoice inside the
 * same transaction, so the inbox and the invoice ledger can never disagree
 * (the old CRM kept them apart and reconciliation was a monthly hunt).
 */
class PaymentInboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $query = PaymentInboxEntry::with([
            'issuingCompany:id,name', 'bankAccount:id,label',
            'claimedInvoice:id,uuid,number,kind', 'claimedMember.user:id,name',
            'sourceProforma:id,number',
        ])->where('organization_id', $org->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($bank = $request->query('bank_account_id')) {
            $query->where('bank_account_id', $bank);
        }
        if ($company = $request->query('issuing_company_id')) {
            $query->where('issuing_company_id', $company);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('received_on', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('received_on', '<=', $to);
        }
        // Whose money: the salesperson the credit was claimed for.
        if ($member = $request->query('member')) {
            $query->whereHas('claimedMember', fn ($m) => $m->where('uuid', $member));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('details', 'like', "%{$search}%")
                    ->orWhere('reference_no', 'like', "%{$search}%")
                    ->orWhereHas('claimedInvoice', fn ($i) => $i->where('number', 'like', "%{$search}%"));
            });
        }

        $all = (clone $query)->get(['id', 'status', 'amount', 'payment_mode', 'received_on']);
        $summary = [
            'unclaimed_count' => $all->where('status', 'unclaimed')->count(),
            'pending_count' => $all->where('status', 'pending')->count(),
            'pending_amount' => round($all->where('status', 'pending')->sum('amount'), 2),
            // What this company does when a payment is matched.
            'settlement_mode' => $org->settlementMode(),
            'unclaimed_amount' => round($all->where('status', 'unclaimed')->sum('amount'), 2),
            'claimed_amount' => round($all->where('status', 'claimed')->sum('amount'), 2),
            'total_amount' => round($all->sum('amount'), 2),
            'by_mode' => $all->groupBy(fn ($e) => $e->payment_mode ?: 'Unspecified')
                ->map(fn ($g, $mode) => ['mode' => $mode, 'amount' => round($g->sum('amount'), 2), 'count' => $g->count()])
                ->sortByDesc('amount')->values(),
            'by_month' => $all->groupBy(fn ($e) => $e->received_on->format('Y-m'))
                ->map(fn ($g, $m) => ['month' => $m, 'amount' => round($g->sum('amount'), 2)])
                ->sortKeys()->values()->take(-12),
        ];

        $entries = $query->orderByDesc('received_on')->orderByDesc('id')->paginate(25);
        $entries->getCollection()->transform(fn ($e) => $this->serialize($e));

        return response()->json(['summary' => $summary] + $entries->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $entry = PaymentInboxEntry::create($this->validateEntry($request, $org->id) + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Payment logged.', 'data' => $this->serialize($entry)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $entry = $this->find($request, $uuid);
        if ($entry->status === 'claimed') {
            abort(422, 'A claimed payment is frozen — unclaim it first.');
        }

        $entry->update($this->validateEntry($request, $entry->organization_id));

        return response()->json(['message' => 'Payment updated.', 'data' => $this->serialize($entry->fresh()->load(['issuingCompany:id,name', 'bankAccount:id,label']))]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $entry = $this->find($request, $uuid);
        if ($entry->status === 'claimed') {
            abort(422, 'Unclaim this payment before deleting it.');
        }

        $entry->delete();

        return response()->json(['message' => 'Payment removed.']);
    }

    /**
     * Point an inbox entry at a document.
     *
     * Money usually arrives against a proforma, so the target may be either
     * kind. What happens next is the company's rule: settle it on the spot,
     * or leave it for an Admin to check first. Whoever is not an Admin can
     * only ever propose — that is the point of the check.
     */
    public function claim(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $entry = $this->find($request, $uuid);

        if ($entry->status === 'claimed') {
            abort(422, 'This payment is already settled. Use Change if it went to the wrong document.');
        }

        $data = $request->validate([
            'invoice_uuid' => ['required', 'string'],
            'member_uuid' => ['nullable', 'string'],
            'mode' => ['nullable', 'in:auto,manual'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $document = $this->document($org->id, $data['invoice_uuid']);
        $member = ! empty($data['member_uuid'])
            ? Member::where('organization_id', $org->id)->where('uuid', $data['member_uuid'])->firstOrFail()
            : null;

        // The company's own default, unless this claim says otherwise — and
        // only an Admin or Subadmin may settle without a second pair of eyes.
        $mode = $data['mode'] ?? $org->settlementMode();
        if (! $this->isManager($me)) {
            $mode = 'manual';
        }

        $entry->fill([
            'settlement_mode' => $mode,
            'claimed_invoice_id' => $document->id,
            'claimed_member_id' => $member?->id ?? $document->member_id,
            'claimed_by' => $request->user()->id,
            'claimed_at' => now(),
            'note' => $data['note'] ?? $entry->note,
        ]);

        if ($mode === 'manual') {
            $entry->status = 'pending';
            $entry->save();

            ActivityLog::record($me, $org->id, 'payment.claim_proposed', $document, [
                'number' => $document->number,
                'client' => $document->client?->company_name,
                'amount' => (float) $entry->amount,
                'by' => $me->user?->name,
            ]);

            Notification::send(
                Member::deciders($org->id, 'payments', $me->id),
                new CrmNotification(
                    'crm_payment',
                    ($me->user?->name ?? 'Someone') . ' matched ' . $this->money($entry)
                        . ' to ' . $document->number . ' — please check and settle it.',
                    '/crm/payments',
                ),
            );

            return response()->json([
                'message' => 'Sent for confirmation — an Admin will settle it against ' . $document->number . '.',
                'data' => $this->serialize($entry->fresh()->load(['claimedInvoice:id,uuid,number,kind', 'claimedMember.user:id,name'])),
            ]);
        }

        $entry->save();
        $invoice = $this->settleEntry($entry, $document, $request, $me);

        return response()->json([
            'message' => $this->settledMessage($entry, $document, $invoice),
            'data' => $this->serialize($entry->fresh()->load(['claimedInvoice:id,uuid,number,kind', 'claimedMember.user:id,name'])),
        ]);
    }

    /**
     * An Admin checks a proposed claim and settles it. This is the moment the
     * money becomes a receipt — and, if it came in against a proforma, the
     * moment that proforma becomes a tax invoice.
     */
    public function settle(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $entry = $this->find($request, $uuid);

        if (! $me->allows('payments.settle')) {
            abort(403, 'Settling a payment is the Company Admin’s, or an employee they grant it to.');
        }
        if ($entry->status !== 'pending') {
            abort(422, $entry->status === 'claimed'
                ? 'This payment is already settled.'
                : 'Match this payment to a document first.');
        }

        $document = $entry->claimedInvoice;
        if (! $document) {
            abort(422, 'The document this payment was matched to is gone. Match it again.');
        }

        $invoice = $this->settleEntry($entry, $document, $request, $me);

        return response()->json([
            'message' => $this->settledMessage($entry, $document, $invoice),
            'data' => $this->serialize($entry->fresh()->load(['claimedInvoice:id,uuid,number,kind', 'claimedMember.user:id,name'])),
        ]);
    }

    /**
     * Money on the wrong document. The old receipt is reversed and a new one
     * written in the same breath, so the books are never briefly wrong.
     */
    public function reclaim(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $entry = $this->find($request, $uuid);

        if (! $me->allows('payments.settle')) {
            abort(403, 'Moving a settled payment is the Company Admin’s, or an employee they grant it to.');
        }
        if (! in_array($entry->status, ['claimed', 'pending'], true)) {
            abort(422, 'This payment is not matched to anything yet.');
        }

        $data = $request->validate([
            'invoice_uuid' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:512'],
        ]);

        $was = $entry->claimedInvoice;
        $document = $this->document($org->id, $data['invoice_uuid']);

        if ($was && $was->id === $document->id) {
            abort(422, 'That is the same document it is already on.');
        }

        $wasSettled = $entry->status === 'claimed';

        DB::transaction(function () use ($entry) {
            // Reverse first: one receipt, one payment, always.
            $entry->invoicePayment?->delete();
            $entry->update(['invoice_payment_id' => null]);
        });
        $was?->refreshPaymentStatus();

        $entry->fill([
            'claimed_invoice_id' => $document->id,
            'claimed_member_id' => $document->member_id,
            'source_proforma_id' => null,
        ])->save();

        ActivityLog::record($me, $org->id, 'payment.reclaimed', $document, array_filter([
            'number' => $document->number,
            'from' => $was?->number,
            'amount' => (float) $entry->amount,
            'reason' => $data['reason'] ?? null,
            'by' => $me->user?->name,
        ]));

        if (! $wasSettled) {
            $entry->save();

            return response()->json([
                'message' => 'Moved to ' . $document->number . ' — still waiting to be settled.',
                'data' => $this->serialize($entry->fresh()->load(['claimedInvoice:id,uuid,number,kind', 'claimedMember.user:id,name'])),
            ]);
        }

        $invoice = $this->settleEntry($entry, $document, $request, $me);

        return response()->json([
            'message' => 'Moved from ' . ($was?->number ?? 'the old document') . ' to ' . $invoice->number . '.',
            'data' => $this->serialize($entry->fresh()->load(['claimedInvoice:id,uuid,number,kind', 'claimedMember.user:id,name'])),
        ]);
    }

    /**
     * Undo: a settled entry gives up its receipt, a proposed one is simply
     * withdrawn. Either way the entry goes back to the inbox.
     */
    public function unclaim(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $entry = $this->find($request, $uuid);

        if (! in_array($entry->status, ['claimed', 'pending'], true)) {
            abort(422, 'This payment is not claimed.');
        }
        if ($entry->status === 'claimed' && ! $me->allows('payments.settle')) {
            abort(403, 'Undoing a settled payment is the Company Admin’s, or an employee they grant it to.');
        }

        $invoice = $entry->claimedInvoice;
        $wasSettled = $entry->status === 'claimed';

        DB::transaction(function () use ($entry) {
            $entry->invoicePayment?->delete();
            $entry->update([
                'status' => 'unclaimed',
                'settlement_mode' => null,
                'claimed_invoice_id' => null,
                'invoice_payment_id' => null,
                'claimed_member_id' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'settled_by' => null,
                'settled_at' => null,
                'source_proforma_id' => null,
            ]);
        });

        $invoice?->refreshPaymentStatus();

        if ($invoice) {
            ActivityLog::record($me, $org->id, $wasSettled ? 'payment.unsettled' : 'payment.claim_withdrawn',
                $invoice, array_filter([
                    'number' => $invoice->number,
                    'amount' => (float) $entry->amount,
                    'by' => $me?->user?->name,
                ]));
        }

        return response()->json([
            'message' => $wasSettled
                ? 'Settlement undone — the receipt was removed from ' . ($invoice?->number ?? 'the invoice') . '.'
                : 'Withdrawn — the payment is back in the inbox.',
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    /** The work itself lives in PaymentSettler — a gateway uses it too. */
    private function settleEntry(PaymentInboxEntry $entry, Invoice $document, Request $request, ?Member $me): Invoice
    {
        // A bank credit is what was LEFT after the gateway took its cut, so
        // the charge has to be named here for the invoice to come out square.
        $charge = $request->validate([
            'charge_amount' => ['nullable', 'numeric', 'min:0'],
            'charge_note' => ['nullable', 'string', 'max:191'],
        ]);

        return app(PaymentSettler::class)->settle(
            $entry,
            $document,
            $request->user(),
            $me,
            (float) ($charge['charge_amount'] ?? 0),
            $charge['charge_note'] ?? null,
        );
    }

    /** Either kind: money usually arrives against a proforma. */
    private function document(int $orgId, string $uuid): Invoice
    {
        return Invoice::with('client:id,company_name', 'convertedTo')
            ->where('organization_id', $orgId)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function isManager(?Member $member): bool
    {
        return $member !== null && in_array($member->crm_role, ['admin', 'subadmin'], true);
    }

    private function money(PaymentInboxEntry $entry): string
    {
        return ($entry->currency ?: 'INR') . ' ' . number_format((float) $entry->amount, 2);
    }

    private function settledMessage(PaymentInboxEntry $entry, Invoice $document, Invoice $invoice): string
    {
        $status = $invoice->fresh()->payment_status;

        return $document->id === $invoice->id
            ? 'Settled against ' . $invoice->number . ' — it is now ' . $status . '.'
            : $document->number . ' became ' . $invoice->number . ', settled and now ' . $status . '.';
    }


    private function find(Request $request, string $uuid): PaymentInboxEntry
    {
        return PaymentInboxEntry::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function validateEntry(Request $request, int $orgId): array
    {
        return $request->validate([
            'received_on' => ['required', 'date'],
            'issuing_company_id' => ['nullable', Rule::exists('crm_issuing_companies', 'id')->where('organization_id', $orgId)],
            'bank_account_id' => ['nullable', Rule::exists('crm_bank_accounts', 'id')->where('organization_id', $orgId)],
            'payment_mode' => ['nullable', 'string', 'max:64'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'details' => ['nullable', 'string', 'max:5000'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);
    }

    private function serialize(PaymentInboxEntry $e): array
    {
        return [
            'uuid' => $e->uuid,
            'received_on' => $e->received_on->toDateString(),
            'issuing_company' => $e->issuingCompany?->name,
            'issuing_company_id' => $e->issuing_company_id,
            'bank_account' => $e->bankAccount?->label,
            'bank_account_id' => $e->bank_account_id,
            'payment_mode' => $e->payment_mode,
            'amount' => $e->amount,
            'currency' => $e->currency,
            'details' => $e->details,
            'reference_no' => $e->reference_no,
            'status' => $e->status,
            'settlement_mode' => $e->settlement_mode,
            'claimed_invoice' => $e->claimedInvoice ? [
                'uuid' => $e->claimedInvoice->uuid,
                'number' => $e->claimedInvoice->number,
                'kind' => $e->claimedInvoice->kind,
            ] : null,
            'claimed_member_uuid' => $e->claimedMember?->uuid,
            'claimed_member' => $e->claimedMember?->user?->name,
            'claimed_at' => $e->claimed_at?->toDateTimeString(),
            'settled_at' => $e->settled_at?->toDateTimeString(),
            // Kept so the trail can read "paid against PI-7, invoiced as INV-12".
            'from_proforma' => $e->sourceProforma?->number,
            'note' => $e->note,
        ];
    }
}
