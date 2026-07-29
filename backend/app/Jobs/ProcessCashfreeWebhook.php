<?php

namespace App\Jobs;

use App\Models\PaymentOrder;
use App\Models\PaymentWebhook;
use App\Services\Billing\PaymentVerificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCashfreeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $webhookId)
    {
    }

    public function handle(PaymentVerificationService $verifier): void
    {
        $webhook = PaymentWebhook::find($this->webhookId);

        if (! $webhook || $webhook->processed_at) {
            return;
        }

        try {
            $orderNumber = data_get($webhook->payload, 'data.order.order_id')
                ?? data_get($webhook->payload, 'data.order.order_number');

            if ($orderNumber) {
                $order = PaymentOrder::where('order_number', $orderNumber)->first();
                if ($order) {
                    // The webhook is the trigger; the truth comes from a
                    // server-to-server status fetch inside verify().
                    $verifier->verify($order);
                }
            }

            $webhook->update(['processed_at' => now(), 'processing_error' => null]);
        } catch (\Throwable $e) {
            $webhook->update(['processing_error' => mb_substr($e->getMessage(), 0, 480)]);
            throw $e; // retry via queue backoff
        }
    }
}
