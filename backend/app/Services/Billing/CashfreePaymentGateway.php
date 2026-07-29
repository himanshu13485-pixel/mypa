<?php

namespace App\Services\Billing;

use App\Models\PaymentOrder;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cashfree PG REST integration (orders API). Sandbox and production are
 * selected purely by environment configuration; secrets never leave the
 * backend (spec §34.4, §34.22).
 */
class CashfreePaymentGateway implements PaymentGatewayInterface
{
    public function isConfigured(): bool
    {
        return (bool) (config('mypa.cashfree.app_id') && config('mypa.cashfree.secret_key'));
    }

    public function mode(): string
    {
        return config('mypa.cashfree.env', 'sandbox');
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(config('mypa.cashfree.base_url'))
            ->withHeaders([
                'x-client-id' => config('mypa.cashfree.app_id'),
                'x-client-secret' => config('mypa.cashfree.secret_key'),
                'x-api-version' => config('mypa.cashfree.api_version'),
            ])
            ->acceptJson()
            ->timeout(20);
    }

    public function createOrder(PaymentOrder $order): array
    {
        $user = $order->user;

        $response = $this->http()->post('/orders', [
            'order_id' => $order->order_number,
            'order_amount' => (float) Money::toDecimalString($order->total_amount),
            'order_currency' => $order->currency,
            'customer_details' => [
                'customer_id' => $user->uuid,
                'customer_name' => $user->name,
                'customer_email' => $user->email,
                'customer_phone' => $user->mobile ?: '9999999999',
            ],
            'order_meta' => [
                'return_url' => config('mypa.frontend_url') . '/payment/status?order=' . $order->uuid,
                'notify_url' => config('app.url') . '/api/webhooks/cashfree',
            ],
            'order_expiry_time' => $order->expires_at?->toIso8601String(),
            'order_note' => 'My PA ' . $order->plan->name . ' (' . $order->billing_frequency . ')',
        ]);

        $response->throw();
        $data = $response->json();

        return [
            'gateway_order_id' => $data['cf_order_id'] ?? $data['order_id'] ?? $order->order_number,
            'payment_session_id' => $data['payment_session_id'],
            'raw' => $data,
        ];
    }

    public function fetchOrderStatus(string $gatewayOrderId): array
    {
        // Orders are addressed by our order_id (order_number) in the PG API.
        $order = $this->http()->get('/orders/' . $gatewayOrderId);
        $order->throw();
        $data = $order->json();

        $paymentId = null;
        $method = null;
        if (($data['order_status'] ?? null) === 'PAID') {
            $payments = $this->http()->get('/orders/' . $gatewayOrderId . '/payments');
            if ($payments->successful()) {
                $success = collect($payments->json())->firstWhere('payment_status', 'SUCCESS');
                $paymentId = $success['cf_payment_id'] ?? null;
                $method = isset($success['payment_group']) ? (string) $success['payment_group'] : null;
                $paymentId = $paymentId !== null ? (string) $paymentId : null;
            }
        }

        return [
            'status' => $data['order_status'] ?? 'CREATED', // PAID | ACTIVE | EXPIRED | TERMINATED…
            'amount_paise' => Money::toPaise((string) ($data['order_amount'] ?? '0')),
            'currency' => $data['order_currency'] ?? 'INR',
            'payment_id' => $paymentId,
            'method' => $method,
            'raw' => $data,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
    {
        $secret = (string) config('mypa.cashfree.secret_key');
        if ($secret === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));

        return hash_equals($computed, $signature);
    }

    public function createRefund(string $gatewayOrderId, string $refundUuid, int $amountPaise, ?string $reason): array
    {
        $response = $this->http()->post('/orders/' . $gatewayOrderId . '/refunds', [
            'refund_id' => $refundUuid,
            'refund_amount' => (float) Money::toDecimalString($amountPaise),
            'refund_note' => $reason ?: 'Refund',
        ]);

        $response->throw();
        $data = $response->json();

        return [
            'gateway_refund_id' => isset($data['cf_refund_id']) ? (string) $data['cf_refund_id'] : null,
            'status' => $data['refund_status'] ?? 'PENDING',
            'raw' => $data,
        ];
    }
}
