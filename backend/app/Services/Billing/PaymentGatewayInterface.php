<?php

namespace App\Services\Billing;

use App\Models\PaymentOrder;

/**
 * Gateway abstraction (spec §34.20): the application talks only to this
 * interface. Cashfree is the first implementation; other gateways can be added
 * without touching the subscription module.
 */
interface PaymentGatewayInterface
{
    /** Whether credentials are configured and online payments can run. */
    public function isConfigured(): bool;

    /** 'sandbox' | 'production' */
    public function mode(): string;

    /**
     * Create the gateway order for an internal payment order.
     *
     * @return array{gateway_order_id: string, payment_session_id: string, raw: array}
     */
    public function createOrder(PaymentOrder $order): array;

    /**
     * Fetch the authoritative order status from the gateway.
     *
     * @return array{status: string, amount_paise: int, currency: string,
     *               payment_id: ?string, method: ?string, raw: array}
     *         status one of: PAID, FAILED, PENDING, EXPIRED, CANCELLED, CREATED
     */
    public function fetchOrderStatus(string $gatewayOrderId): array;

    /** Verify a webhook signature (raw body + headers). */
    public function verifyWebhookSignature(string $rawBody, string $signature, string $timestamp): bool;

    /**
     * Create a refund at the gateway.
     *
     * @return array{gateway_refund_id: ?string, status: string, raw: array}
     */
    public function createRefund(string $gatewayOrderId, string $refundUuid, int $amountPaise, ?string $reason): array;
}
