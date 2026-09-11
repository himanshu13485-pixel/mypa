<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentReminder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Chasing the TDS certificate: the same act as chasing a payment, pointed at
 * the other thing a client owes. One letter per client however many invoices
 * are ticked, one row of trail per invoice, and a way to say it arrived so
 * the list stops asking.
 */
class CrmTdsCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $salesUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $sales;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->salesUser = $this->makeUser('sales@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->sales = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->salesUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id,
            'rights' => ['clients' => ['view', 'create'], 'invoices' => ['view', 'create']],
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function client(User $who, string $name, ?string $email = 'accounts@bhavya.test'): string
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/clients', [
            'company_name' => $name,
            'contact_person' => 'Ravi',
            'email' => $email,
        ])->assertCreated()->json('data.uuid');
    }

    private function invoice(User $who, string $clientUuid, float $tdsRate = 2, string $date = '2026-08-20'): array
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => $date,
            'due_date' => '2026-12-31',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'tds_rate' => $tdsRate,
            'items' => [[
                'membership' => 'Standard', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31',
                'plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 100000,
            ]],
        ])->assertCreated()->json('data');
    }

    public function test_the_list_holds_the_invoices_with_tax_deducted_and_no_certificate(): void
    {
        $client = $this->client($this->salesUser, 'Bhavya Steel');
        $deducted = $this->invoice($this->salesUser, $client);
        // A bill nobody deducted from has nothing to chase.
        $clean = $this->invoice($this->salesUser, $this->client($this->salesUser, 'Clean Traders'), 0);

        $list = $this->actingAs($this->salesUser)->getJson('/api/v1/crm/tds-certificates')->assertOk();

        $numbers = collect($list->json('data'))->pluck('number');
        $this->assertTrue($numbers->contains($deducted['number']));
        $this->assertFalse($numbers->contains($clean['number']));
        $this->assertSame(1, $list->json('totals.pending'));
        $this->assertGreaterThan(0, (float) $list->json('totals.tds'));
    }

    public function test_one_letter_per_client_and_one_row_of_trail_per_invoice(): void
    {
        $client = $this->client($this->salesUser, 'Bhavya Steel');
        $first = $this->invoice($this->salesUser, $client, 2, '2026-05-10');
        $second = $this->invoice($this->salesUser, $client, 2, '2026-08-20');

        // What the letter would say, before anybody sends it.
        $draft = $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/draft', [
            'invoice_uuids' => [$first['uuid'], $second['uuid']],
        ])->assertOk();

        $this->assertCount(1, $draft->json('data'));
        $this->assertStringContainsString($first['number'], $draft->json('data.0.body'));
        $this->assertStringContainsString($second['number'], $draft->json('data.0.body'));
        // Two quarters of one financial year, named as a span.
        $this->assertStringContainsString('Q1 FY 2026-27 to Q2 FY 2026-27', $draft->json('data.0.body'));

        $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/remind', [
            'invoice_uuids' => [$first['uuid'], $second['uuid']],
        ])->assertCreated()->assertJsonPath('data.sent.0', 'accounts@bhavya.test');

        // One chase each, so each row can say when it was last asked about.
        $this->assertSame(2, PaymentReminder::where('kind', 'tds')->count());
        // And it did not land in the payment chase trail.
        $this->assertSame(0, PaymentReminder::where('kind', 'payment')->count());

        $this->assertSame(2, ActivityLog::where('action', 'tds.reminder')->count());

        $list = $this->actingAs($this->salesUser)->getJson('/api/v1/crm/tds-certificates')->assertOk();
        $this->assertSame(1, $list->json('data.0.chased'));
        $this->assertSame($this->salesUser->name, $list->json('data.0.last_chased_by'));
    }

    public function test_a_client_with_no_address_is_named_rather_than_silently_skipped(): void
    {
        $silent = $this->client($this->salesUser, 'No Mail Ltd', null);
        $invoice = $this->invoice($this->salesUser, $silent);

        $response = $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/remind', [
            'invoice_uuids' => [$invoice['uuid']],
        ])->assertCreated();

        $this->assertSame([], $response->json('data.sent'));
        $this->assertStringContainsString('No Mail Ltd', $response->json('data.refused.0'));
        // Recorded as failed rather than not recorded at all.
        $this->assertSame('failed', PaymentReminder::where('kind', 'tds')->first()->status);
    }

    public function test_an_invoice_with_no_tax_deducted_is_refused_by_name(): void
    {
        $clean = $this->invoice($this->salesUser, $this->client($this->salesUser, 'Clean Traders'), 0);

        $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/remind', [
            'invoice_uuids' => [$clean['uuid']],
        ])->assertStatus(422);

        $this->assertSame(0, PaymentReminder::where('kind', 'tds')->count());
    }

    public function test_the_certificate_arriving_takes_it_off_the_list(): void
    {
        $invoice = $this->invoice($this->salesUser, $this->client($this->salesUser, 'Bhavya Steel'));

        $this->actingAs($this->salesUser)
            ->postJson("/api/v1/crm/invoices/{$invoice['uuid']}/tds-certificate")
            ->assertOk();

        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/tds-certificates')
            ->assertOk()->assertJsonCount(0, 'data');

        $received = $this->actingAs($this->salesUser)->getJson('/api/v1/crm/tds-certificates?state=received')->assertOk();
        $this->assertSame($invoice['number'], $received->json('data.0.number'));
        $this->assertSame($this->salesUser->name, $received->json('data.0.certificate_by'));

        // And it can be put back if it was marked in error.
        $this->actingAs($this->salesUser)
            ->postJson("/api/v1/crm/invoices/{$invoice['uuid']}/tds-certificate")
            ->assertOk();
        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/tds-certificates')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(1, ActivityLog::where('action', 'tds.certificate_received')->count());
        $this->assertSame(1, ActivityLog::where('action', 'tds.certificate_cleared')->count());
    }

    public function test_the_letter_says_who_it_reaches_and_who_is_copied(): void
    {
        $client = $this->client($this->salesUser, 'Bhavya Steel');
        $invoice = $this->invoice($this->salesUser, $client);

        $draft = $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/draft', [
            'invoice_uuids' => [$invoice['uuid']],
        ])->assertOk();

        // The client's own address, the salesperson, and the accounts desk.
        $draft->assertJsonPath('data.0.to_email', 'accounts@bhavya.test');
        $draft->assertJsonPath('accounts_email', 'accounts@grapmail.com');
        $this->assertEqualsCanonicalizing(
            [$this->salesUser->email, 'accounts@grapmail.com'],
            $draft->json('data.0.cc'),
        );
        $this->assertSame('accounts@grapmail.com', $draft->json('data.0.reply_to'));
    }

    public function test_the_addresses_can_be_changed_before_it_goes(): void
    {
        $client = $this->client($this->salesUser, 'Bhavya Steel');
        $invoice = $this->invoice($this->salesUser, $client);

        Mail::assertNothingSent();

        $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/remind', [
            'invoice_uuids' => [$invoice['uuid']],
            // A second desk added, the salesperson taken off, and the answer
            // pointed somewhere other than the company default.
            'to' => ['accounts@bhavya.test', 'tax.desk@bhavya.test'],
            'cc' => ['accounts@grapmail.com'],
            'reply_to' => 'tds@grapout.test',
        ])->assertCreated();

        $reminder = \App\Models\Crm\PaymentReminder::where('kind', 'tds')->firstOrFail();
        $this->assertSame('accounts@bhavya.test', $reminder->to_email);

        $logged = \App\Models\Crm\ActivityLog::where('action', 'tds.reminder')->firstOrFail();
        $this->assertSame('accounts@grapmail.com', $logged->changes['cc']);
        $this->assertSame('tds@grapout.test', $logged->changes['reply_to']);
    }

    public function test_where_certificates_come_back_to_is_the_companys_to_set(): void
    {
        // An employee cannot move the company's accounts desk.
        $this->actingAs($this->salesUser)
            ->postJson('/api/v1/crm/tds-certificates/accounts-email', ['email' => 'me@example.test'])
            ->assertForbidden();

        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/tds-certificates/accounts-email', ['email' => 'Accounts@GrapOut.Test'])
            ->assertOk()
            ->assertJsonPath('data.accounts_email', 'accounts@grapout.test');

        // And every letter drafted after it says so.
        $invoice = $this->invoice($this->salesUser, $this->client($this->salesUser, 'Bhavya Steel'));
        $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/draft', [
            'invoice_uuids' => [$invoice['uuid']],
        ])->assertOk()->assertJsonPath('data.0.reply_to', 'accounts@grapout.test');
    }

    public function test_one_letter_cannot_be_addressed_to_several_clients_desks(): void
    {
        $first = $this->invoice($this->salesUser, $this->client($this->salesUser, 'Bhavya Steel'));
        $second = $this->invoice($this->salesUser, $this->client($this->salesUser, 'Other Traders'));

        $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/remind', [
            'invoice_uuids' => [$first['uuid'], $second['uuid']],
            'to' => ['somebody@one-of-them.test'],
        ])->assertStatus(422);
    }

    public function test_the_chase_stays_inside_the_ledger_window(): void
    {
        $mine = $this->invoice($this->adminUser, $this->client($this->adminUser, 'Admin Only Ltd'));

        // A salesperson cannot see the admin's own document, so cannot chase
        // it, mark it, or read its trail.
        $this->actingAs($this->salesUser)->postJson('/api/v1/crm/tds-certificates/remind', [
            'invoice_uuids' => [$mine['uuid']],
        ])->assertNotFound();

        $this->actingAs($this->salesUser)
            ->postJson("/api/v1/crm/invoices/{$mine['uuid']}/tds-certificate")
            ->assertNotFound();
    }
}
