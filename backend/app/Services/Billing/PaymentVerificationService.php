<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Notifications\PaymentOutcomeNotification;
use Illuminate\Support\Facades\DB;

/**
 * The single authority that turns a gateway "PAID" into an active
 * subscription. Fully idempotent: redirects, webhooks, and manual verify calls
 * can all race — exactly one payment, one subscription period, and one invoice
 * result (spec §34.8).
 */
class PaymentVerificationService
{
    public function __construct(protected PaymentGatewayInterface $gateway)
    {
    }

    public function verify(PaymentOrder $order): PaymentOrder
    {
        if (in_array($order->status, ['paid', 'cancelled'])) {
            return $order; // already settled
        }

        // Authoritative status comes from the gateway backend — never from the
        // browser redirect (spec §34.5.20, §34.22).
        $status = $this->gateway->fetchOrderStatus($order->order_number);

        return DB::transaction(function () use ($order, $status) {
            /** @var PaymentOrder $locked */
            $locked = PaymentOrder::whereKey($order->id)->lockForUpdate()->first();

            if ($locked->status === 'paid') {
                return $locked; // another caller won the race
            }

            if ($status['status'] === 'PAID') {
                // Strict amount + currency validation before activation.
                if ($status['amount_paise'] !== (int) $locked->total_amount
                    || $status['currency'] !== $locked->currency) {
                    $locked->update([
                        'status' => 'failed',
                        'gateway_response' => $status['raw'],
                    ]);
                    \App\Models\AuditLog::record(null, 'payment.amount_mismatch', $locked, [
                        'expected' => $locked->total_amount,
                        'received' => $status['amount_paise'],
                    ]);

                    return $locked;
                }

                $locked->update(['status' => 'paid', 'paid_at' => now(), 'gateway_response' => $status['raw']]);

                $payment = Payment::create([
                    'payment_order_id' => $locked->id,
                    'user_id' => $locked->user_id,
                    'gateway_payment_id' => $status['payment_id'],
                    'amount' => $locked->total_amount,
                    'currency' => $locked->currency,
                    'status' => 'successful',
                    'method' => $status['method'],
                    'gateway_response' => $status['raw'],
                    'paid_at' => now(),
                ]);

                $subscription = $this->activateSubscription($locked);
                $this->issueInvoice($locked, $payment, $subscription);

                $locked->user->notify(new PaymentOutcomeNotification($locked, 'successful'));
            } elseif (in_array($status['status'], ['EXPIRED', 'TERMINATED', 'TERMINATION_REQUESTED'])) {
                $locked->update(['status' => 'failed', 'gateway_response' => $status['raw']]);
                $locked->user->notify(new PaymentOutcomeNotification($locked, 'failed'));
            }
            // ACTIVE / CREATED → still pending; leave untouched.

            return $locked->fresh();
        });
    }

    protected function activateSubscription(PaymentOrder $order): Subscription
    {
        // Retire the current subscription; the paid one takes over.
        Subscription::where('user_id', $order->user_id)
            ->whereIn('status', ['active', 'trial'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return Subscription::create([
            'user_id' => $order->user_id,
            'plan_id' => $order->plan_id,
            'status' => 'active',
            'started_at' => now(),
            'ends_at' => $order->billing_frequency === 'annual'
                ? now()->addYear()
                : now()->addMonthNoOverflow(),
            'note' => 'Paid order ' . $order->order_number,
        ]);
    }

    protected function issueInvoice(PaymentOrder $order, Payment $payment, Subscription $subscription): Invoice
    {
        $year = now()->format('Y');
        $sequence = Invoice::where('invoice_number', 'like', "MYPA-INV-{$year}-%")->count() + 1;

        return Invoice::create([
            'invoice_number' => sprintf('MYPA-INV-%s-%05d', $year, $sequence),
            'user_id' => $order->user_id,
            'payment_id' => $payment->id,
            'plan_name' => $order->plan->name,
            'billing_frequency' => $order->billing_frequency,
            'period_starts_on' => $subscription->started_at->toDateString(),
            'period_ends_on' => $subscription->ends_at->toDateString(),
            'base_amount' => $order->base_amount,
            'discount_amount' => $order->discount_amount,
            'tax_amount' => $order->tax_amount,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'tax_label' => config('mypa.billing.tax_label'),
            'tax_percent_bp' => (int) config('mypa.billing.tax_percent_bp'),
            'billing_snapshot' => [
                'buyer' => $order->customer_snapshot,
                'seller' => config('mypa.billing.seller'),
                'gateway_order_id' => $order->gateway_order_id,
                'gateway_payment_id' => $payment->gateway_payment_id,
                'order_number' => $order->order_number,
            ],
            'issued_at' => now(),
        ]);
    }
}
