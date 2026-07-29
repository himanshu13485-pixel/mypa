<?php

namespace App\Services\Billing;

use App\Models\PaymentOrder;

/**
 * In-memory gateway for automated tests (spec §34.23: never hit the real
 * gateway in tests). Orders can be marked paid/failed by the test.
 */
class FakePaymentGateway implements PaymentGatewayInterface
{
    /** @var array<string, array> keyed by gateway order id */
    public array $orders = [];

    public bool $configured = true;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function mode(): string
    {
        return 'sandbox';
    }

    /** Lookups accept either the fake gateway id or the merchant order number. */
    protected function key(string $id): string
    {
        return str_replace('cf_fake_', '', $id);
    }

    public function createOrder(PaymentOrder $order): array
    {
        $id = $this->key($order->order_number);
        $this->orders[$id] = [
            'status' => 'ACTIVE',
            'amount_paise' => $order->total_amount,
            'currency' => $order->currency,
            'payment_id' => null,
            'method' => null,
        ];

        return [
            'gateway_order_id' => 'cf_fake_' . $id,
            'payment_session_id' => 'session_fake_' . $order->order_number,
            'raw' => ['fake' => true],
        ];
    }

    public function markPaid(string $gatewayOrderId, ?int $amountPaise = null): void
    {
        $key = $this->key($gatewayOrderId);
        $this->orders[$key]['status'] = 'PAID';
        $this->orders[$key]['payment_id'] = 'cfpay_' . substr(md5($key), 0, 10);
        $this->orders[$key]['method'] = 'upi';
        if ($amountPaise !== null) {
            $this->orders[$key]['amount_paise'] = $amountPaise;
        }
    }

    public function markFailed(string $gatewayOrderId): void
    {
        $this->orders[$this->key($gatewayOrderId)]['status'] = 'TERMINATED';
    }

    public function fetchOrderStatus(string $gatewayOrderId): array
    {
        $order = $this->orders[$this->key($gatewayOrderId)]
            ?? ['status' => 'CREATED', 'amount_paise' => 0, 'currency' => 'INR', 'payment_id' => null, 'method' => null];

        return $order + ['raw' => ['fake' => true]];
    }

    public function verifyWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
    {
        // Same HMAC scheme as Cashfree, using the configured (test) secret.
        $secret = (string) config('mypa.cashfree.secret_key', 'test-secret');
        $computed = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));

        return hash_equals($computed, $signature);
    }

    public function createRefund(string $gatewayOrderId, string $refundUuid, int $amountPaise, ?string $reason): array
    {
        return [
            'gateway_refund_id' => 'cfrefund_' . substr(md5($refundUuid), 0, 10),
            'status' => 'SUCCESS',
            'raw' => ['fake' => true],
        ];
    }
}
