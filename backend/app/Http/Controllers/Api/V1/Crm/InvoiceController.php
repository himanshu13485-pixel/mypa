<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\BankAccount;
use App\Models\Crm\Client;
use App\Models\Crm\CustomField;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\PaymentInboxEntry;
use App\Services\Crm\GatewayCharge;
use App\Services\Crm\InvoiceConverter;
use App\Support\TextCase;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Proforma and tax invoices share one engine. Numbers come from the issuing
 * company's own series; totals are recomputed server-side from the items so
 * a tampered client can never make the ledger disagree with its lines.
 */
class InvoiceController extends Controller
{
    /** Which stored columns each switchable header field owns. */
    private const DOCUMENT_ATTRIBUTES = [
        'due_date' => ['due_date'],
        'client_category' => ['client_category'],
        'pricing_tier' => ['pricing_tier'],
        'terms_of_payment' => ['terms_of_payment'],
        'subscription_type' => ['subscription_type'],
        'dispatch_status' => ['dispatch_status'],
        'fx' => ['fx_currency', 'fx_rate'],
        'notes' => ['notes'],
    ];

    /**
     * The right that governs one kind of document.
     *
     * A proforma is a quote and a tax invoice is a demand for money, and the
     * two are now separate rights — a junior can be trusted with the first
     * and not the second, which one shared 'invoices' right could not say.
     *
     * The check lives here rather than in the route file because the kind is
     * only knowable once the request is in hand: a query parameter on a list,
     * a column on a row. Every method that touches a document opens with it.
     */
    protected function forKind(Request $request, string $kind, string $ability): void
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        /*
         * Both slugs written out rather than resolved into a variable.
         *
         * CrmModuleRightsTest walks the module list and fails on any right
         * nothing asks for, by looking for exactly this call. A slug held in
         * a variable is invisible to that check — and the check is worth more
         * than the line it saves, because what it prevents is a checkbox that
         * grants nothing while looking like it granted something.
         */
        $allowed = $kind === 'proforma'
            ? $me?->can('proforma', $ability)
            : $me?->can('invoices', $ability);

