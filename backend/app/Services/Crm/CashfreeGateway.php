<?php

namespace App\Services\Crm;

use App\Models\Crm\Invoice;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentGateway;
use App\Models\Crm\PaymentLink;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cashfree payment links.
 *
 * Each company brings its own Cashfree account, so every call carries that
 * company's credentials and the money lands in that company's bank. We only
 * ever ask for a link and then wait to be told it was paid — the card details
 * never come near us.
 *
 * Contract (verified against Cashfree's API reference, version 2026-01-01):
 *   POST {base}/pg/links   headers: x-api-version, x-client-id, x-client-secret
 *   Webhook: x-webhook-signature = base64(hmac-sha256(timestamp + rawBody, secret))
 */
class CashfreeGateway
{
    /** The dated API version this integration is written against. */
    public const API_VERSION = '2026-01-01';

    public function accountFor(Organization $organization): ?PaymentGateway
    {
        $gateway = PaymentGateway::where('organization_id', $organization->id)
            ->where('provider', 'cashfree')
            ->first();

        return $gateway?->isUsable() ? $gateway : null;
    }

    /**
     * Ask Cashfree for a link that pays this document.
     *
     * @param  float  $amount  what to collect — usually the balance
     */
    public function createLink(Invoice $document, float $amount, ?User $user, array $options = []): PaymentLink
    {
        $organization = $document->organization ?? Organization::findOrFail($document->organization_id);
        $account = $this->accountFor($organization);

        if (! $account) {
            abort(422, 'Cashfree is not set up for this company yet — add the credentials under Billing setup.');
        }
        if ($amount <= 0) {
            abort(422, $document->number . ' has nothing left to pay.');
        }

        $client = $document->client;
        // Cashfree requires a phone; a company that bills by e-mail alone has
        // nowhere to send the SMS, so say which detail is missing.
        if (blank($client?->mobile)) {
            abort(422, ($client?->company_name ?? 'This client') . ' has no mobile number on file — Cashfree needs one to raise a link.');
        }

        // Ours, and unique: a second link on the same document is a new one,
        // not a retry of the first.
        $linkId = Str::of($document->number)->replaceMatches('/[^A-Za-z0-9]/', '-')->append('-' . Str::lower(Str::random(6)))->value();
        $expiresAt = now()->addDays((int) ($options['expiry_days'] ?? 15));

        $payload = [
            'link_id' => $linkId,
            'link_amount' => round($amount, 2),
            'link_currency' => $document->currency ?: 'INR',
            'link_purpose' => $options['purpose'] ?? ($document->kind === 'proforma'
                ? 'Payment against proforma ' . $document->number
                : 'Payment against invoice ' . $document->number),
            'customer_details' => array_filter([
                'customer_phone' => preg_replace('/\D+/', '', $client->mobile),
                'customer_email' => $client->email,
                'customer_name' => $client->company_name,
            ]),
            'link_notify' => [
                'send_sms' => (bool) ($options['send_sms'] ?? false),
                'send_email' => (bool) ($options['send_email'] ?? false),
            ],
            'link_auto_reminders' => (bool) ($options['auto_reminders'] ?? true),
            'link_expiry_time' => $expiresAt->toIso8601String(),
            'link_partial_payments' => (bool) ($options['partial'] ?? false),
            'link_notes' => array_filter([
                'document' => $document->number,
                'document_uuid' => $document->uuid,
                'organization' => $organization->code,
            ]),
            'link_meta' => array_filter([
                'notify_url' => $this->webhookUrl($organization),
                'return_url' => $options['return_url'] ?? null,
            ]),
        ];

        $response = Http::withHeaders([
            'x-api-version' => self::API_VERSION,
            'x-client-id' => $account->app_id,
            'x-client-secret' => $account->secret,
            'x-request-id' => $linkId,
        ])->acceptJson()
            ->timeout(20)
            ->post($account->baseUrl() . '/links', $payload);

        if (! $response->successful()) {
            // Cashfree's own words are more use than ours.
            abort(422, 'Cashfree refused the link: ' . ($response->json('message') ?? $response->body()));
        }

        $body = $response->json();

        return PaymentLink::create([
            'organization_id' => $organization->id,
            'invoice_id' => $document->id,
            'provider' => 'cashfree',
            'link_id' => $linkId,
            'cf_link_id' => $body['cf_link_id'] ?? null,
            'link_url' => $body['link_url'] ?? null,
            'amount' => round($amount, 2),
            'currency' => $payload['link_currency'],
            'purpose' => $payload['link_purpose'],
            'status' => 'active',
            'expires_at' => $expiresAt,
            'created_by' => $user?->id,
            'last_event' => ['created' => $body],
        ]);
    }

    /**
     * Is this webhook really from Cashfree?
     *
     * The signature is over the raw body, not the parsed one — a re-encoded
     * payload will not match, which is the whole point.
     */
    public function verifyWebhook(Organization $organization, string $timestamp, string $rawBody, ?string $signature): bool
    {
        $account = PaymentGateway::where('organization_id', $organization->id)
            ->where('provider', 'cashfree')
            ->first();

        if (! $account || blank($account->secret) || blank($signature)) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $account->secret, true));

        return hash_equals($expected, $signature);
    }

    /** Where this company's Cashfree account should send its webhooks. */
    public function webhookUrl(Organization $organization): string
    {
        return url('/api/v1/crm/webhooks/cashfree/' . $organization->uuid);
    }
}
