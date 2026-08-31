<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentReminder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Money still owed, and the chasing of it. The outstanding list is worked out
 * from the receipts themselves, every chase is recorded with what was owed at
 * the time, and none of it escapes the ledger window.
 */
class CrmPaymentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $alice;
    protected Member $bob;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-08-27 10:00:00');

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');
        $this->bobUser = $this->makeUser('bob@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = [
            'clients' => ['view', 'create'],
            'invoices' => ['view', 'create'],
            'payments' => ['view', 'create'],
        ];
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        $this->bob = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bobUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');
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

    private function raise(User $who, array $client, string $dueDate, float $amount = 10000, string $raisedOn = '2026-06-01'): string
    {
        $clientUuid = $this->actingAs($who)->postJson('/api/v1/crm/clients', $client)
            ->assertCreated()->json('data.uuid');

        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => $raisedOn,
            'due_date' => $dueDate,
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => $amount]],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_the_outstanding_list_is_worked_out_from_the_receipts(): void
    {
        $uuid = $this->raise($this->adminUser, [
            'company_name' => 'Bhavya Steel', 'contact_person' => 'Jaimin', 'email' => 'pay@bhavya.test',
        ], '2026-07-28');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 4000, 'received_at' => '2026-08-01',
        ])->assertCreated();

        $row = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments/outstanding')
            ->assertOk()
            ->assertJsonPath('summary.count', 1)
            ->json('data.0');

        $this->assertEquals(10000, $row['total']);
        $this->assertEquals(4000, $row['received']);
        $this->assertEquals(6000, $row['balance']);
        $this->assertSame(30, $row['days_overdue']);
        $this->assertSame('1_30', $row['bucket']);
        $this->assertNull($row['last_reminder']);
    }

    public function test_a_settled_invoice_leaves_the_list(): void
    {
        $uuid = $this->raise($this->adminUser, ['company_name' => 'Bhavya Steel'], '2026-09-30');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 10000, 'received_at' => '2026-08-01',
        ])->assertCreated();

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments/outstanding')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.outstanding', 0);
    }

    public function test_the_ledger_is_read_in_age_buckets(): void
    {
        $this->raise($this->adminUser, ['company_name' => 'Not Due Yet'], '2026-09-30');
        $this->raise($this->adminUser, ['company_name' => 'Recent Debtor'], '2026-08-10');
        $this->raise($this->adminUser, ['company_name' => 'Old Debtor'], '2026-04-01', raisedOn: '2026-03-01');

        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments/outstanding')
            ->assertOk()->json('summary');

        $buckets = collect($summary['by_bucket'])->keyBy('key');
        $this->assertSame(1, $buckets['not_due']['count']);
        $this->assertSame(1, $buckets['1_30']['count']);
        $this->assertSame(1, $buckets['over_90']['count']);
        $this->assertEquals(30000, $summary['outstanding']);
        $this->assertEquals(20000, $summary['overdue']);
        $this->assertSame(3, $summary['never_chased']);

        // And one bucket can be read on its own.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments/outstanding?bucket=over_90')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.client.company_name', 'Old Debtor');
    }

    public function test_a_reminder_is_drafted_from_the_document(): void
    {
        $uuid = $this->raise($this->adminUser, [
            'company_name' => 'Bhavya Steel', 'contact_person' => 'Jaimin', 'email' => 'pay@bhavya.test',
        ], '2026-07-28');

        $draft = $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}/reminders")
            ->assertOk()->json('draft');

        $this->assertSame('pay@bhavya.test', $draft['to_email']);
        $this->assertStringContainsString('INV-1', $draft['subject']);
        $this->assertStringContainsString('overdue', $draft['subject']);
        $this->assertStringContainsString('Jaimin', $draft['body']);
        $this->assertStringContainsString('30 days overdue', $draft['body']);
        $this->assertEquals(10000, $draft['balance']);
    }

    public function test_sending_a_reminder_writes_to_the_client_and_the_record(): void
    {
        Mail::fake();

        $uuid = $this->raise($this->adminUser, [
            'company_name' => 'Bhavya Steel', 'email' => 'pay@bhavya.test',
        ], '2026-07-28');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/reminders", [
            'next_follow_up' => '2026-09-03',
        ])->assertCreated()->assertJsonPath('data.status', 'sent');

        $reminder = PaymentReminder::firstOrFail();
        $this->assertSame('pay@bhavya.test', $reminder->to_email);
        $this->assertNotNull($reminder->sent_at);
        $this->assertSame('email', $reminder->channel);
        $this->assertEquals(10000, $reminder->balance);
        $this->assertSame($this->admin->id, $reminder->member_id);

        // The list now says when it was chased, and when to look again.
        $row = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments/outstanding')
            ->assertOk()->json('data.0');
        $this->assertSame(1, $row['reminders']);
        $this->assertSame('2026-09-03', $row['next_follow_up']);
        $this->assertSame($this->adminUser->name, $row['last_reminder']['by']);
    }

    public function test_a_phone_call_can_be_noted_instead(): void
    {
        Mail::fake();

        $uuid = $this->raise($this->adminUser, ['company_name' => 'No Email Co'], '2026-07-28');

        // No address on file, so an e-mail is refused with the reason.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/reminders", [])
            ->assertStatus(422);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/reminders", [
            'channel' => 'note', 'body' => 'Rang Jaimin; cheque posted Friday.', 'next_follow_up' => '2026-09-01',
        ])->assertCreated()->assertJsonPath('data.status', 'logged');

        Mail::assertNothingSent();
        $this->assertSame('note', PaymentReminder::firstOrFail()->channel);
    }

    public function test_a_settled_invoice_cannot_be_chased(): void
    {
        $uuid = $this->raise($this->adminUser, [
            'company_name' => 'Bhavya Steel', 'email' => 'pay@bhavya.test',
        ], '2026-07-28');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 10000, 'received_at' => '2026-08-01',
        ])->assertCreated();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/reminders", [])
            ->assertStatus(422);
    }

    public function test_the_chase_history_is_kept(): void
    {
        Mail::fake();

        $uuid = $this->raise($this->adminUser, [
            'company_name' => 'Bhavya Steel', 'email' => 'pay@bhavya.test',
        ], '2026-07-28');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/reminders", [
            'subject' => 'First reminder', 'body' => 'Kindly settle INV-1.',
        ])->assertCreated();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/reminders", [
            'channel' => 'note', 'body' => 'Spoke to accounts.',
        ])->assertCreated();

        $history = $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}/reminders")
            ->assertOk()->json('data');

        $this->assertCount(2, $history);
        $this->assertSame('Spoke to accounts.', $history[0]['body']);
        $this->assertSame('First reminder', $history[1]['subject']);

        // What was owed at the time is kept, even after the money lands.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 10000, 'received_at' => '2026-08-26',
        ])->assertCreated();

        $this->assertEquals(10000, PaymentReminder::orderBy('id')->first()->balance);
    }

    public function test_chasing_stays_inside_the_ledger_window(): void
    {
        $mine = $this->raise($this->aliceUser, [
            'company_name' => 'Alice Client', 'email' => 'a@client.test',
        ], '2026-07-28');
        $theirs = $this->raise($this->bobUser, [
            'company_name' => 'Bob Client', 'email' => 'b@client.test',
        ], '2026-07-28');

        // Alice's list is Alice's ledger.
        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/payments/outstanding')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $mine);

        // And she cannot chase, or read the chasing of, what is not hers.
        $this->actingAs($this->aliceUser)->getJson("/api/v1/crm/invoices/{$theirs}/reminders")->assertNotFound();
        $this->actingAs($this->aliceUser)->postJson("/api/v1/crm/invoices/{$theirs}/reminders", [])->assertNotFound();

        // The admin sees the whole ledger.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments/outstanding')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_cancelled_document_is_not_owed(): void
    {
        $uuid = $this->raise($this->adminUser, ['company_name' => 'Bhavya Steel'], '2026-07-28');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/cancel")->assertOk();

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments/outstanding')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('cancelled', Invoice::firstOrFail()->status);
    }
}