        abort_unless($allowed, 403, $kind === 'proforma'
            ? 'You do not have rights to proformas.'
            : 'You do not have rights to invoices.');
    }

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        // Which list this is decides which right it needs.
        $this->forKind($request, (string) $request->query('kind', 'invoice'), 'view');

        $query = Invoice::with(['client:id,uuid,company_name,contact_person', 'issuingCompany:id,name', 'member.user:id,name,email'])
            ->where('organization_id', $org->id)
            // Own ledger only, unless you run the company.
            ->visibleTo($me)
            ->where('kind', $request->query('kind', 'invoice'))
            // A proforma row shows either its invoice or a Convert button,
            // so the list has to know which of the two it is.
            ->when($request->query('kind') === 'proforma',
                fn ($q) => $q->with('convertedTo:id,uuid,number,converted_from_id'));

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('company_name', 'like', "%{$search}%"));
            });
        }
        if ($status = $request->query('payment_status')) {
            $query->where('payment_status', $status);
        }
        if ($dispatch = $request->query('dispatch_status')) {
            $query->where('dispatch_status', $dispatch);
        }
        // GST-wise: documents carrying any GST, none, IGST ones, or the
        // CGST+SGST pair — the splits an accountant actually filters by.
        if ($gst = $request->query('gst')) {
            match ($gst) {
                'with' => $query->whereRaw('(cgst + sgst + igst) > 0'),
                'without' => $query->whereRaw('(cgst + sgst + igst) <= 0'),
                'igst' => $query->where('igst', '>', 0),
                'cgst_sgst' => $query->where(fn ($w) => $w->where('cgst', '>', 0)->orWhere('sgst', '>', 0)),
                default => null,
            };
        }
        // TDS-wise: where the client deducted, and where they did not.
        if ($tds = $request->query('tds')) {
            $tds === 'with' ? $query->where('tds', '>', 0) : $query->where('tds', '<=', 0);
        }
        // Due-amount-wise: what is still owed, optionally within a band.
        $balanceSql = '(total - coalesce((select sum(amount) from crm_invoice_payments'
            . ' where crm_invoice_payments.invoice_id = crm_invoices.id), 0))';
        if ($request->boolean('due_only')) {
            $query->whereRaw($balanceSql . ' > 0.009')->where('status', '!=', 'cancelled');
        }
        if (($dueMin = $request->query('due_min')) !== null && $dueMin !== '') {
            $query->whereRaw($balanceSql . ' >= (? + 0)', [(float) $dueMin]);
        }
        if (($dueMax = $request->query('due_max')) !== null && $dueMax !== '') {
            $query->whereRaw($balanceSql . ' <= (? + 0)', [(float) $dueMax]);
        }
        if ($company = $request->query('issuing_company_id')) {
            $query->where('issuing_company_id', $company);
        }
        if ($client = $request->query('client')) {
            $query->whereHas('client', fn ($c) => $c->where('uuid', $client));
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('invoice_date', '<=', $to);
        }

        // A Team Head holds two ledgers: their own sales, and the team's.
        // The window above says what they MAY see; the scope says which of
        // the two this screen is showing, so the figures never blur.
        $scope = $request->query('scope') === 'mine' ? 'mine' : 'team';
        if ($scope === 'mine') {
            $query->where(fn ($q) => $q->where('member_id', $me->id)
                ->orWhere(fn ($w) => $w->whereNull('member_id')->where('created_by', $me->user_id)));
        }

        // In the combined view, say whose money is whose — computed BEFORE
        // the per-person filter, so the cards always show the whole ledger
        // and picking one person never makes the others vanish.
        $bySalesperson = null;
        if ($scope === 'team') {
            $bySalesperson = (clone $query)
                ->where('status', '!=', 'cancelled')
                ->with('member.user:id,name,email')
                ->withSum('payments as received', 'amount')
                ->get(['id', 'member_id', 'total'])
                ->groupBy('member_id')
                ->map(fn ($group) => [
                    'uuid' => $group->first()->member?->uuid,
                    'name' => $group->first()->member?->user?->name ?? 'Unassigned',
                    'is_me' => $group->first()->member_id === $me->id,
                    'count' => $group->count(),
                    'total' => round((float) $group->sum('total'), 2),
                    // What of it is still owed — sales and dues, side by side.
                    'due' => round((float) $group->sum(fn ($i) => max(0, (float) $i->total - (float) ($i->received ?? 0))), 2),
                ])
                ->sortByDesc('total')
                ->values();
        }

        // One person's rows out of the combined view. The window is already
        // applied, so this can only narrow, never reach.
        if ($salesperson = $request->query('salesperson')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $salesperson));
        }

        // The consolidated figures for exactly what the filters selected —
        // the block the foot of the list shows.
        $live = (clone $query)->where('status', '!=', 'cancelled')
            ->withSum('payments as received', 'amount')
            ->withSum('payments as charges', 'charge_amount')
            ->get(['id', 'subtotal', 'discount', 'cgst', 'sgst', 'igst', 'other_tax', 'tds', 'total']);
        $consolidated = [
            'basic' => round((float) $live->sum(fn ($i) => (float) $i->subtotal - (float) ($i->discount ?? 0)), 2),
            'cgst' => round((float) $live->sum('cgst'), 2),
            'sgst' => round((float) $live->sum('sgst'), 2),
            'igst' => round((float) $live->sum('igst'), 2),
            'gst_total' => round((float) $live->sum(fn ($i) => (float) $i->cgst + (float) $i->sgst + (float) $i->igst), 2),
            'other_tax' => round((float) $live->sum('other_tax'), 2),
            'tds' => round((float) $live->sum('tds'), 2),
            'total' => round((float) $live->sum('total'), 2),
            'received' => round((float) $live->sum(fn ($i) => (float) ($i->received ?? 0)), 2),
            'charges' => round((float) $live->sum(fn ($i) => (float) ($i->charges ?? 0)), 2),
            'due' => round((float) $live->sum(fn ($i) => max(0, (float) $i->total - (float) ($i->received ?? 0))), 2),
        ];

        $totals = array_filter([
            'count' => (clone $query)->count(),
            'total' => $consolidated['total'],
            'due' => $consolidated['due'],
            'consolidated' => $consolidated,
            'scope' => $scope,
            'by_salesperson' => $bySalesperson,
        ], fn ($v) => $v !== null);

        $invoices = $query->orderByDesc('invoice_date')->orderByDesc('id')->paginate(25);
        $invoices->getCollection()->transform(fn ($i) => $this->serialize($i));

        return response()->json(['totals' => $totals] + $invoices->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        [$data, $items, $taxLines] = $this->validatePayload($request, $org->id);

        // Validated first, so the kind being raised is the real one rather
        // than whatever arrived in the body.
        $this->forKind($request, (string) $data['kind'], 'create');

        $invoice = DB::transaction(function () use ($org, $data, $items, $taxLines, $request) {
            $company = IssuingCompany::where('organization_id', $org->id)->findOrFail($data['issuing_company_id']);
            $number = $company->claimNumber($data['kind']);

            $invoice = Invoice::create($data + [
                'organization_id' => $org->id,
                'number' => $number,
                // Attribution outlives ownership: whoever raised the document
                // stays on it even after the client moves to someone else.
                'member_id' => $request->attributes->get('crm_member')?->id,
                'created_by' => $request->user()->id,
            ]);
            $this->syncItems($invoice, $items);
            $this->syncTaxes($invoice, $taxLines);

            return $invoice;
        });

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, $invoice->kind . '.created', $invoice, $this->trail($invoice));

        return response()->json([
            'message' => ($invoice->kind === 'proforma' ? 'Proforma invoice ' : 'Invoice ') . $invoice->number . ' created.',
            'data' => $this->serialize($invoice->fresh()->load(['client', 'issuingCompany', 'items', 'taxes', 'member.user:id,name,email'])),
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $invoice = $this->find($request, $uuid)->load([
            'client', 'issuingCompany', 'items', 'taxes', 'payments.bankAccount:id,label',
            'member.user:id,name,email', 'convertedFrom:id,uuid,number', 'convertedTo:id,uuid,number,converted_from_id',
        ]);
        $this->forKind($request, $invoice->kind, 'view');

        return response()->json(['data' => $this->serialize($invoice, full: true)]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $invoice = $this->find($request, $uuid);
        $this->forKind($request, $invoice->kind, 'edit');

        if ($invoice->status === 'cancelled') {
            abort(422, 'A cancelled document cannot be edited.');
        }
        if ($invoice->kind === 'proforma' && $invoice->convertedTo()->exists()) {
            abort(422, 'This proforma was already converted; edit the tax invoice instead.');
        }

        [$data, $items, $taxLines] = $this->validatePayload($request, $org->id, updating: true);
        unset($data['kind'], $data['issuing_company_id']); // series identity never changes after creation

        DB::transaction(function () use ($invoice, $data, $items, $taxLines, $request) {
            $invoice->update($data + ['updated_by' => $request->user()->id]);
            $this->syncItems($invoice, $items);
            $this->syncTaxes($invoice, $taxLines);
        });

        $invoice->refreshPaymentStatus();
        ActivityLog::record($request->attributes->get('crm_member'), $org->id, $invoice->kind . '.updated', $invoice, $this->trail($invoice));

        return response()->json([
            'message' => 'Saved.',
            'data' => $this->serialize($invoice->fresh()->load(['client', 'issuingCompany', 'items', 'taxes', 'member.user:id,name,email'])),
        ]);
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $invoice = $this->find($request, $uuid);
        $this->forKind($request, $invoice->kind, 'delete');

        if ($invoice->payments()->exists()) {
            abort(422, 'Payments are recorded against this document; remove them before cancelling.');
        }

        $invoice->update(['status' => 'cancelled', 'updated_by' => $request->user()->id]);
        ActivityLog::record($request->attributes->get('crm_member'), $invoice->organization_id, $invoice->kind . '.cancelled', $invoice, $this->trail($invoice));

        return response()->json(['message' => $invoice->number . ' cancelled.']);
    }

    /** Proforma → tax invoice. The work itself lives in InvoiceConverter,
     *  because settling a payment against a proforma converts it too. */
    public function convert(Request $request, string $uuid, InvoiceConverter $converter): JsonResponse
    {
        $proforma = $this->find($request, $uuid);

        /*
         * Both rights, because this reads a quote and raises a bill.
         * Somebody trusted only with proformas must not be able to turn one
         * into a tax invoice, which is the whole distinction the split exists
         * to draw.
         */
        $this->forKind($request, 'proforma', 'view');
        $this->forKind($request, 'invoice', 'create');

        $invoice = $converter->convert($proforma, $request->user(), $request->attributes->get('crm_member'));

        return response()->json([
            'message' => $proforma->number . ' converted to ' . $invoice->number . '.',
            'data' => ['uuid' => $invoice->uuid, 'number' => $invoice->number],
        ], 201);
    }

    // ---- Payments ----------------------------------------------------------

    public function addPayment(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $invoice = $this->find($request, $uuid);

        if ($invoice->status === 'cancelled') {
            abort(422, 'A cancelled document cannot take payments.');
        }

        $data = $request->validate([
            // The GROSS the client paid — that is what discharges the debt.
            'amount' => ['required', 'numeric', 'min:0.01'],
            // What the gateway or bank kept out of it, if anything.
            'charge_amount' => ['nullable', 'numeric', 'min:0'],
            'charge_note' => ['nullable', 'string', 'max:191'],
            'amount_fx' => ['nullable', 'numeric', 'min:0'],
            'bank_account_id' => ['nullable', Rule::exists('crm_bank_accounts', 'id')->where('organization_id', $org->id)],
            'payment_mode' => ['nullable', 'string', 'max:64'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'drawee_bank' => ['nullable', 'string', 'max:128'],
            'instrument_date' => ['nullable', 'date'],
            'received_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        if (($data['charge_amount'] ?? 0) >= $data['amount']) {
            abort(422, 'The charge cannot be the whole payment.');
        }

        $payment = $invoice->payments()->create($data + ['created_by' => $request->user()->id]);
        // The cost of collecting goes to the spend register, where the P&L
        // will find it — never netted off the sale.
        $payment = GatewayCharge::apply($payment->load('invoice'), $request->user()->id);
        // Money booked straight onto an invoice is still money that arrived,
        // so it belongs in the Payments ledger too — already matched to this
        // document and already settled, because recording it here WAS the
        // decision. Otherwise the two screens tell different stories.
        $this->mirrorToInbox($payment, $invoice, $request);
        $invoice->refreshPaymentStatus();

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'payment.recorded', $invoice,
            $this->trail($invoice) + array_filter([
                'amount' => $data['amount'],
                'charge' => ($data['charge_amount'] ?? 0) > 0 ? $data['charge_amount'] : null,
            ]));

        $charge = (float) ($data['charge_amount'] ?? 0);

        return response()->json([
            'message' => $charge > 0
                ? 'Payment recorded — ' . number_format((float) $data['amount'], 2)
                    . ' settled, ' . number_format($charge, 2) . ' booked as a collection charge, '
                    . number_format($payment->netAmount(), 2) . ' to the bank.'
                : 'Payment recorded.',
            'data' => $payment->load('bankAccount:id,label'),
            'payment_status' => $invoice->fresh()->payment_status,
        ], 201);
    }

    /**
     * Name (or correct) what collecting a payment cost, after the fact.
     * A gateway's settlement report usually arrives a day later than the
     * money, so the fee has to be addable to a receipt already written.
     */
    public function setPaymentCharge(Request $request, string $uuid, int $paymentId): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $invoice = $this->find($request, $uuid);
        $payment = $invoice->payments()->whereKey($paymentId)->firstOrFail();

        $data = $request->validate([
            'charge_amount' => ['required', 'numeric', 'min:0'],
            'charge_note' => ['nullable', 'string', 'max:191'],
            // The gross the client actually paid, when the receipt was
            // written from a bank line and so understated it.
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        if (($data['amount'] ?? $payment->amount) <= $data['charge_amount']) {
            abort(422, 'The charge cannot be the whole payment.');
        }

        $payment->update(array_filter([
            'amount' => $data['amount'] ?? null,
            'charge_amount' => $data['charge_amount'],
            'charge_note' => $data['charge_note'] ?? null,
        ], fn ($v) => $v !== null) + ['charge_note' => $data['charge_note'] ?? null]);

        $payment = GatewayCharge::apply($payment->load('invoice'), $request->user()->id);
        $invoice->refreshPaymentStatus();

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'payment.charge_set', $invoice,
            $this->trail($invoice) + ['charge' => (float) $data['charge_amount']]);

        return response()->json([
            'message' => (float) $data['charge_amount'] > 0
                ? number_format((float) $data['charge_amount'], 2)
                    . ' booked as a collection charge — ' . number_format($payment->netAmount(), 2) . ' to the bank.'
                : 'Charge removed.',
            'data' => $payment->load('bankAccount:id,label'),
            'payment_status' => $invoice->fresh()->payment_status,
        ]);
    }

    public function deletePayment(Request $request, string $uuid, int $paymentId): JsonResponse
    {
        $invoice = $this->find($request, $uuid);
        $payment = $invoice->payments()->whereKey($paymentId)->firstOrFail();
        // The charge was only ever a cost of this receipt.
        GatewayCharge::release($payment);
        // And the ledger line was only ever this receipt's reflection.
        PaymentInboxEntry::where('invoice_payment_id', $payment->id)->delete();
        $payment->delete();
        $invoice->refreshPaymentStatus();

        return response()->json(['message' => 'Payment removed.', 'payment_status' => $invoice->fresh()->payment_status]);
    }

    /**
     * Reflect a receipt written on the invoice into the Payments ledger.
     *
     * The ledger is the company's record of money arriving, so a payment
     * entered on the document has to appear there too — otherwise Payments
     * shows an empty day on which an invoice was paid. It arrives already
     * claimed against this document and already settled, because entering
     * it here was the decision: there is nothing left for anyone to confirm.
     *
     * The bank line is what actually reached the bank, so a gateway charge
     * is deducted here even though the invoice was credited the gross.
     */
    private function mirrorToInbox($payment, Invoice $invoice, Request $request): void
    {
        $charge = (float) $payment->charge_amount;

        PaymentInboxEntry::create([
            'organization_id' => $invoice->organization_id,
            'received_on' => $payment->received_at->toDateString(),
            'issuing_company_id' => $invoice->issuing_company_id,
            'bank_account_id' => $payment->bank_account_id,
            'payment_mode' => $payment->payment_mode,
            'amount' => $payment->netAmount(),
            'currency' => $invoice->currency ?: 'INR',
            'details' => 'Recorded on ' . $invoice->number
                . ($charge > 0
                    ? ' — client paid ' . number_format((float) $payment->amount, 2)
                        . ', ' . number_format($charge, 2) . ' kept as '
                        . ($payment->charge_note ?: 'a collection charge')
                    : ''),
            'reference_no' => $payment->reference_no,
            'status' => 'claimed',
            'settlement_mode' => 'auto',
            'claimed_invoice_id' => $invoice->id,
            'invoice_payment_id' => $payment->id,
            'claimed_member_id' => $invoice->member_id,
            'claimed_by' => $request->user()->id,
            'claimed_at' => now(),
            'settled_by' => $request->user()->id,
            'settled_at' => now(),
            'note' => $payment->note,
            'created_by' => $request->user()->id,
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * What a log line should still say a year from now: the number, who it
     * was for and how much — captured at the moment, not looked up later.
     */
    private function trail(Invoice $invoice): array
    {
        $client = $invoice->relationLoaded('client')
            ? $invoice->client?->company_name
            : Client::whereKey($invoice->client_id)->value('company_name');

        return array_filter([
            'number' => $invoice->number,
            'client' => $client,
            'total' => (float) $invoice->total,
        ], fn ($v) => $v !== null);
    }

    private function find(Request $request, string $uuid): Invoice
    {
        return Invoice::where('organization_id', $request->attributes->get('crm_org')->id)
            ->visibleTo($request->attributes->get('crm_member'))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * The document as a PDF file.
     *
     * The browser's print dialog is not available everywhere the CRM is used
     * (embedded browsers refuse it outright), so the paper copy is rendered
     * here and handed over as a file. Same ledger window as every other
     * single-document action.
     */
    public function pdf(Request $request, string $uuid)
    {
        $invoice = $this->find($request, $uuid)->load([
            'client', 'issuingCompany', 'items', 'taxes', 'member.user:id,name,email', 'payments',
        ]);
        $this->forKind($request, $invoice->kind, 'view');

        return $this->documentPdf($invoice)
            ->download(str_replace(['/', '\\', ' '], '-', $invoice->number) . '.pdf');
    }

    /**
     * Send the document to the client by e-mail, PDF attached. The sender
     * comes from the Communication setup: the company's general address, or
     * the separate invoice/dues sender when one is configured and chosen.
     */
    public function email(Request $request, string $uuid): JsonResponse
    {
        $invoice = $this->find($request, $uuid)->load([
            'client', 'issuingCompany', 'items', 'taxes', 'member.user:id,name,email', 'payments',
        ]);
        $this->forKind($request, $invoice->kind, 'view');
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'to' => ['nullable', 'email'],
            // Everyone else who should see it. The salesperson is offered by
            // default, and whoever sends decides who else — the accounts
            // person the client asked for, a colleague, themselves.
            'cc' => ['nullable', 'array', 'max:10'],
            'cc.*' => ['email'],
            'from' => ['nullable', \Illuminate\Validation\Rule::in(['default', 'invoice', 'dues'])],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $to = $data['to'] ?? $invoice->client?->email;
        abort_unless($to, 422, 'The client has no e-mail on file — enter one to send to.');

        // Nobody twice, and no copy to the address it is already going to.
        $cc = collect($data['cc'] ?? [])
            ->map(fn ($a) => trim($a))
            ->filter()
            ->unique(fn ($a) => mb_strtolower($a))
            ->reject(fn ($a) => strcasecmp($a, $to) === 0)
            ->values()
            ->all();

        // The issuing company's own sender/mailbox first; the chosen
        // purpose-level sender when the company has none.
        $resolved = (new \App\Services\Crm\CompanyMailer($org))
            ->resolve($invoice->issuing_company_id, $data['from'] ?? 'invoice');
        $mailer = $resolved['mailer'];
        $fromAddress = $resolved['address'];
        $fromName = $resolved['name'];

        $label = $invoice->kind === 'proforma' ? 'Proforma invoice' : 'Invoice';
        $lines = [
            'Dear ' . ($invoice->client?->contact_person ?: $invoice->client?->company_name ?: 'Sir/Madam') . ',',
            '',
            $data['message'] ?? 'Please find attached ' . strtolower($label) . ' ' . $invoice->number
                . ' for ' . ($invoice->currency ?: 'INR') . ' ' . number_format((float) $invoice->total, 2) . '.',
            '',
            'Regards,',
            $invoice->issuingCompany?->name ?? $org->name,
        ];

        $pdf = $this->documentPdf($invoice);
        try {
            $mailer->html(nl2br(e(implode("\n", $lines))), function ($m) use ($to, $cc, $label, $invoice, $pdf, $fromAddress, $fromName) {
                $m->to($to)
                    ->cc($cc)
                    ->from($fromAddress, $fromName)
                    ->subject($label . ' ' . $invoice->number)
                    ->attachData($pdf->output(), str_replace(['/', '\\', ' '], '-', $invoice->number) . '.pdf', ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $e) {
            abort(422, 'The mail could not be sent: ' . $e->getMessage());
        }

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'invoice.emailed', $invoice, [
            'number' => $invoice->number,
            'to' => $to,
            'cc' => implode(', ', $cc),
            'from' => $fromAddress,
        ]);

        return response()->json(['message' => $label . ' ' . $invoice->number . ' sent to ' . $to
            . ($cc !== [] ? ', copied to ' . implode(', ', $cc) : '') . '.']);
    }

    /** One PDF builder for downloads and e-mails alike. */
    private function documentPdf(Invoice $invoice)
    {

        $columns = collect(CustomField::workOrderMethod($invoice->organization_id))
            ->where('source', 'builtin')
            ->keyBy('key')
            ->all();

        $received = (float) $invoice->payments->sum('amount');

        $logoPath = $invoice->issuingCompany?->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($invoice->issuingCompany->logo_path)
            : null;

        // The rubber stamp, which prints beside the signatory rather than in
        // the header. Resolved to a real path the same way, because dompdf
        // reads from disk and a missing file would break the whole document.
        $stampPath = $invoice->issuingCompany?->stamp_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($invoice->issuingCompany->stamp_path)
            : null;

        $pdf = Pdf::loadView('crm.document', [
            'invoice' => $invoice,
            'company' => $invoice->issuingCompany,
            'logoPath' => $logoPath && is_file($logoPath) ? $logoPath : null,
            'stampPath' => $stampPath && is_file($stampPath) ? $stampPath : null,
            'currency' => $invoice->currency ?: 'INR',
            'received' => $received,
            'columns' => $columns,
            'extraColumns' => collect(CustomField::workOrderMethod($invoice->organization_id))
                ->where('source', 'custom')
                ->values()
                ->all(),
            // The lines as they stood on this document, in the wording it
            // was raised with.
            'moneyLines' => $invoice->taxes->map(fn ($t) => [
                'label' => $t->label,
                'rate' => $t->rate,
                'amount' => (float) $t->amount,
                'sign' => $t->kind === 'tax' ? '+' : '-',
            ])->filter(fn ($line) => $line['amount'] > 0)->values()->all(),
            // The document's own extra fields, if this company added any.
            'documentFields' => collect(CustomField::approvedFor($invoice->organization_id, 'invoice'))
                ->map(fn ($f) => ['label' => $f->label, 'value' => data_get($invoice->custom_fields, $f->key)])
                ->filter(fn ($f) => $f['value'] !== null && $f['value'] !== '' && $f['value'] !== false)
                ->values()
                ->all(),
            'headings' => collect(CustomField::invoiceMethod($invoice->organization_id))
                ->where('source', 'builtin')
                ->keyBy('key')
                ->all(),
            // The issuing company's OWN account first; an unassigned
            // org-wide account only as the fallback.
            'bank' => BankAccount::where('organization_id', $invoice->organization_id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('issuing_company_id', $invoice->issuing_company_id)
                    ->orWhereNull('issuing_company_id'))
                ->orderByRaw('case when issuing_company_id is null then 1 else 0 end')
                ->orderBy('id')
                ->first(),
        ])->setPaper('a4');

        return $pdf;
    }

    // ---- Invoice Log / Proforma Log ----------------------------------------

    /**
     * The document trail: every event about invoices (or proformas), read
     * from the shared ActivityLog.
     *
     * Entries are matched by the SUBJECT's kind rather than by action name,
     * so a payment or an applied change request lands in the invoice log
     * without the log needing a list of action strings to keep in step.
     */
    public function log(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $kind = $request->query('kind') === 'proforma' ? 'proforma' : 'invoice';

        /*
         * The log is its own entry in the sidebar, so its own right — reading
         * the trail is a different job from raising the document, and a
         * company may well want one without the other.
         */
        /** @var Member $me */
        $reader = $request->attributes->get('crm_member');
        abort_unless(
            $kind === 'proforma'
                ? $reader?->can('proforma_log', 'view')
                : $reader?->can('invoice_log', 'view'),
            403,
            $kind === 'proforma'
                ? 'You do not have rights to the proforma log.'
                : 'You do not have rights to the invoice log.',
        );

        // The same ledger window as the list: your own documents only,
        // unless you run the company.
        $documents = Invoice::where('organization_id', $org->id)
            ->visibleTo($me)
            ->where('kind', $kind);

        $query = ActivityLog::with('member.user:id,name,email')
            ->where('organization_id', $org->id)
            ->where('subject_type', (new Invoice)->getMorphClass())
            ->whereIn('subject_id', (clone $documents)->select('id'));

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($by = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $by));
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($text = trim((string) $request->query('search'))) {
            // Numbers and client names both live in the entry's own payload.
            $query->where('changes', 'like', "%{$text}%");
        }
        if ($doc = $request->query('document')) {
            $query->whereIn('subject_id', (clone $documents)->where('uuid', $doc)->select('id'));
        }

        // Chart feeds, over the filtered set: what happened, and when.
        $counted = (clone $query)->get(['action', 'created_at']);
        $summary = [
            'total' => $counted->count(),
            'by_action' => $counted->groupBy('action')
                ->map(fn ($g, $action) => ['action' => $action, 'count' => $g->count()])
                ->sortByDesc('count')->values(),
            'daily' => $counted->groupBy(fn ($l) => $l->created_at->toDateString())
                ->map(fn ($g, $day) => ['day' => $day, 'count' => $g->count()])
                ->sortBy('day')->values()->take(-30)->values(),
            // The actions this org has actually recorded — the filter's options.
            'actions' => $counted->pluck('action')->unique()->sort()->values(),
        ];

        $logs = $query->latest()->latest('id')->paginate(50);

        // One lookup so each line can deep-link to a document that still exists.
        $docs = Invoice::whereIn('id', $logs->getCollection()->pluck('subject_id')->unique())
            ->get(['id', 'uuid', 'number', 'status', 'payment_status'])
            ->keyBy('id');

        $logs->getCollection()->transform(function (ActivityLog $log) use ($docs) {
            $doc = $docs[$log->subject_id] ?? null;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'by' => $log->member?->user?->name,
                'at' => $log->created_at->toDateTimeString(),
                // The payload is the record of the moment; the live document
                // fills in only what the entry never captured.
                'number' => data_get($log->changes, 'number') ?? $doc?->number,
                'client' => data_get($log->changes, 'client'),
                'total' => data_get($log->changes, 'total'),
                'amount' => data_get($log->changes, 'amount'),
                'invoice' => data_get($log->changes, 'invoice'),
                'from_proforma' => data_get($log->changes, 'from_proforma'),
                'fields' => data_get($log->changes, 'fields'),
                'note' => data_get($log->changes, 'note'),
                'document' => $doc ? [
                    'uuid' => $doc->uuid,
                    'number' => $doc->number,
                    'status' => $doc->status,
                    'payment_status' => $doc->payment_status,
                ] : null,
            ];
        });

        return response()->json(['summary' => $summary, 'kind' => $kind] + $logs->toArray());
    }

    /** @return array{0: array, 1: array, 2: array} [$invoiceData, $items, $taxLines] */
    private function validatePayload(Request $request, int $orgId, bool $updating = false): array
    {
        $data = $request->validate([
            'kind' => [$updating ? 'nullable' : 'required', Rule::in(Invoice::KINDS)],
            'issuing_company_id' => [$updating ? 'nullable' : 'required', Rule::exists('crm_issuing_companies', 'id')->where('organization_id', $orgId)],
            'client_uuid' => [$updating ? 'nullable' : 'required', 'string'],
            'member_uuid' => ['nullable', 'string'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'client_category' => ['nullable', Rule::in(Client::CATEGORIES)],
            'pricing_tier' => ['nullable', Rule::in(['regular', 'low'])],
            'currency' => ['nullable', 'string', 'size:3'],
            'terms_of_payment' => ['nullable', 'string', 'max:255'],
            'subscription_type' => ['nullable', Rule::in(['online', 'offline', 'both'])],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'cgst' => ['nullable', 'numeric', 'min:0'],
            'sgst' => ['nullable', 'numeric', 'min:0'],
            'igst' => ['nullable', 'numeric', 'min:0'],
            'other_tax' => ['nullable', 'numeric', 'min:0'],
            'tds' => ['nullable', 'numeric', 'min:0'],
            // A percentage may be given instead of a figure; the server then
            // works the figure out, so the two can never disagree.
            'discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'other_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tds_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // A company's own money lines, when the form sends them.
            'tax_lines' => ['nullable', 'array', 'max:30'],
            'tax_lines.*.key' => ['required', 'string', 'max:64'],
            'tax_lines.*.rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_lines.*.amount' => ['nullable', 'numeric'],
            'fx_currency' => ['nullable', 'string', 'size:3'],
            'fx_rate' => ['nullable', 'numeric', 'min:0'],
            'dispatch_status' => ['nullable', Rule::in(Invoice::DISPATCH_STATUSES)],
            'payment_status' => ['nullable', Rule::in(Invoice::PAYMENT_STATUSES)],
            'status' => ['nullable', Rule::in(['draft', 'final'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.membership' => ['nullable', 'string', 'max:128'],
            'items.*.plan_name' => ['nullable', 'string', 'max:128'],
            'items.*.description' => ['nullable', 'string', 'max:512'],
            'items.*.validity_from' => ['nullable', 'date'],
            'items.*.validity_to' => ['nullable', 'date'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.amount_fx' => ['nullable', 'numeric', 'min:0'],
        ]);

        $items = $this->applyWorkOrderFields($request, $orgId, $data['items']);
        unset($data['items'], $data['tax_lines']);
        $data = $this->applyDocumentFields($request, $orgId, $data);

        if (! $updating || $request->filled('client_uuid')) {
            $data['client_id'] = Client::where('organization_id', $orgId)
                ->where('uuid', $data['client_uuid'] ?? '')
                ->firstOrFail()->id;
        }
        unset($data['client_uuid']);

        if ($request->has('member_uuid')) {
            $uuid = $data['member_uuid'] ?? null;
            $data['member_id'] = $uuid
                ? Member::where('organization_id', $orgId)->where('uuid', $uuid)->firstOrFail()->id
                : null;
        }
        unset($data['member_uuid']);

        // House style on the name-like line fields (App\Support\TextCase):
        // plan and membership names, never the free-text description.
        foreach ($items as &$named) {
            foreach (['membership', 'plan_name'] as $field) {
                if (! empty($named[$field])) {
                    $named[$field] = TextCase::company($named[$field]);
                }
            }
        }
        unset($named);

        if (! empty($data['fx_currency'])) {
            $data['fx_currency'] = TextCase::code($data['fx_currency']);
        }

        // Server-side arithmetic: the stored totals always agree with the lines.
        $subtotal = 0.0;
        $subtotalFx = 0.0;
        foreach ($items as &$item) {
            $qty = (float) ($item['qty'] ?? 1);
            $unit = (float) ($item['unit_price'] ?? 0);
            $item['amount'] = round($qty * $unit, 2);
            $subtotal += $item['amount'];
            $subtotalFx += (float) ($item['amount_fx'] ?? 0);
        }
        unset($item);

        $data['subtotal'] = round($subtotal, 2);

        // The money lines are this company's own — ours renamed or switched
        // off, plus any they added — so the arithmetic reads the setup.
        [$taxLines, $sums] = $this->computeTaxes($request, $orgId, $data, $subtotal);
        $data['total'] = $sums['total'];
        if ($subtotalFx > 0) {
            $data['subtotal_fx'] = round($subtotalFx, 2);
            $data['total_fx'] = round($subtotalFx, 2);
        }

        // A foreign-currency issuing company bills in its own currency, and
        // the document carries the universal INR figure beside it — market
        // rate less the bank-charge margin, frozen at save time.
        if (! empty($data['issuing_company_id'])) {
            $company = IssuingCompany::find($data['issuing_company_id']);
            $companyCurrency = strtoupper((string) ($company?->currency ?: 'INR'));
            if ($company && $companyCurrency !== 'INR') {
                $data['currency'] = $companyCurrency;
                $rate = (new \App\Services\Crm\FxService($company->organization ?? $request->attributes->get('crm_org')))
                    ->effectiveRate($companyCurrency);
                if ($rate !== null) {
                    $data['fx_currency'] = 'INR';
                    $data['fx_rate'] = $rate;
                    $data['subtotal_fx'] = round($data['subtotal'] * $rate, 2);
                    $data['total_fx'] = round($data['total'] * $rate, 2);
                }
            }
        }

        return [$data, $items, $taxLines];
    }

    /**
     * The document's own fields: this company's wording for our header
     * fields, plus any it added. A field switched off carries no data, and an
     * added one is validated against its approved definition.
     */
    private function applyDocumentFields(Request $request, int $orgId, array $data): array
    {
        $method = CustomField::invoiceMethod($orgId);
        $fields = CustomField::approvedFor($orgId, 'invoice');

        if ($fields->isNotEmpty()) {
            $rules = [];
            $names = [];
            foreach ($fields as $field) {
                $rules['custom_fields.' . $field->key] = $field->validationRule();
                $names['custom_fields.' . $field->key] = $field->label;
            }
            $validated = $request->validate($rules, [], $names);

            $values = [];
            foreach ($fields as $field) {
                $value = data_get($validated, 'custom_fields.' . $field->key);
                if ($field->type === 'checkbox') {
                    $values[$field->key] = (bool) $value;
                } elseif ($value !== null && $value !== '') {
                    $values[$field->key] = $value;
                }
            }
            $data['custom_fields'] = $values;
        }

        // A header field this company switched off is not quietly kept.
        foreach ($method as $column) {
            if ($column['source'] !== 'builtin' || ! $column['hidden']) {
                continue;
            }
            foreach (self::DOCUMENT_ATTRIBUTES[$column['key']] ?? [] as $attribute) {
                $data[$attribute] = null;
            }
        }

        return $data;
    }

    /**
     * The Work Order method: this company's own line columns (DCW).
     *
     * Two things happen here. The extra fields a company added are validated
     * against their approved definitions and stored as JSON on the line; and
     * our own columns are held to the company's wording — a column they
     * turned into a dropdown takes only their options, a column they marked
     * required must be filled, and a column they hid is cleared rather than
     * quietly kept.
     */
    private function applyWorkOrderFields(Request $request, int $orgId, array $items): array
    {
        $method = CustomField::workOrderMethod($orgId);
        $fields = CustomField::approvedFor($orgId, 'work_order');
        $builtins = collect($method)->where('source', 'builtin')->keyBy('key');

        $rules = [];
        $names = [];
        foreach (array_keys($items) as $index) {
            $line = ' (line ' . ((int) $index + 1) . ')';

            foreach ($fields as $field) {
                $rules['items.' . $index . '.custom_fields.' . $field->key] = $field->validationRule();
                $names['items.' . $index . '.custom_fields.' . $field->key] = $field->label . $line;
            }

            foreach ($builtins as $key => $column) {
                if ($column['hidden']) {
                    continue;
                }
                foreach ($this->builtinRules($key, $column) as $attribute => $rule) {
                    $rules['items.' . $index . '.' . $attribute] = $rule;
                    $names['items.' . $index . '.' . $attribute] = $column['label'] . $line;
                }
            }
        }

        $hidden = $builtins->filter(fn ($column) => $column['hidden']);

        if ($rules === [] && $hidden->isEmpty()) {
            return $items;
        }

        $validated = $rules === [] ? [] : $request->validate($rules, [], $names);

        foreach ($items as $index => $item) {
            // Extra fields: unknown keys never make it this far.
            if ($fields->isNotEmpty()) {
                $values = [];
                foreach ($fields as $field) {
                    $value = data_get($validated, 'items.' . $index . '.custom_fields.' . $field->key);
                    if ($field->type === 'checkbox') {
                        $values[$field->key] = (bool) $value;
                    } elseif ($value !== null && $value !== '') {
                        $values[$field->key] = $value;
                    }
                }
                $items[$index]['custom_fields'] = $values;
            }

            // A column this company switched off carries no data.
            foreach ($hidden as $key => $column) {
                foreach ($key === 'validity' ? ['validity_from', 'validity_to'] : [$key] as $attribute) {
                    $items[$index][$attribute] = null;
                }
            }
        }

        return $items;
    }

    /**
     * The extra rules a customised built-in column earns, keyed by the line
     * attribute they apply to. Anything already covered by the base rules is
     * left alone.
     *
     * @return array<string, array>
     */
    private function builtinRules(string $key, array $column): array
    {
        if ($key === 'validity') {
            return $column['is_required']
                ? ['validity_from' => ['required', 'date'], 'validity_to' => ['required', 'date']]
                : [];
        }

        if ($column['type'] === 'select') {
            return [$key => [
                $column['is_required'] ? 'required' : 'nullable',
                Rule::in($column['options'] ?? []),
            ]];
        }

        if ($column['is_required']) {
            return [$key => $column['type'] === 'number'
                ? ['required', 'numeric']
                : ['required', 'string']];
        }

        return [];
    }

    /**
     * The money lines of one document, worked out from this company's own tax
     * setup rather than a fixed list of five.
     *
     * A line may be given a percentage or a flat figure; the percentage wins,
     * because two answers to the same question is how a ledger goes wrong.
     * Discounts come off the subtotal first, then everything charged on the
     * taxable value works on what is left.
     *
     * @return array{0: array<int, array>, 1: array<string, float>}
     *         [$lines, $totals]
     */
    private function computeTaxes(Request $request, int $orgId, array $data, float $subtotal): array
    {
        $setup = CustomField::taxSetup($orgId);

        // Two ways in: the company's own lines, or the plain cgst/sgst/… of
        // the standard setup, which is what every older client still sends.
        $given = collect($request->input('tax_lines'))
            ->filter(fn ($line) => is_array($line) && ! empty($line['key']))
            ->keyBy('key');

        $rows = [];
        $discounted = 0.0;

        // First pass: discounts, so the taxable value is known before the
        // taxes that are charged on it.
        foreach ($setup as $line) {
            if ($line['kind'] !== 'discount') {
                continue;
            }
            $row = $this->taxRow($line, $given, $data, $subtotal, $subtotal);
            $discounted += $row['amount'];
            $rows[$line['key']] = $row;
        }

        $taxable = round($subtotal - $discounted, 2);
        $added = 0.0;
        $deducted = 0.0;

        foreach ($setup as $line) {
            if ($line['kind'] === 'discount') {
                continue;
            }
            $base = $line['basis'] === 'subtotal' ? $subtotal : $taxable;
            $row = $this->taxRow($line, $given, $data, $subtotal, $base);
            $rows[$line['key']] = $row;

            if ($line['kind'] === 'deduction') {
                $deducted += $row['amount'];
            } else {
                $added += $row['amount'];
            }
        }

        return [
            array_values($rows),
            [
                'taxable' => $taxable,
                'total' => round($taxable + $added - $deducted, 2),
            ],
        ];
    }

    /** One money line: its rate, and what that comes to on this document. */
    private function taxRow(array $line, $given, array $data, float $subtotal, float $base): array
    {
        $key = $line['key'];
        $sent = $given[$key] ?? null;

        // A rate given on the document, else the standing rate the company
        // set for this line, else none.
        $rate = $sent !== null
            ? ($sent['rate'] ?? null)
            : ($data[$key . '_rate'] ?? null);
        if ($rate === null || $rate === '') {
            $rate = $line['default_rate'];
        }

        $amount = $rate !== null
            ? round($base * (float) $rate / 100, 2)
            : (float) ($sent !== null ? ($sent['amount'] ?? 0) : ($data[$key] ?? 0));

        return [
            'key' => $key,
            'label' => $line['label'],
            'kind' => $line['kind'],
            'basis' => $line['basis'],
            'rate' => $rate === null ? null : round((float) $rate, 3),
            'amount' => round($amount, 2),
        ];
    }

    /**
     * Write the lines to the document and mirror the standard six onto their
     * own columns, so everything that already reads `cgst` keeps working.
     */
    private function syncTaxes(Invoice $invoice, array $lines): void
    {
        $invoice->taxes()->delete();
        $mirror = array_fill_keys(array_keys(CustomField::BUILTIN_TAX), 0);
        $rates = [];

        foreach (array_values($lines) as $sort => $line) {
            $invoice->taxes()->create($line + ['sort' => $sort]);

            if (array_key_exists($line['key'], $mirror)) {
                $mirror[$line['key']] = $line['amount'];
                $rates[$line['key'] . '_rate'] = $line['rate'];
            }
        }

        $invoice->forceFill($mirror + $rates)->save();
    }

    private function syncItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();
        foreach (array_values($items) as $i => $item) {
            $invoice->items()->create($item + ['sort' => $i]);
        }
    }

    private function serialize(Invoice $i, bool $full = false): array
    {
        $base = [
            'uuid' => $i->uuid,
            'kind' => $i->kind,
            'number' => $i->number,
            'status' => $i->status,
            'invoice_date' => $i->invoice_date->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'client' => $i->client ? [
                'uuid' => $i->client->uuid,
                'company_name' => $i->client->company_name,
                'contact_person' => $i->client->contact_person,
                // The Email button prefills with this.
                'email' => $i->client->email,
            ] : null,
            'issuing_company' => $i->issuingCompany?->only(['id', 'name']),
            // The e-mail dialog offers the salesperson a copy of what
            // went to their client, so it needs their address too.
            'salesperson' => $i->member ? [
                'uuid' => $i->member->uuid,
                'name' => $i->member->user?->name,
                'email' => $i->member->user?->email,
            ] : null,
            'currency' => $i->currency,
            'subtotal' => $i->subtotal,
            'total' => $i->total,
            'total_fx' => $i->total_fx,
            'fx_currency' => $i->fx_currency,
            'payment_status' => $i->payment_status,
            'dispatch_status' => $i->dispatch_status,
            'converted' => $i->relationLoaded('convertedTo') && $i->convertedTo !== null,
            // "Recurring · 2 of 12", stamped when the copy was raised.
            'recurring_note' => $i->recurring_note,
            // The schedule link is the fact; the note is the choice — the
            // office sees "Recurring" even when the paper stays silent. A
            // ONE-TIME copy is not recurring and never wears the word: it
            // was one extra document, raised once, full stop.
            'is_recurring' => $i->recurring_invoice_id !== null
                && ($i->recurringSchedule?->frequency ?? null) !== 'once',
            'converted_to_doc' => $i->relationLoaded('convertedTo') && $i->convertedTo
                ? ['uuid' => $i->convertedTo->uuid, 'number' => $i->convertedTo->number]
                : null,
        ];

        if (! $full) {
            return $base;
        }

        return $base + [
            'client_full' => $i->client?->only([
                'address', 'city', 'state', 'pincode', 'country', 'gst_no', 'email', 'mobile',
            ]),
            'issuing_company_full' => $i->issuingCompany?->only([
                'address', 'gstin', 'pan', 'state_code', 'phone', 'email',
                // The letterhead and the rubber stamp. Sent so the screen can
                // draw the same document the PDF does — the Print button
                // prints what is on screen, so a header only the PDF knew
                // about was a header printing never had.
                'logo_path', 'stamp_path',
            ]),
            'client_category' => $i->client_category,
            'pricing_tier' => $i->pricing_tier,
            'terms_of_payment' => $i->terms_of_payment,
            'subscription_type' => $i->subscription_type,
            'discount' => $i->discount,
            'cgst' => $i->cgst,
            'sgst' => $i->sgst,
            'igst' => $i->igst,
            'other_tax' => $i->other_tax,
            'tds' => $i->tds,
            'discount_rate' => $i->discount_rate,
            'cgst_rate' => $i->cgst_rate,
            'sgst_rate' => $i->sgst_rate,
            'igst_rate' => $i->igst_rate,
            'other_tax_rate' => $i->other_tax_rate,
            'tds_rate' => $i->tds_rate,
            // Whatever the discounts left — a company may have its own, so
            // the lines are the truth, not the mirrored `discount` column.
            'taxable' => round((float) $i->subtotal - ($i->relationLoaded('taxes')
                ? (float) $i->taxes->where('kind', 'discount')->sum('amount')
                : (float) $i->discount), 2),
            // Every money line as it stood on this document, in the company's
            // own wording — the fixed fields above mirror the standard six.
            'tax_lines' => $i->relationLoaded('taxes')
                ? $i->taxes->map(fn ($t) => [
                    'key' => $t->key,
                    'label' => $t->label,
                    'kind' => $t->kind,
                    'basis' => $t->basis,
                    'rate' => $t->rate,
                    'amount' => $t->amount,
                ])->values()
                : [],
            'custom_fields' => $i->custom_fields ?? (object) [],
            'fx_rate' => $i->fx_rate,
            'subtotal_fx' => $i->subtotal_fx,
            'notes' => $i->notes,
            'items' => $i->items->map(fn ($it) => [
                'id' => $it->id,
                'membership' => $it->membership,
                'plan_name' => $it->plan_name,
                'description' => $it->description,
                'custom_fields' => $it->custom_fields ?? (object) [],
                'validity_from' => $it->validity_from?->toDateString(),
                'validity_to' => $it->validity_to?->toDateString(),
                'qty' => $it->qty,
                'unit_price' => $it->unit_price,
                'amount' => $it->amount,
                'amount_fx' => $it->amount_fx,
            ]),
            'payments' => $i->payments->map(fn ($p) => [
                'id' => $p->id,
                'payment_no' => $p->payment_no,
                // Gross the client paid; what collecting it cost; what
                // actually reached the bank.
                'amount' => $p->amount,
                'charge_amount' => $p->charge_amount,
                'charge_note' => $p->charge_note,
                'net_amount' => $p->netAmount(),
                'amount_fx' => $p->amount_fx,
                'bank_account' => $p->bankAccount?->label,
                'payment_mode' => $p->payment_mode,
                'reference_no' => $p->reference_no,
                'drawee_bank' => $p->drawee_bank,
                'instrument_date' => $p->instrument_date?->toDateString(),
                'received_at' => $p->received_at->toDateString(),
                'note' => $p->note,
            ]),
            'amount_received' => $i->payments->sum('amount'),
            'collection_charges' => round($i->payments->sum('charge_amount'), 2),
            // What the sale cost the channel (the client's cut) — named
            // neutrally so the payload never carries the c-word a client
            // must not see; the screen labels it for internal eyes.
            'sale_costs_total' => round((float) \App\Models\Crm\Expense::where('invoice_id', $i->id)
                ->where('category', CommissionController::CATEGORY)
                ->sum('total_amount'), 2),
            'converted_from' => $i->convertedFrom ? ['uuid' => $i->convertedFrom->uuid, 'number' => $i->convertedFrom->number] : null,
            'converted_to' => $i->relationLoaded('convertedTo') && $i->convertedTo
                ? ['uuid' => $i->convertedTo->uuid, 'number' => $i->convertedTo->number]
                : null,
        ];
    }
}
