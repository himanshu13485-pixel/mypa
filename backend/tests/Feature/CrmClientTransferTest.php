<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\ClientAccessRequest;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transferring a client from one employee to another.
 *
 * The split the business asked for: ownership moves, history stays. After
 * the handover the outgoing employee no longer has the client in their list,
 * yet the invoices they raised remain theirs — and those documents still
 * carry the client's details.
 */
class CrmClientTransferTest extends TestCase
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
            'clients' => ['view', 'create', 'edit'],
            'invoices' => ['view', 'create'],
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

    /** Alice adds a client and bills it — the state before every handover. */
    private function aliceWithAClient(): string
    {
        $uuid = $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel',
            'gst_no' => '24AAACS1234A1Z5',
            'city' => 'Surat',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $uuid,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 8000]],
        ])->assertCreated()->assertJsonPath('data.salesperson.name', $this->aliceUser->name);

        return $uuid;
    }

    public function test_the_invoice_raiser_is_recorded_without_being_asked(): void
    {
        $this->aliceWithAClient();

        $this->assertSame($this->alice->id, \App\Models\Crm\Invoice::firstOrFail()->member_id);
    }

    public function test_a_transfer_moves_the_client_and_leaves_the_invoices_behind(): void
    {
        $uuid = $this->aliceWithAClient();

        $response = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/clients/{$uuid}/transfer", [
            'to_member_uuid' => $this->bob->uuid,
            'note' => 'Alice is moving to the Pune desk.',
        ])->assertOk()->assertJsonPath('data.assigned_member.name', $this->bobUser->name);

        $this->assertStringContainsString('1 invoice stays with ' . $this->aliceUser->name, $response->json('message'));

        // The client has left Alice's portfolio and joined Bob's.
        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients')->assertJsonCount(0, 'data');
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/clients')->assertJsonCount(1, 'data');

        // She cannot open the client record any more…
        $this->actingAs($this->aliceUser)->getJson("/api/v1/crm/clients/{$uuid}")->assertNotFound();

        // …but the invoice is still hers, with the client's details on it.
        $invoiceUuid = \App\Models\Crm\Invoice::firstOrFail()->uuid;
        $this->actingAs($this->aliceUser)->getJson("/api/v1/crm/invoices/{$invoiceUuid}")
            ->assertOk()
            ->assertJsonPath('data.salesperson.name', $this->aliceUser->name)
            ->assertJsonPath('data.client.company_name', 'Bhavya Steel')
            ->assertJsonPath('data.client_full.city', 'Surat');
    }

    public function test_documents_raised_before_the_rule_are_stamped_on_the_way_out(): void
    {
        $uuid = $this->aliceWithAClient();

        // An older invoice that never named a salesperson: the transfer must
        // pin it to Alice rather than let it drift to the new owner.
        \App\Models\Crm\Invoice::query()->update(['member_id' => null]);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/clients/{$uuid}/transfer", [
            'to_member_uuid' => $this->bob->uuid,
        ])->assertOk();

        $this->assertSame($this->alice->id, \App\Models\Crm\Invoice::firstOrFail()->member_id);
    }

    public function test_a_client_kept_in_house_can_be_handed_to_an_employee(): void
    {
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Quiet Holdings',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/clients/{$uuid}/transfer", [
            'to_member_uuid' => $this->alice->uuid,
        ])->assertOk();

        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients')->assertJsonCount(1, 'data');

        // The trail names where it came from — a client kept in-house sits
        // with the admin who added it, so that is who it moved from.
        $this->actingAs($this->adminUser)->getJson("/api/v1/crm/clients/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.transfers.0.from', $this->adminUser->name)
            ->assertJsonPath('data.transfers.0.to', $this->aliceUser->name);
    }

    public function test_a_transfer_drops_the_old_share_and_answers_a_pending_request(): void
    {
        $uuid = $this->aliceWithAClient();

        // Bob tried to add the same client, so a request is waiting.
        $this->actingAs($this->bobUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])
            ->assertStatus(422);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/clients/{$uuid}/transfer", [
            'to_member_uuid' => $this->bob->uuid,
        ])->assertOk()->assertJsonCount(0, 'data.shared_with');

        $accessRequest = ClientAccessRequest::firstOrFail();
        $this->assertSame('approved', $accessRequest->status);
        $this->assertSame('Client transferred to them.', $accessRequest->decision_note);
    }

    public function test_a_transfer_takes_the_client_back_from_a_colleague_it_was_shared_with(): void
    {
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Shared Traders',
            'assigned_member_uuid' => $this->alice->uuid,
            'share_with' => [$this->bob->uuid],
        ])->assertCreated()->json('data.uuid');

        // Bob now owns it outright: no share row left over for either of them.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/clients/{$uuid}/transfer", [
            'to_member_uuid' => $this->bob->uuid,
        ])->assertOk()->assertJsonCount(0, 'data.shared_with');

        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients')->assertJsonCount(0, 'data');
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/clients')->assertJsonCount(1, 'data');
    }

    public function test_only_a_manager_can_transfer_a_client(): void
    {
        $uuid = $this->aliceWithAClient();

        // Alice holds clients.edit and still cannot give her client away.
        $this->actingAs($this->aliceUser)->postJson("/api/v1/crm/clients/{$uuid}/transfer", [
            'to_member_uuid' => $this->bob->uuid,
        ])->assertForbidden();

        $this->assertSame($this->alice->id, Client::firstOrFail()->assigned_member_id);
    }

    public function test_transferring_to_the_current_owner_is_refused(): void
    {
        $uuid = $this->aliceWithAClient();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/clients/{$uuid}/transfer", [
            'to_member_uuid' => $this->alice->uuid,
        ])->assertStatus(422);
    }
}
