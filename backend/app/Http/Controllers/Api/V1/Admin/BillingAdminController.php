<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Models\Refund;
use App\Services\Billing\PaymentGatewayInterface;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingAdminController extends Controller
{
    // --- Payments -----------------------------------------------------------

    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with(['user:id,uuid,name,email', 'order.plan:id,slug,name', 'invoice:id,uuid,payment_id,invoice_number']);

        if ($q = $request->query('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('gateway_payment_id', 'like', "%{$q}%")
                    ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$q}%"))
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"));
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->latest()->paginate(30));
    }

    public function webhooks(): JsonResponse
    {
        return response()->json(
            PaymentWebhook::latest()->paginate(30)
        );
    }

    // --- Coupons ------------------------------------------------------------

    public function coupons(): JsonResponse
    {
        return response()->json(['data' => Coupon::withCount('usages')->latest()->get()]);
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $data = $this->validatedCoupon($request);

        $coupon = Coupon::create($data);
        AuditLog::record($request->user(), 'coupon.created', $coupon, ['code' => $coupon->code]);

        return response()->json(['message' => 'Coupon created.', 'data' => $coupon], 201);
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $this->validatedCoupon($request, $coupon);

        $coupon->update($data);
        AuditLog::record($request->user(), 'coupon.updated', $coupon);

        return response()->json(['message' => 'Coupon updated.', 'data' => $coupon->fresh()]);
    }

    protected function validatedCoupon(Request $request, ?Coupon $coupon = null): array
    {
        return $request->validate([
            'code' => [$coupon ? 'sometimes' : 'required', 'string', 'max:64', 'unique:coupons,code' . ($coupon ? ',' . $coupon->id : '')],
            'title' => [$coupon ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => [$coupon ? 'sometimes' : 'required', 'in:fixed,percent'],
            // fixed: paise; percent: basis points (1000 = 10%)
            'discount_value' => [$coupon ? 'sometimes' : 'required', 'integer', 'min:1'],
            'max_discount_amount' => ['nullable', 'integer', 'min:1'],
            'min_order_amount' => ['nullable', 'integer', 'min:0'],
            'applicable_plans' => ['nullable', 'array'],
            'applicable_frequencies' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'new_users_only' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    // --- Refunds ------------------------------------------------------------

    public function refunds(): JsonResponse
    {
        return response()->json(
            Refund::with(['payment.user:id,uuid,name,email', 'payment.order:id,order_number,plan_id'])
                ->latest()->paginate(30)
        );
    }

    public function createRefund(Request $request, Payment $payment, PaymentGatewayInterface $gateway): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a super admin can issue refunds.');

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'], // paise
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        abort_unless($payment->status === 'successful' || $payment->status === 'partially_refunded', 422, 'Only successful payments can be refunded.');

        $refundable = $payment->amount - $payment->refundedAmount();
        if ($data['amount'] > $refundable) {
            return response()->json([
                'message' => 'Amount exceeds the refundable balance of ₹' . Money::toDecimalString($refundable) . '.',
            ], 422);
        }

        $refund = Refund::create([
            'payment_id' => $payment->id,
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        try {
            $result = $gateway->createRefund(
                $payment->order->order_number,
                $refund->uuid,
                (int) $data['amount'],
                $data['reason'] ?? null,
            );

            $refund->update([
                'gateway_refund_id' => $result['gateway_refund_id'],
                'status' => in_array($result['status'], ['SUCCESS', 'PROCESSED']) ? 'processed' : 'pending',
                'gateway_response' => $result['raw'],
                'processed_at' => in_array($result['status'], ['SUCCESS', 'PROCESSED']) ? now() : null,
            ]);
        } catch (\Throwable $e) {
            $refund->update(['status' => 'failed', 'gateway_response' => ['error' => mb_substr($e->getMessage(), 0, 400)]]);

            return response()->json(['message' => 'Gateway refund failed: ' . $e->getMessage()], 502);
        }

        // Reflect on the payment (spec §34.16 — refunds never auto-reactivate anything).
        $totalRefunded = $payment->refundedAmount();
        if ($totalRefunded >= $payment->amount) {
            $payment->update(['status' => 'refunded']);
        } elseif ($totalRefunded > 0) {
            $payment->update(['status' => 'partially_refunded']);
        }

        AuditLog::record($request->user(), 'refund.issued', $refund, [
            'payment' => $payment->uuid,
            'amount' => $data['amount'],
        ]);

        return response()->json(['message' => 'Refund initiated.', 'data' => $refund->fresh()], 201);
    }
}
