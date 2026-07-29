<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\CouponService;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\PaymentVerificationService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BillingController extends Controller
{
    /** Quote (and coupon validation) without creating anything. */
    public function quote(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'exists:plans,slug'],
            'frequency' => ['required', 'in:monthly,annual'],
            'coupon' => ['nullable', 'string', 'max:64'],
        ]);

        $plan = Plan::where('slug', $data['plan_slug'])->where('is_active', true)->firstOrFail();
        $quote = $checkout->quote($request->user(), $plan, $data['frequency'], $data['coupon'] ?? null);

        return response()->json([
            'data' => [
                'plan' => $plan->slug,
                'frequency' => $data['frequency'],
                'base' => Money::toDecimalString($quote['base']),
                'discount' => Money::toDecimalString($quote['discount']),
                'tax' => Money::toDecimalString($quote['tax']),
                'tax_label' => $quote['tax_label'],
                'tax_percent' => $quote['tax_percent_bp'] / 100,
                'total' => Money::toDecimalString($quote['total']),
                'currency' => $quote['currency'],
                'coupon_applied' => $quote['coupon']?->code,
            ],
        ]);
    }

    /** Create internal + gateway order; returns the Cashfree payment session. */
    public function checkout(Request $request, CheckoutService $checkout, PaymentGatewayInterface $gateway): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'exists:plans,slug'],
            'frequency' => ['required', 'in:monthly,annual'],
            'coupon' => ['nullable', 'string', 'max:64'],
        ]);

        $plan = Plan::where('slug', $data['plan_slug'])
            ->where('is_active', true)->where('is_public', true)
            ->firstOrFail();

        $order = $checkout->begin($request->user(), $plan, $data['frequency'], $data['coupon'] ?? null);

        return response()->json([
            'message' => 'Checkout ready.',
            'data' => [
                'order_uuid' => $order->uuid,
                'order_number' => $order->order_number,
                'payment_session_id' => $order->payment_session_id,
                'total' => Money::toDecimalString($order->total_amount),
                'currency' => $order->currency,
                'gateway_mode' => $gateway->mode(),
                'expires_at' => $order->expires_at,
            ],
        ], 201);
    }

    /** Server-side verification after redirect (and pollable by the status page). */
    public function verifyOrder(Request $request, PaymentOrder $order, PaymentVerificationService $verifier): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $order = $verifier->verify($order);

        return response()->json([
            'data' => [
                'order_uuid' => $order->uuid,
                'status' => $order->status,
                'plan' => $order->plan->slug,
                'total' => Money::toDecimalString($order->total_amount),
                'paid_at' => $order->paid_at,
            ],
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $payments = Payment::with(['order.plan', 'invoice'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        $payments->getCollection()->transform(fn ($payment) => [
            'uuid' => $payment->uuid,
            'amount' => Money::toDecimalString($payment->amount),
            'currency' => $payment->currency,
            'status' => $payment->status,
            'method' => $payment->method,
            'plan' => $payment->order->plan->name,
            'frequency' => $payment->order->billing_frequency,
            'order_number' => $payment->order->order_number,
            'invoice_uuid' => $payment->invoice?->uuid,
            'refunded' => Money::toDecimalString($payment->refundedAmount()),
            'paid_at' => $payment->paid_at,
        ]);

        return response()->json($payments);
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::where('user_id', $request->user()->id)
            ->latest('issued_at')
            ->paginate(20);

        $invoices->getCollection()->transform(fn ($invoice) => [
            'uuid' => $invoice->uuid,
            'invoice_number' => $invoice->invoice_number,
            'plan_name' => $invoice->plan_name,
            'total' => Money::toDecimalString($invoice->total_amount),
            'currency' => $invoice->currency,
            'issued_at' => $invoice->issued_at,
        ]);

        return response()->json($invoices);
    }

    /** Printable HTML invoice (browser print → PDF). */
    public function invoiceView(Request $request, Invoice $invoice): Response
    {
        abort_unless(
            $invoice->user_id === $request->user()->id || $request->user()->isAdmin(),
            403,
        );

        $html = view('invoices.show', [
            'invoice' => $invoice,
            'money' => fn (int $paise) => ($invoice->currency === 'INR' ? '₹' : $invoice->currency . ' ') . Money::toDecimalString($paise),
        ])->render();

        return response($html)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Cancel auto-continuation: access remains until the paid period ends. */
    public function cancelSubscription(Request $request): JsonResponse
    {
        $subscription = Subscription::where('user_id', $request->user()->id)
            ->whereIn('status', ['active', 'trial'])
            ->whereNotNull('ends_at')
            ->latest('started_at')
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No paid subscription to cancel.'], 404);
        }

        $subscription->update(['cancelled_at' => now()]);

        return response()->json([
            'message' => 'Subscription cancelled. Your plan stays active until '
                . $subscription->ends_at->toFormattedDateString()
                . ', then your account moves to the Free plan.',
        ]);
    }
}
