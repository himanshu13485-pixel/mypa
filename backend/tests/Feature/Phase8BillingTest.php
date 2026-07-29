<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\FakePaymentGateway;
use App\Services\Billing\PaymentGatewayInterface;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase8BillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, PlanSeeder::class]);
        $this->user = User::factory()->create();
        $this->user->settings()->create([]);

        $this->gateway = $this->app->make(PaymentGatewayInterface::class);
        config(['mypa.cashfree.secret_key' => 'test-secret']);
    }

    protected function checkout(string $plan = 'personal', string $frequency = 'monthly', ?string $coupon = null): array
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/subscription/checkout', array_filter([
            'plan_slug' => $plan,
            'frequency' => $frequency,
            'coupon' => $coupon,
        ]));
        $response->assertCreated();

        return $response->json('data');
    }

    // --- Quotes & tax --------------------------------------------------------

    public function test_quote_applies_tax_on_base(): void
    {
        // Personal monthly = ₹99.00 → GST 18% = ₹17.82 → total ₹116.82
        $this->actingAs($this->user)->postJson('/api/v1/subscription/quote', [
            'plan_slug' => 'personal',
            'frequency' => 'monthly',
        ])->assertOk()
            ->assertJsonPath('data.base', '99.00')
            ->assertJsonPath('data.tax', '17.82')
            ->assertJsonPath('data.total', '116.82');
    }

    public function test_percent_coupon_with_cap(): void
    {
        Coupon::create([
            'code' => 'HALF', 'title' => '50% off', 'discount_type' => 'percent',
            'discount_value' => 5000, // 50%
            'max_discount_amount' => 4000, // capped at ₹40
            'is_active' => true,
        ]);

        // Base 9900 → 50% = 4950 → capped 4000 → taxable 5900 → tax 1062 → total 6962
        $this->actingAs($this->user)->postJson('/api/v1/subscription/quote', [
            'plan_slug' => 'personal', 'frequency' => 'monthly', 'coupon' => 'HALF',
        ])->assertOk()
            ->assertJsonPath('data.discount', '40.00')
            ->assertJsonPath('data.total', '69.62')
            ->assertJsonPath('data.coupon_applied', 'HALF');
    }

    public function test_invalid_coupons_rejected(): void
    {
        Coupon::create([
            'code' => 'EXPIRED', 'title' => 'Old', 'discount_type' => 'fixed',
            'discount_value' => 1000, 'expires_at' => now()->subDay(), 'is_active' => true,
        ]);
        Coupon::create([
            'code' => 'FAMONLY', 'title' => 'Family only', 'discount_type' => 'fixed',
            'discount_value' => 1000, 'applicable_plans' => ['family'], 'is_active' => true,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/subscription/quote', [
            'plan_slug' => 'personal', 'frequency' => 'monthly', 'coupon' => 'EXPIRED',
        ])->assertUnprocessable();

        $this->actingAs($this->user)->postJson('/api/v1/subscription/quote', [
            'plan_slug' => 'personal', 'frequency' => 'monthly', 'coupon' => 'FAMONLY',
        ])->assertUnprocessable();
    }

    // --- Checkout ------------------------------------------------------------

    public function test_checkout_creates_order_with_session(): void
    {
        $data = $this->checkout();

        $this->assertNotEmpty($data['payment_session_id']);
        $this->assertEquals('116.82', $data['total']);
        $this->assertDatabaseHas('payment_orders', [
            'user_id' => $this->user->id,
            'status' => 'pending',
            'total_amount' => 11682,
        ]);
    }

    public function test_double_click_reuses_pending_order(): void
    {
        $first = $this->checkout();
        $second = $this->checkout();

        $this->assertEquals($first['order_uuid'], $second['order_uuid']);
        $this->assertEquals(1, PaymentOrder::count());
    }

    // --- Verification & activation -------------------------------------------

    public function test_paid_order_activates_subscription_idempotently(): void
    {
        $data = $this->checkout();
        $order = PaymentOrder::where('uuid', $data['order_uuid'])->first();

        $this->gateway->markPaid($order->gateway_order_id);

        // Verify twice (redirect + webhook race).
        $this->actingAs($this->user)->postJson("/api/v1/payments/{$order->uuid}/verify")
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->actingAs($this->user)->postJson("/api/v1/payments/{$order->uuid}/verify")
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertEquals(1, Payment::count());
        $this->assertEquals(1, Invoice::count());
        $this->assertEquals(1, Subscription::where('status', 'active')->count());

        // Entitlements now resolve to the paid plan.
        $this->actingAs($this->user)->getJson('/api/v1/subscription')
            ->assertJsonPath('data.plan.slug', 'personal');

        $invoice = Invoice::first();
        $this->assertStringStartsWith('MYPA-INV-', $invoice->invoice_number);
        $this->assertEquals(11682, $invoice->total_amount);
    }

    public function test_amount_mismatch_blocks_activation(): void
    {
        $data = $this->checkout();
        $order = PaymentOrder::where('uuid', $data['order_uuid'])->first();

        // Gateway reports a tampered lower amount.
        $this->gateway->markPaid($order->gateway_order_id, 100);

        $this->actingAs($this->user)->postJson("/api/v1/payments/{$order->uuid}/verify")
            ->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertEquals(0, Subscription::where('status', 'active')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.amount_mismatch']);
    }

    public function test_only_owner_can_verify_order(): void
    {
        $data = $this->checkout();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/payments/{$data['order_uuid']}/verify")
            ->assertForbidden();
    }

    // --- Webhooks -------------------------------------------------------------

    protected function signedWebhook(array $payload): array
    {
        $body = json_encode($payload);
        $timestamp = (string) now()->timestamp;
        $signature = base64_encode(hash_hmac('sha256', $timestamp . $body, 'test-secret', true));

        return [$body, $timestamp, $signature];
    }

    public function test_webhook_signature_validation_and_dedupe(): void
    {
        $data = $this->checkout();
        $order = PaymentOrder::where('uuid', $data['order_uuid'])->first();
        $this->gateway->markPaid($order->gateway_order_id);

        $payload = [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_id' => 'evt_1',
            'data' => ['order' => ['order_id' => $order->order_number]],
        ];
        [$body, $timestamp, $signature] = $this->signedWebhook($payload);

        // Invalid signature → 401, nothing stored.
        $this->call('POST', '/api/webhooks/cashfree', [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => 'garbage',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);
        $this->assertDatabaseCount('payment_webhooks', 0);

        // Valid → accepted and processed (sync queue in tests).
        $this->call('POST', '/api/webhooks/cashfree', [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        // Duplicate delivery → still one stored webhook, one payment.
        $this->call('POST', '/api/webhooks/cashfree', [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertDatabaseCount('payment_webhooks', 1);
        $this->assertEquals(1, Payment::count());
        $this->assertEquals('paid', $order->fresh()->status);
        $this->assertEquals(1, Subscription::where('status', 'active')->count());
    }

    // --- Invoices -------------------------------------------------------------

    public function test_invoice_html_visible_to_owner_only(): void
    {
        $data = $this->checkout();
        $order = PaymentOrder::where('uuid', $data['order_uuid'])->first();
        $this->gateway->markPaid($order->gateway_order_id);
        $this->actingAs($this->user)->postJson("/api/v1/payments/{$order->uuid}/verify");

        $invoice = Invoice::first();

        $view = $this->actingAs($this->user)->get("/api/v1/invoices/{$invoice->uuid}");
        $view->assertOk();
        $this->assertStringContainsString($invoice->invoice_number, $view->getContent());
        $this->assertStringContainsString('116.82', $view->getContent());

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/api/v1/invoices/{$invoice->uuid}")->assertForbidden();
    }

    // --- Cancellation & lifecycle ---------------------------------------------

    public function test_cancel_keeps_access_until_period_end_then_expires(): void
    {
        $data = $this->checkout();
        $order = PaymentOrder::where('uuid', $data['order_uuid'])->first();
        $this->gateway->markPaid($order->gateway_order_id);
        $this->actingAs($this->user)->postJson("/api/v1/payments/{$order->uuid}/verify");

        $this->actingAs($this->user)->postJson('/api/v1/subscription/cancel')->assertOk();

        // Still on the paid plan until ends_at…
        $this->actingAs($this->user)->getJson('/api/v1/subscription')
            ->assertJsonPath('data.plan.slug', 'personal');

        // …then the lifecycle job expires it.
        Subscription::query()->update(['ends_at' => now()->subDay()]);
        $this->artisan('mypa:subscription-lifecycle')->assertSuccessful();

        $this->actingAs($this->user)->getJson('/api/v1/subscription')
            ->assertJsonPath('data.plan.slug', 'free');
        $this->assertDatabaseHas('subscriptions', ['status' => 'expired']);
    }

    public function test_renewal_reminder_sent_at_configured_interval(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => \App\Models\Plan::where('slug', 'personal')->first()->id,
            'status' => 'active',
            'started_at' => now()->subDays(27),
            'ends_at' => now()->addDays(3),
        ]);

        $this->artisan('mypa:subscription-lifecycle')->assertSuccessful();

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $this->user,
            \App\Notifications\SubscriptionLifecycleNotification::class,
            fn ($n) => $n->event === 'renewal_reminder' && $n->daysLeft === 3,
        );
    }

    // --- Refunds --------------------------------------------------------------

    public function test_partial_refund_and_over_refund_guard(): void
    {
        $data = $this->checkout();
        $order = PaymentOrder::where('uuid', $data['order_uuid'])->first();
        $this->gateway->markPaid($order->gateway_order_id);
        $this->actingAs($this->user)->postJson("/api/v1/payments/{$order->uuid}/verify");

        $payment = Payment::first();
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        // Partial refund of ₹50.
        $this->actingAs($superAdmin)
            ->postJson("/api/v1/admin/billing/payments/{$payment->uuid}/refund", [
                'amount' => 5000, 'reason' => 'Goodwill',
            ])->assertCreated();

        $this->assertEquals('partially_refunded', $payment->fresh()->status);

        // Over-refund rejected (refundable = 11682 - 5000 = 6682).
        $this->actingAs($superAdmin)
            ->postJson("/api/v1/admin/billing/payments/{$payment->uuid}/refund", [
                'amount' => 7000,
            ])->assertUnprocessable();

        // Non-super-admin cannot refund.
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $this->actingAs($admin)
            ->postJson("/api/v1/admin/billing/payments/{$payment->uuid}/refund", ['amount' => 100])
            ->assertForbidden();
    }
}
