<?php

namespace App\Services\Billing;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Plan;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class CouponService
{
    /**
     * Validate a coupon for a user/plan/frequency and return the discount in
     * paise for the given base amount. Backend-only validation (spec §34.11).
     */
    public function discountFor(string $code, User $user, Plan $plan, string $frequency, int $basePaise): array
    {
        $coupon = Coupon::whereRaw('LOWER(code) = ?', [mb_strtolower(trim($code))])->first();

        $fail = fn (string $message) => throw ValidationException::withMessages(['coupon' => [$message]]);

        if (! $coupon || ! $coupon->is_active) {
            $fail('This coupon code is not valid.');
        }
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            $fail('This coupon is not active yet.');
        }
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            $fail('This coupon has expired.');
        }
        if ($coupon->applicable_plans && ! in_array($plan->slug, $coupon->applicable_plans, true)) {
            $fail('This coupon does not apply to the ' . $plan->name . ' plan.');
        }
        if ($coupon->applicable_frequencies && ! in_array($frequency, $coupon->applicable_frequencies, true)) {
            $fail('This coupon does not apply to ' . $frequency . ' billing.');
        }
        if ($coupon->min_order_amount && $basePaise < $coupon->min_order_amount) {
            $fail('Order amount is below this coupon\'s minimum (₹' . Money::toDecimalString((int) $coupon->min_order_amount) . ').');
        }
        if ($coupon->max_uses !== null && $coupon->usages()->count() >= $coupon->max_uses) {
            $fail('This coupon has reached its usage limit.');
        }
        if ($coupon->per_user_limit !== null
            && CouponUsage::where('coupon_id', $coupon->id)->where('user_id', $user->id)->count() >= $coupon->per_user_limit) {
            $fail('You have already used this coupon.');
        }
        if ($coupon->new_users_only && $user->created_at->lt(now()->subDays(30))) {
            $fail('This coupon is for new users only.');
        }

        $discount = $coupon->discount_type === 'fixed'
            ? (int) $coupon->discount_value
            : Money::percentOf($basePaise, (int) $coupon->discount_value);

        if ($coupon->max_discount_amount !== null) {
            $discount = min($discount, (int) $coupon->max_discount_amount);
        }

        // Never discount below zero (spec: prevent negative totals).
        $discount = min($discount, $basePaise);

        return ['coupon' => $coupon, 'discount_paise' => $discount];
    }
}
