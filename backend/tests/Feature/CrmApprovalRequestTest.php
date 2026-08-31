<?php

namespace Tests\Feature;

use App\Models\Crm\Approval;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Approvals: an employee asking the Admin for something.
 *
 * Most requests are about a document — a price below the card rate, details
 * to resend — and must name the invoice or at least the client, chosen from
 * the asker's own sales. The rest are the office's own money, and name
 * nothing. The reasons themselves are the company's list to keep.
 */
class CrmApprovalRequestTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $alice;
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
            'invoices' => ['view', 'create'],
            'approvals' => ['view', 'create'],
        ];
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        Member::create([
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

    /** @return array{0: string, 1: string} [invoiceUuid, clientUuid] */
    private function sale(User $who, string $company): array
    {
        $clientUuid = $this->actingAs($who)->postJson('/api/v1/crm/clients', ['company_name' => $company])
            ->assertCreated()->json('data.uuid');

        $invoiceUuid = $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-30',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        return [$invoiceUuid, $clientUuid];
    }

    // ---- Asking --------------------------------------------------------------

    public function test_an_employee_asks_the_admin_directly(): void
    {
        Notification::fake();

        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Office Recharge',
            'approval_date' => '2026-08-30',
            'amount' => 499,
            'details' => 'Recharged the office mobile from my own pocket.',
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'general')
            ->assertJsonPath('data.invoice', null)
            ->assertJsonPath('data.client', null);

        Notification::assertSentTo($this->adminUser, CrmNotification::class);
        $this->assertSame($this->alice->id, Approval::firstOrFail()->requested_by);
    }

    public function test_a_document_request_names_the_invoice_and_its_client(): void
    {
        [$invoice] = $this->sale($this->aliceUser, 'Bhavya Steel');

        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Discount',
            'scope' => 'invoice',
            'approval_date' => '2026-08-30',
            'invoice_uuid' => $invoice,
            'amount' => 1500,
            'details' => 'Client will not move past 8,500.',
        ])->assertCreated()
            ->assertJsonPath('data.scope', 'invoice')
            ->assertJsonPath('data.invoice.number', 'INV-1')
            // The client comes along, so the decider reads a name not a number.
            ->assertJsonPath('data.client.company_name', 'Bhavya Steel');
    }

    public function test_a_document_request_may_name_the_client_alone(): void
    {
        [, $client] = $this->sale($this->aliceUser, 'Bhavya Steel');

        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Resend Details',
            'scope' => 'invoice',
            'approval_date' => '2026-08-30',
            'client_uuid' => $client,
        ])->assertCreated()
            ->assertJsonPath('data.client.company_name', 'Bhavya Steel')
            ->assertJsonPath('data.invoice', null);
    }

    public function test_a_document_request_that_names_nothing_is_refused(): void
    {
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Discount',
            'scope' => 'invoice',
            'approval_date' => '2026-08-30',
            'details' => 'Something about a price.',
        ])->assertStatus(422);

        $this->assertSame(0, Approval::count());
    }

    public function test_nobody_points_a_request_at_a_stranger_sale(): void
    {
        [$bobInvoice, $bobClient] = $this->sale($this->bobUser, 'Bob Client');

        // Alice cannot see Bob's sale, so she cannot cite it either.
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Discount', 'scope' => 'invoice',
            'approval_date' => '2026-08-30', 'invoice_uuid' => $bobInvoice,
        ])->assertNotFound();

        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Discount', 'scope' => 'invoice',
            'approval_date' => '2026-08-30', 'client_uuid' => $bobClient,
        ])->assertNotFound();
    }

    // ---- What the form offers ------------------------------------------------

    public function test_the_form_offers_only_the_askers_own_work(): void
    {
        $this->sale($this->aliceUser, 'Alice Client');
        $this->sale($this->bobUser, 'Bob Client');

        $options = $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/approvals/options')
            ->assertOk()->json('data');

        $this->assertCount(1, $options['invoices']);
        $this->assertSame('Alice Client', $options['invoices'][0]['client']);
        $this->assertSame(['Alice Client'], collect($options['clients'])->pluck('company_name')->all());
        $this->assertContains('Discount', $options['types']);

        // The admin sees the whole floor.
        $adminOptions = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/approvals/options')
            ->assertOk()->json('data');
        $this->assertCount(2, $adminOptions['invoices']);

        // And it can be searched, so a long ledger stays usable.
        $found = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/approvals/options?search=Bob')
            ->assertOk()->json('data.invoices');
        $this->assertCount(1, $found);
    }

    // ---- The company's own list ---------------------------------------------

    public function test_the_admin_keeps_the_list_of_reasons(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/approval-types', [
            'approval_types' => ['Discount', 'Office Recharge', 'Travel Claim'],
        ])->assertOk();

        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/approvals/options')
            ->assertOk()->assertJsonPath('data.types', ['Discount', 'Office Recharge', 'Travel Claim']);

        // Every other dropdown that reads the list follows.
        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/masters')
            ->assertOk()->assertJsonPath('data.approval_types.2', 'Travel Claim');

        // A request already filed keeps the words it was filed under.
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Travel Claim', 'approval_date' => '2026-08-30', 'amount' => 250,
        ])->assertCreated();
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/approval-types', [
            'approval_types' => ['Discount'],
        ])->assertOk();
        $this->assertSame('Travel Claim', Approval::firstOrFail()->type);

        // An employee cannot rewrite the company's list.
        $this->actingAs($this->aliceUser)->putJson('/api/v1/crm/masters/approval-types', [
            'approval_types' => ['Whatever'],
        ])->assertForbidden();
    }
}
