<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\PaymentGateway;
use App\Models\Crm\PaymentLink;
use App\Services\Crm\CashfreeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Pay this online": a Cashfree link raised against a proforma or an invoice.
 *
 * The link belongs to the document, so it obeys the same ledger window as the
 * document does, and paying one settles it through the same door an Admin
 * uses — see PaymentSettler.
 */
class PaymentLinkController extends Controller
{
    public function __construct(private CashfreeGateway $cashfree)
    {
    }

    /** Every link raised against one document, newest first. */
    public function index(Request $request, string $invoiceUuid): JsonResponse
    {
        $document = $this->document($request, $invoiceUuid);
        $org = $request->attributes->get('crm_org');

        return response()->json([
            'data' => $document->paymentLinks()->with('creator:id,name')->orderByDesc('id')->get()
                ->map(fn (PaymentLink $link) => $this->serialize($link)),
            'gateway' => [
                'configured' => $this->cashfree->accountFor($org) !== null,
                'balance' => $this->balance($document),
            ],
        ]);
    }

    /** Raise one. The amount defaults to what is still owed. */
    public function store(Request $request, string $invoiceUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $document = $this->document($request, $invoiceUuid);

        if ($document->status === 'cancelled') {
            abort(422, $document->number . ' is cancelled.');
        }
        if ($document->kind === 'proforma' && $document->convertedTo) {
            abort(422, $document->number . ' has already become ' . $document->convertedTo->number . ' — raise the link there.');
        }

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:1'],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'send_email' => ['nullable', 'boolean'],
            'send_sms' => ['nullable', 'boolean'],
            'partial' => ['nullable', 'boolean'],
        ]);

        $amount = round((float) ($data['amount'] ?? $this->balance($document)), 2);

        // An open link for the same money is a duplicate, not a second try.
        $existing = $document->paymentLinks()
            ->where('status', 'active')
            ->where('amount', $amount)
            ->get()
            ->first(fn (PaymentLink $link) => $link->isOpen());

        if ($existing) {
            return response()->json([
                'message' => 'There is already an open link for this amount.',
                'data' => $this->serialize($existing),
            ]);
        }

        $link = $this->cashfree->createLink($document, $amount, $request->user(), $data);

        ActivityLog::record($me, $org->id, 'payment.link_created', $document, array_filter([
            'number' => $document->number,
            'client' => $document->client?->company_name,
            'amount' => $amount,
            'by' => $me?->user?->name,
        ]));

        return response()->json([
            'message' => 'Payment link ready for ' . $document->number . '.',
            'data' => $this->serialize($link->load('creator:id,name')),
        ], 201);
    }

    // ---- The company's Cashfree account ------------------------------------

    /** What is on file — never the secret itself. */
    public function settings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $account = PaymentGateway::where('organization_id', $org->id)->where('provider', 'cashfree')->first();

        return response()->json(['data' => [
            'provider' => 'cashfree',
            'mode' => $account?->mode ?? 'sandbox',
            'app_id' => $account?->app_id,
            'has_secret' => filled($account?->secret),
            'is_active' => (bool) $account?->is_active,
            'api_version' => CashfreeGateway::API_VERSION,
            // Paste this into the Cashfree dashboard so it can tell us.
            'webhook_url' => $this->cashfree->webhookUrl($org),
        ]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'mode' => ['required', Rule::in(['sandbox', 'production'])],
            'app_id' => ['required', 'string', 'max:255'],
            // Left blank on an edit, the secret already on file stands.
            'secret' => ['nullable', 'string', 'max:512'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $account = PaymentGateway::firstOrNew([
            'organization_id' => $org->id,
            'provider' => 'cashfree',
        ]);

        $account->fill([
            'mode' => $data['mode'],
            'app_id' => $data['app_id'],
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        if (filled($data['secret'] ?? null)) {
            $account->secret = $data['secret'];
        }
        if (blank($account->secret)) {
            abort(422, 'The Cashfree secret key is needed before links can be raised.');
        }
        $account->save();

        // The secret is never written to the trail, only the fact it changed.
        ActivityLog::record($me, $org->id, 'settings.gateway', $org, [
            'provider' => 'cashfree',
            'mode' => $account->mode,
            'active' => $account->is_active ? 'on' : 'off',
            'by' => $me?->user?->name,
        ]);

        return response()->json(['message' => 'Cashfree settings saved.', 'data' => [
            'mode' => $account->mode,
            'app_id' => $account->app_id,
            'has_secret' => true,
            'is_active' => $account->is_active,
            'webhook_url' => $this->cashfree->webhookUrl($org),
        ]]);
    }

    // ---- Helpers -----------------------------------------------------------

    private function document(Request $request, string $uuid): Invoice
    {
        return Invoice::with('client', 'organization', 'convertedTo')
            ->where('organization_id', $request->attributes->get('crm_org')->id)
            ->visibleTo($request->attributes->get('crm_member'))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function balance(Invoice $document): float
    {
        return round((float) $document->total - (float) $document->payments()->sum('amount'), 2);
    }

    private function serialize(PaymentLink $link): array
    {
        return [
            'uuid' => $link->uuid,
            'link_id' => $link->link_id,
            'link_url' => $link->link_url,
            'amount' => $link->amount,
            'amount_paid' => $link->amount_paid,
            'currency' => $link->currency,
            'status' => $link->status,
            'is_open' => $link->isOpen(),
            'expires_at' => $link->expires_at?->toDateTimeString(),
            'paid_at' => $link->paid_at?->toDateTimeString(),
            'created_by' => $link->creator?->name,
            'created_at' => $link->created_at?->toDateTimeString(),
        ];
    }
}
