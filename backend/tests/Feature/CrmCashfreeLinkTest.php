<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentGateway;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\Crm\PaymentLink;
use App\Models\User;
use App\Services\Crm\CashfreeGateway;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cashfree payment links against a proforma or an invoice.
 *
 * Each company brings its own Cashfree account, so the credentials, the links
 * and the webhook URL are all per company. Paying a link goes through the
 * same settlement door an Admin uses: a proforma becomes a tax invoice and
 * the money is a receipt.
 */
class CrmCashfreeLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $clerkUser;
    protected Organization $org;
    protected int $issuingCompanyId;
    protected string $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->clerkUser = $this->makeUser('clerk@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->clerkUser->id, 'crm_role' => 'employee',
            'rights' => ['invoices' => ['view', 'create'], 'payments' => ['view', 'create']],
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel', 'email' => 'pay@bhavya.test', 'mobile' => '9825012345',
        ])->assertCreated()->json('data.uuid');
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function configure(string $mode = 'sandbox', bool $active = true): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-gateway', [
            'mode' => $mode, 'app_id' => 'TEST_APP_ID', 'secret' => 'TEST_SECRET', 'is_active' => $active,
        ])->assertOk();
    }

    private function document(string $kind = 'invoice', float $amount = 10000): string
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => $amount]],
        ])->assertCreated()->json('data.uuid');
    }

    private function fakeCashfree(): void
    {
        Http::fake(['*/pg/links' => Http::response([
            'cf_link_id' => 'CF-9911',
            'link_id' => 'stub',
            'link_status' => 'ACTIVE',
            'link_url' => 'https://payments-test.cashfree.com/links/abcd1234',
        ], 200)]);
    }

    /** The webhook Cashfree would send, signed as Cashfree signs it. */
    private function webhook(PaymentLink $link, array $overrides = []): array
    {
        $body = json_encode([
            'type' => 'PAYMENT_LINK_EVENT',
            'version' => 1,
            'event_time' => now()->toIso8601String(),
            'data' => array_replace([
                'link_id' => $link->link_id,
                'link_status' => 'PAID',
                'link_amount' => (float) $link->amount,
                'link_amount_paid' => (float) $link->amount,
                'order_id' => 'order_5521',
                'transaction_id' => 'txn_8890',
                'transaction_status' => 'SUCCESS',
            ], $overrides),
        ]);

        $timestamp = (string) now()->getTimestampMs();
        $signature = base64_encode(hash_hmac('sha256', $timestamp . $body, 'TEST_SECRET', true));

        return [$body, ['x-webhook-timestamp' => $timestamp, 'x-webhook-signature' => $signature]];
    }

    /** Post a raw body, the way Cashfree does — the signature is over it. */
    private function hook(string $body, array $headers, ?string $orgUuid = null)
    {
        return $this->call(
            'POST',
            '/api/v1/crm/webhooks/cashfree/' . ($orgUuid ?? $this->org->uuid),
            [], [], [],
            $this->transformHeadersToServerVars($headers + ['CONTENT_TYPE' => 'application/json']),
            $body,
        );
    }

    // ---- Setting the account up ---------------------------------------------

    public function test_the_secret_is_kept_but_never_handed_back(): void
    {
        $this->configure();

        $settings = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters/payment-gateway')
            ->assertOk()->json('data');

        $this->assertSame('TEST_APP_ID', $settings['app_id']);
        $this->assertTrue($settings['has_secret']);
        $this->assertArrayNotHasKey('secret', $settings);
        $this->assertStringContainsString($this->org->uuid, $settings['webhook_url']);

        // On disk it is encrypted, not readable.
        $this->assertNotSame('TEST_SECRET', PaymentGateway::firstOrFail()->getRawOriginal('secret'));
        $this->assertSame('TEST_SECRET', PaymentGateway::firstOrFail()->secret);
    }

    public function test_the_secret_survives_an_edit_that_leaves_it_blank(): void
    {
        $this->configure();

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-gateway', [
            'mode' => 'production', 'app_id' => 'LIVE_APP_ID', 'is_active' => true,
        ])->assertOk();

        $account = PaymentGateway::firstOrFail();
        $this->assertSame('LIVE_APP_ID', $account->app_id);
        $this->assertSame('TEST_SECRET', $account->secret);
        $this->assertSame('https://api.cashfree.com/pg', $account->baseUrl());
    }

    // ---- Raising a link -----------------------------------------------------

    public function test_a_link_is_raised_against_a_proforma_for_what_is_owed(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $proforma = $this->document('proforma');

        $link = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$proforma}/payment-links", [])
            ->assertCreated()->json('data');

        $this->assertSame('https://payments-test.cashfree.com/links/abcd1234', $link['link_url']);
        $this->assertEquals(10000, $link['amount']);
        $this->assertSame('active', $link['status']);

        // What we actually asked Cashfree for.
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://sandbox.cashfree.com/pg/links'
                && $request->hasHeader('x-api-version', CashfreeGateway::API_VERSION)
                && $request->hasHeader('x-client-id', 'TEST_APP_ID')
                && $request->hasHeader('x-client-secret', 'TEST_SECRET')
                && $body['link_amount'] === 10000.0
                && $body['link_currency'] === 'INR'
                && $body['customer_details']['customer_phone'] === '9825012345'
                && str_contains($body['link_meta']['notify_url'], '/crm/webhooks/cashfree/');
        });

        $this->assertTrue(ActivityLog::where('action', 'payment.link_created')->exists());
    }

    public function test_a_second_link_for_the_same_money_is_not_raised_twice(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertCreated();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertOk();

        $this->assertSame(1, PaymentLink::count());
        Http::assertSentCount(1);
    }

    public function test_a_company_without_credentials_is_told_so(): void
    {
        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertStatus(422);

        $this->assertSame(0, PaymentLink::count());
    }

    public function test_a_client_with_no_mobile_cannot_be_sent_a_link(): void
    {
        $this->configure();
        $this->fakeCashfree();

        $clientUuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'No Phone Co', 'email' => 'a@nophone.test',
        ])->assertCreated()->json('data.uuid');

        $invoice = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice', 'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid, 'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'A', 'qty' => 1, 'unit_price' => 500]],
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_cashfree_refusing_the_link_is_reported_in_its_own_words(): void
    {
        $this->configure();
        Http::fake(['*/pg/links' => Http::response(['message' => 'link_amount : must be greater than 1'], 422)]);

        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertStatus(422);

        $this->assertSame(0, PaymentLink::count());
    }

    // ---- Being told it was paid ---------------------------------------------

    public function test_paying_a_proforma_link_converts_it_and_settles_the_money(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $proforma = $this->document('proforma');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$proforma}/payment-links", [])
            ->assertCreated();

        [$body, $headers] = $this->webhook(PaymentLink::firstOrFail());
        $this->hook($body, $headers)->assertOk();

        // The proforma became a tax invoice, and that invoice is paid.
        $invoice = Invoice::where('kind', 'invoice')->firstOrFail();
        $this->assertSame('INV-1', $invoice->number);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(1, $invoice->payments()->count());

        // The credit is in the inbox, already matched and settled.
        $entry = PaymentInboxEntry::firstOrFail();
        $this->assertSame('claimed', $entry->status);
        $this->assertSame('gateway', $entry->settlement_mode);
        $this->assertSame('PI-1', $entry->sourceProforma->number);
        $this->assertSame('txn_8890', $entry->reference_no);

        $this->assertSame('paid', PaymentLink::firstOrFail()->status);
        $this->assertTrue(ActivityLog::where('action', 'payment.link_paid')->exists());
        $this->assertTrue(ActivityLog::where('action', 'payment.settled')->exists());
    }

    public function test_the_same_webhook_twice_settles_once(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertCreated();

        [$body, $headers] = $this->webhook(PaymentLink::firstOrFail());
        $this->hook($body, $headers)->assertOk();
        $this->hook($body, $headers)->assertOk();

        $this->assertSame(1, PaymentInboxEntry::count());
        $this->assertSame(1, Invoice::where('kind', 'invoice')->firstOrFail()->payments()->count());
    }

    public function test_an_unsigned_webhook_is_refused(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertCreated();

        [$body, $headers] = $this->webhook(PaymentLink::firstOrFail());

        // No signature at all.
        $this->hook($body, [])->assertUnauthorized();

        // A signature over a different body: the raw payload is what is signed.
        $this->hook(str_replace('10000', '1', $body), $headers)->assertUnauthorized();

        // Someone else's company.
        $other = Organization::create(['name' => 'Globex Ltd', 'code' => 'GLOBEX']);
        $this->hook($body, $headers, $other->uuid)->assertUnauthorized();

        $this->assertSame(0, PaymentInboxEntry::count());
        $this->assertSame('active', PaymentLink::firstOrFail()->status);
    }

    public function test_a_part_payment_is_recorded_without_settling(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertCreated();

        [$body, $headers] = $this->webhook(PaymentLink::firstOrFail(), [
            'link_status' => 'PARTIALLY_PAID', 'link_amount_paid' => 2500,
        ]);
        $this->hook($body, $headers)->assertOk();

        $link = PaymentLink::firstOrFail();
        $this->assertSame('partially_paid', $link->status);
        $this->assertEquals(2500, $link->amount_paid);
        // Nothing is settled on a part payment: an Admin decides what it is.
        $this->assertSame(0, PaymentInboxEntry::count());
        $this->assertSame('due', Invoice::firstOrFail()->payment_status);
    }

    public function test_an_expired_link_is_marked_and_nothing_else(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertCreated();

        [$body, $headers] = $this->webhook(PaymentLink::firstOrFail(), [
            'link_status' => 'EXPIRED', 'link_amount_paid' => 0,
        ]);
        $this->hook($body, $headers)->assertOk();

        $this->assertSame('expired', PaymentLink::firstOrFail()->status);
        $this->assertSame(0, PaymentInboxEntry::count());
    }

    public function test_a_link_stays_inside_the_ledger_window(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $invoice = $this->document();   // raised by the admin

        // The clerk cannot see the admin's document, so cannot bill it either.
        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertNotFound();
        $this->actingAs($this->clerkUser)->getJson("/api/v1/crm/invoices/{$invoice}/payment-links")
            ->assertNotFound();
    }

    public function test_a_reminder_carries_the_link_when_one_is_open(): void
    {
        $this->configure();
        $this->fakeCashfree();
        $invoice = $this->document();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$invoice}/payment-links", [])
            ->assertCreated();

        $draft = $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$invoice}/reminders")
            ->assertOk()->json('draft');

        $this->assertStringContainsString('https://payments-test.cashfree.com/links/abcd1234', $draft['body']);
    }
}
