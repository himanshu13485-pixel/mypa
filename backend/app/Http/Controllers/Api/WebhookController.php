<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCashfreeWebhook;
use App\Models\PaymentWebhook;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Cashfree webhook receiver (spec §34.7): verify signature, store, dedupe,
     * process on the queue, answer fast.
     */
    public function cashfree(Request $request, PaymentGatewayInterface $gateway): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('x-webhook-signature', '');
        $timestamp = (string) $request->header('x-webhook-timestamp', '');

        $valid = $gateway->verifyWebhookSignature($rawBody, $signature, $timestamp);

        if (! $valid) {
            // Reject invalid signatures outright; log nothing sensitive.
            \Illuminate\Support\Facades\Log::warning('Cashfree webhook rejected: bad signature');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();
        $eventType = data_get($payload, 'type');
        $eventId = data_get($payload, 'event_id')
            ?? data_get($payload, 'data.payment.cf_payment_id');

        // Idempotency: identical deliveries are stored once and processed once.
        $dedupeHash = hash('sha256', ($eventId !== null ? (string) $eventId : '') . '|' . $eventType . '|' . $rawBody);

        $webhook = PaymentWebhook::firstOrCreate(
            ['dedupe_hash' => $dedupeHash],
            [
                'gateway' => 'cashfree',
                'event_id' => $eventId !== null ? (string) $eventId : null,
                'event_type' => $eventType,
                'payload' => $payload,
                'signature_valid' => true,
            ],
        );

        if ($webhook->wasRecentlyCreated) {
            ProcessCashfreeWebhook::dispatch($webhook->id);
        }

        return response()->json(['message' => 'ok']);
    }
}
