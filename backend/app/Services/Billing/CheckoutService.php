<?php

namespace App\Services\Billing;

use App\Models\CouponUsage;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected CouponService $coupons,
    ) {
    }

    /** Price + coupon + tax breakdown, all in paise. Backend is the only calculator (spec §34.12). */
    public function quote(User $user, Plan $plan, string $frequency, ?string $couponCode): array
    {
        $base = Money::toPaise($frequency === 'annual' ? $plan->annual_price : $plan->monthly_price);

        if ($base <= 0) {
            throw ValidationException::withMessages(['plan' => ['This plan cannot be purchased online.']]);
        }

        $coupon = null;
        $discount = 0;
        if ($couponCode) {
            ['coupon' => $coupon, 'discount_paise' => $discount] =
                $this->coupons->discountFor($couponCode, $user, $plan, $frequency, $base);
        }

        $taxable = $base - $discount;
        $tax = Money::percentOf($taxable, (int) config('mypa.billing.tax_percent_bp'));

        return [
            'plan' => $plan,
            'coupon' => $coupon,
            'base' => $base,
            'discount' => $discount,
            'tax' => $tax,
            'tax_label' => config('mypa.billing.tax_label'),
            'tax_percent_bp' => (int) config('mypa.billing.tax_percent_bp'),
            'total' => $taxable + $tax,
            'currency' => $plan->currency ?: 'INR',
        ];
    }

    /** Create the internal order + gateway order and return checkout info. */
    public function begin(User $user, Plan $plan, string $frequency, ?string $couponCode): PaymentOrder
    {
        if (! $this->gateway->isConfigured()) {
            abort(503, 'Online payments are not configured yet. Contact support.');
        }

        $quote = $this->quote($user, $plan, $frequency, $couponCode);

        // Reuse a still-payable identical order instead of stacking duplicates
        // (spec §34.21: prevent duplicate orders on multiple clicks).
        $existing = PaymentOrder::where('user_id', $user->id)
            ->where('plan_id', $plan->id)
            ->where('billing_frequency', $frequency)
            ->where('total_amount', $quote['total'])
            ->whereIn('status', ['created', 'pending'])
            ->where('expires_at', '>', now()->addMinutes(5))
            ->latest()
            ->first();

        if ($existing && $existing->payment_session_id) {
            return $existing;
        }

        $order = DB::transaction(function () use ($user, $plan, $frequency, $quote) {
            $order = PaymentOrder::create([
                'order_number' => 'MYPA' . now()->format('ymd') . strtoupper(Str::random(8)),
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'billing_frequency' => $frequency,
                'base_amount' => $quote['base'],
                'discount_amount' => $quote['discount'],
                'tax_amount' => $quote['tax'],
                'total_amount' => $quote['total'],
                'currency' => $quote['currency'],
                'coupon_id' => $quote['coupon']?->id,
                'status' => 'created',
                'idempotency_key' => (string) Str::uuid(),
                'customer_snapshot' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'app_id' => $user->appId?->app_id,
                ],
                'expires_at' => now()->addMinutes((int) config('mypa.billing.order_expiry_minutes')),
            ]);

            if ($quote['coupon']) {
                CouponUsage::create([
                    'coupon_id' => $quote['coupon']->id,
                    'user_id' => $user->id,
                    'payment_order_id' => $order->id,
                ]);
            }

            return $order;
        });

        $gateway = $this->gateway->createOrder($order->load(['user', 'plan']));

        $order->update([
            'status' => 'pending',
            'gateway_order_id' => $gateway['gateway_order_id'],
            'payment_session_id' => $gateway['payment_session_id'],
            'gateway_response' => $gateway['raw'],
        ]);

        return $order->fresh();
    }
}
