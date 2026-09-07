<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice Log and Proforma Log: the trail of every document, read from the
 * shared activity log. Entries are chosen by the SUBJECT's kind, so a
 * payment lands in the invoice log without the log knowing action names,
 * and the ledger window applies here exactly as it does to the lists.
 */
class CrmInvoiceLogTest extends TestCase
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

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');
        $this->bobUser = $this->makeUser('bob@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = [
            'clients' => ['view', 'create'],
            // The whole set the old wide 'invoices' right used to carry —
            // which is exactly what the split migration hands existing
            // members, so this fixture is a person who worked here before it.
            'proforma' => ['view', 'create', 'edit', 'delete'],
            'proforma_log' => ['view'],
            'invoices' => ['view', 'create', 'edit', 'delete'],
            'invoice_log' => ['view'],
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

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function raise(User $who, string $company, string $kind = 'invoice'): string
    {
        $clientUuid = $this->actingAs($who)->postJson('/api/v1/crm/clients', ['company_name' => $company])
            ->assertCreated()->json('data.uuid');

        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_the_log_records_what_happened_to_whom_and_for_how_much(): void
    {
        $uuid = $this->raise($this->aliceUser, 'Bhavya Steel');

        $this->actingAs($this->aliceUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 2000, 'received_at' => '2026-08-22',
        ])->assertCreated();

        $log = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoice-log')
            ->assertOk()
            ->assertJsonPath('kind', 'invoice')
            ->assertJsonPath('summary.total', 2)
            ->json();

        // Newest first: the payment, then the document being raised.
        $this->assertSame('payment.recorded', $log['data'][0]['action']);
        $this->assertEquals(2000, $log['data'][0]['amount']);
        $this->assertSame('invoice.created', $log['data'][1]['action']);
        $this->assertSame('Bhavya Steel', $log['data'][1]['client']);
        $this->assertEquals(5000, $log['data'][1]['total']);
        $this->assertSame($this->aliceUser->name, $log['data'][1]['by']);
        $this->assertSame($uuid, $log['data'][1]['document']['uuid']);
    }

    public function test_each_kind_keeps_its_own_log(): void
    {
        $this->raise($this->adminUser, 'Bhavya Steel', 'proforma');
        $this->raise($this->adminUser, 'Quiet Holdings', 'invoice');

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoice-log?kind=proforma')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'proforma.created');

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoice-log?kind=invoice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'invoice.created');
    }

    public function test_a_conversion_is_written_to_both_logs(): void
    {
        $proforma = $this->raise($this->adminUser, 'Bhavya Steel', 'proforma');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$proforma}/convert")->assertCreated();

        // The proforma's own trail says where it went…
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoice-log?kind=proforma')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'proforma.converted')
            ->assertJsonPath('data.0.invoice', 'INV-1');

        // …and the new invoice's trail says where it came from.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoice-log?kind=invoice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'invoice.created')
            ->assertJsonPath('data.0.from_proforma', 'PI-1');
    }

    public function test_the_log_shows_only_your_own_ledger(): void
    {
        $this->raise($this->aliceUser, 'Bhavya Steel');
        $this->raise($this->bobUser, 'Quiet Holdings');

        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/invoice-log')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client', 'Bhavya Steel');

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoice-log')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.total', 2);
    }

    public function test_the_log_can_be_narrowed(): void
    {
        $uuid = $this->raise($this->aliceUser, 'Bhavya Steel');
        $this->raise($this->bobUser, 'Quiet Holdings');
        $this->actingAs($this->aliceUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 1000, 'received_at' => '2026-08-22',
        ])->assertCreated();

        $admin = $this->actingAs($this->adminUser);

        $admin->getJson('/api/v1/crm/invoice-log?action=payment.recorded')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'payment.recorded');

        $admin->getJson('/api/v1/crm/invoice-log?member=' . $this->bob->uuid)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.client', 'Quiet Holdings');

        $admin->getJson('/api/v1/crm/invoice-log?search=Bhavya')
            ->assertOk()->assertJsonCount(2, 'data');

        $admin->getJson('/api/v1/crm/invoice-log?document=' . $uuid)
            ->assertOk()->assertJsonCount(2, 'data');

        // A day with nothing on it stays empty, chart included.
        $admin->getJson('/api/v1/crm/invoice-log?date_from=2030-01-01')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.total', 0);
    }

    public function test_the_trail_survives_the_document_it_describes(): void
    {
        $uuid = $this->raise($this->adminUser, 'Bhavya Steel');
        Invoice::where('uuid', $uuid)->delete();

        // The document is gone, so nothing to link to — but the log has to
        // keep saying what happened. (Deletion is not a CRM feature; this is
        // the safety net for a row removed some other way.)
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoice-log')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_proforma_list_says_what_became_an_invoice(): void
    {
        $proforma = $this->raise($this->adminUser, 'Bhavya Steel', 'proforma');
        $other = $this->raise($this->adminUser, 'Quiet Holdings', 'proforma');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$proforma}/convert")->assertCreated();

        $rows = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices?kind=proforma')
            ->assertOk()->json('data'))->keyBy('uuid');

        $this->assertSame('INV-1', $rows[$proforma]['converted_to_doc']['number']);
        $this->assertTrue($rows[$proforma]['converted']);
        $this->assertNull($rows[$other]['converted_to_doc']);
    }
}
