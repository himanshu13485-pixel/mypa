<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\Crm\PaymentReminder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Settling money that has landed.
 *
 * The rules the business asked for: a company decides whether a matched
 * payment settles on the spot or waits for an Admin to check it; money
 * usually arrives against a proforma, so settling converts that proforma to
 * a tax invoice and pays it; and a payment put on the wrong document can be
 * moved without the books being briefly wrong.
 */
class CrmPaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $clerkUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $clerk;
    protected int $issuingCompanyId;
    protected string $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-08-28 10:00:00');

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->clerkUser = $this->makeUser('clerk@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->clerk = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->clerkUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id,
            'rights' => [
                'invoices' => ['view', 'create'],
                'clients' => ['view', 'create'],
                'payments' => ['view', 'create', 'edit'],
            ],
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel', 'email' => 'pay@bhavya.test',
        ])->assertCreated()->json('data.uuid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function document(string $kind = 'invoice', float $amount = 10000): string
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-01',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => $amount]],
        ])->assertCreated()->json('data.uuid');
    }

    private function logged(User $who, float $amount = 10000): string
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/payments', [
            'received_on' => '2026-08-27', 'amount' => $amount, 'payment_mode' => 'NEFT',
            'details' => 'NEFT Cr from BHAVYA STEEL',
        ])->assertCreated()->json('data.uuid');
    }

    // ---- The two ways to settle --------------------------------------------

    public function test_by_default_a_matched_payment_waits_for_an_admin(): void
    {
        $invoice = $this->document();
        $entry = $this->logged($this->clerkUser);

        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $invoice,
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        // Nothing has been received yet: it is a proposal, not a receipt.
        $this->assertSame('due', Invoice::firstOrFail()->payment_status);
        $this->assertSame(0, Invoice::firstOrFail()->payments()->count());

        // The Admin checks it, and that is the moment the money exists.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/settle")
            ->assertOk()->assertJsonPath('data.status', 'claimed');

        $this->assertSame('paid', Invoice::firstOrFail()->payment_status);
        $this->assertSame(1, Invoice::firstOrFail()->payments()->count());
        $this->assertSame($this->adminUser->id, PaymentInboxEntry::firstOrFail()->settled_by);
    }

    public function test_a_company_can_choose_to_settle_on_the_spot(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-settings', [
            'settlement_mode' => 'auto',
            'reminders' => ['enabled' => false],
        ])->assertOk();

        $invoice = $this->document();
        $entry = $this->logged($this->adminUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $invoice,
        ])->assertOk()->assertJsonPath('data.status', 'claimed');

        $this->assertSame('paid', Invoice::firstOrFail()->payment_status);
    }

    public function test_only_an_admin_settles_however_it_was_matched(): void
    {
        // The company settles on the spot…
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-settings', [
            'settlement_mode' => 'auto', 'reminders' => ['enabled' => false],
        ])->assertOk();

        $invoice = $this->document();
        $entry = $this->logged($this->clerkUser);

        // …but a clerk still only proposes. That is the point of the check.
        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $invoice, 'mode' => 'auto',
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/settle")->assertForbidden();
        $this->assertSame('due', Invoice::firstOrFail()->payment_status);
    }

    // ---- Money against a proforma ------------------------------------------

    public function test_settling_a_proforma_turns_it_into_an_invoice_and_pays_it(): void
    {
        $proforma = $this->document('proforma');
        $entry = $this->logged($this->adminUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $proforma, 'mode' => 'auto',
        ])->assertOk()->assertJsonPath('data.claimed_invoice.kind', 'invoice');

        $invoice = Invoice::where('kind', 'invoice')->firstOrFail();
        $this->assertSame('INV-1', $invoice->number);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame(1, $invoice->payments()->count());

        // The trail says where the money came in.
        $this->assertSame('PI-1', PaymentInboxEntry::firstOrFail()->sourceProforma->number);
        $this->assertTrue(ActivityLog::where('action', 'payment.settled')
            ->get()->contains(fn ($log) => data_get($log->changes, 'from_proforma') === 'PI-1'));
    }

    public function test_a_proforma_waiting_on_an_admin_is_converted_only_when_settled(): void
    {
        $proforma = $this->document('proforma');
        $entry = $this->logged($this->clerkUser);

        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $proforma,
        ])->assertOk()->assertJsonPath('data.status', 'pending');

        // Still one document: nothing was converted on a proposal.
        $this->assertSame(1, Invoice::count());

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/settle")->assertOk();

        $this->assertSame(2, Invoice::count());
        $this->assertSame('paid', Invoice::where('kind', 'invoice')->firstOrFail()->payment_status);
    }

    // ---- Putting a mistake right -------------------------------------------

    public function test_a_payment_on_the_wrong_document_can_be_moved(): void
    {
        $wrong = $this->document();
        $right = $this->document();
        $entry = $this->logged($this->adminUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $wrong, 'mode' => 'auto',
        ])->assertOk();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/reclaim", [
            'invoice_uuid' => $right, 'reason' => 'Bank narration named the wrong order.',
        ])->assertOk();

        $wrongInvoice = Invoice::where('uuid', $wrong)->firstOrFail();
        $rightInvoice = Invoice::where('uuid', $right)->firstOrFail();

        // One receipt, one payment — never two, never none.
        $this->assertSame(0, $wrongInvoice->payments()->count());
        $this->assertSame('due', $wrongInvoice->fresh()->payment_status);
        $this->assertSame(1, $rightInvoice->payments()->count());
        $this->assertSame('paid', $rightInvoice->fresh()->payment_status);

        $this->assertTrue(ActivityLog::where('action', 'payment.reclaimed')->exists());
    }

    public function test_moving_a_payment_is_the_admins(): void
    {
        $wrong = $this->document();
        $right = $this->document();
        $entry = $this->logged($this->adminUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $wrong, 'mode' => 'auto',
        ])->assertOk();

        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/reclaim", [
            'invoice_uuid' => $right,
        ])->assertForbidden();

        // And a settled payment is not a clerk's to undo either.
        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/unclaim")->assertForbidden();
    }

    public function test_a_proposal_can_be_withdrawn_by_whoever_made_it(): void
    {
        $invoice = $this->document();
        $entry = $this->logged($this->clerkUser);

        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $invoice,
        ])->assertOk();

        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/unclaim")->assertOk();

        $this->assertSame('unclaimed', PaymentInboxEntry::firstOrFail()->status);
        $this->assertTrue(ActivityLog::where('action', 'payment.claim_withdrawn')->exists());
    }

    public function test_every_step_is_written_to_the_trail(): void
    {
        $invoice = $this->document();
        $entry = $this->logged($this->clerkUser);

        $this->actingAs($this->clerkUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $invoice,
        ])->assertOk();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/settle")->assertOk();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/unclaim")->assertOk();

        $actions = ActivityLog::pluck('action');
        foreach (['payment.claim_proposed', 'payment.settled', 'payment.unsettled'] as $action) {
            $this->assertTrue($actions->contains($action), $action . ' should be in the trail');
        }
    }

    // ---- The automatic chase ------------------------------------------------

    public function test_the_schedule_chases_on_the_days_the_company_chose(): void
    {
        Mail::fake();

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-settings', [
            'settlement_mode' => 'manual',
            'reminders' => ['enabled' => true, 'offsets' => [0, 7, 30], 'stop_after' => 3],
        ])->assertOk();

        // Due exactly seven days ago: today is a chasing day.
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-21',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        $this->artisan('crm:chase-payments')->assertSuccessful();

        $reminder = PaymentReminder::firstOrFail();
        $this->assertTrue((bool) $reminder->is_auto);
        $this->assertNull($reminder->member_id);
        $this->assertSame('pay@bhavya.test', $reminder->to_email);
        $this->assertEquals(10000, $reminder->balance);

        // Twice on the same day is nagging, not chasing.
        $this->artisan('crm:chase-payments')->assertSuccessful();
        $this->assertSame(1, PaymentReminder::count());

        // And a paid invoice is left alone.
        $entry = $this->logged($this->adminUser);
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $uuid, 'mode' => 'auto',
        ])->assertOk();

        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->artisan('crm:chase-payments')->assertSuccessful();
        $this->assertSame(1, PaymentReminder::count());
    }

    public function test_a_company_with_the_schedule_off_is_left_alone(): void
    {
        Mail::fake();

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-21',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated();

        $this->artisan('crm:chase-payments')->assertSuccessful();

        $this->assertSame(0, PaymentReminder::count());
    }

    public function test_the_schedule_gives_up_after_the_agreed_number(): void
    {
        Mail::fake();

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-settings', [
            'settlement_mode' => 'manual',
            'reminders' => ['enabled' => true, 'offsets' => [7, 14, 21], 'stop_after' => 2],
        ])->assertOk();

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-01',
            'due_date' => '2026-08-21',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated();

        foreach (['2026-08-28 09:00:00', '2026-09-04 09:00:00', '2026-09-11 09:00:00'] as $day) {
            Carbon::setTestNow($day);
            $this->artisan('crm:chase-payments')->assertSuccessful();
        }

        $this->assertSame(2, PaymentReminder::count());
    }
}
