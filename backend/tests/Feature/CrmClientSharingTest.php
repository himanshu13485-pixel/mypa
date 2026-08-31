<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\ClientAccessRequest;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Client portfolios: who owns a client, who was let in on it, and what
 * happens when a second employee types a company the firm already has.
 *
 * The rule the business asked for: one record per client. A duplicate is
 * never a second row — it is a request for access that an admin decides.
 */
class CrmClientSharingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $alice;
    protected Member $bob;

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
        $rights = ['clients' => ['view', 'create', 'edit']];
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        $this->bob = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bobUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    public function test_an_employees_new_client_is_always_their_own(): void
    {
        // Alice even names Bob as the owner — the server ignores it.
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel',
            'assigned_member_uuid' => $this->bob->uuid,
        ])->assertCreated()->assertJsonPath('data.assigned_member.name', $this->aliceUser->name);

        $this->assertSame($this->alice->id, Client::first()->assigned_member_id);
    }

    public function test_a_client_the_admin_keeps_is_invisible_to_employees(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Quiet Holdings',
        ])->assertCreated();

        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_admin_can_hand_a_client_to_several_employees(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Shared Traders',
            'assigned_member_uuid' => $this->alice->uuid,
            'share_with' => [$this->bob->uuid],
        ])->assertCreated()->assertJsonPath('data.shared_with.0.name', $this->bobUser->name);

        // Both see it: the owner by ownership, Bob by the share.
        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients')->assertJsonCount(1, 'data');
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/clients')->assertJsonCount(1, 'data');
    }

    public function test_a_second_employee_gets_a_request_instead_of_a_duplicate(): void
    {
        Notification::fake();

        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel Pvt Ltd',
        ])->assertCreated();

        // Same company, sloppier typing — still the same company.
        $response = $this->actingAs($this->bobUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'BHAVYA  STEEL PVT LTD.',
        ])->assertStatus(422)->assertJsonPath('request_pending', true);

        $this->assertStringContainsString('already exists', $response->json('message'));
        $this->assertSame(1, Client::count());

        $accessRequest = ClientAccessRequest::firstOrFail();
        $this->assertSame($this->bob->id, $accessRequest->member_id);
        $this->assertSame('pending', $accessRequest->status);

        // The admin is told; Bob is not notified about his own request.
        Notification::assertSentTo($this->adminUser, CrmNotification::class);
        Notification::assertNotSentTo($this->bobUser, CrmNotification::class);

        // Asking twice does not stack up requests.
        $this->actingAs($this->bobUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel Pvt Ltd',
        ])->assertStatus(422);
        $this->assertSame(1, ClientAccessRequest::count());
    }

    public function test_approving_a_request_puts_the_client_in_both_portfolios(): void
    {
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])->assertCreated();
        $this->actingAs($this->bobUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])->assertStatus(422);

        // Bob cannot wave his own request through.
        $uuid = ClientAccessRequest::firstOrFail()->uuid;
        $this->actingAs($this->bobUser)->postJson("/api/v1/crm/client-requests/{$uuid}/decide", ['status' => 'approved'])
            ->assertForbidden();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/client-requests/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk();

        // One record, now in Bob's list too, marked as shared.
        $this->assertSame(1, Client::count());
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/clients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shared_with.0.name', $this->bobUser->name);

        // Alice still sees it, with the share shown on her side as well.
        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients')
            ->assertJsonPath('data.0.shared_with.0.name', $this->bobUser->name);
    }

    public function test_a_rejected_request_leaves_the_client_where_it_was(): void
    {
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])->assertCreated();
        $this->actingAs($this->bobUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])->assertStatus(422);

        $uuid = ClientAccessRequest::firstOrFail()->uuid;
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/client-requests/{$uuid}/decide", [
            'status' => 'rejected', 'note' => 'Alice is already talking to them.',
        ])->assertOk();

        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/clients')->assertJsonCount(0, 'data');

        // Deciding twice is refused.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/client-requests/{$uuid}/decide", ['status' => 'approved'])
            ->assertStatus(422);
    }

    public function test_an_employee_only_sees_their_own_access_requests(): void
    {
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])->assertCreated();
        $this->actingAs($this->bobUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])->assertStatus(422);

        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/client-requests')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requested_by', $this->bobUser->name);

        // Alice asked for nothing, so her inbox is empty even though the
        // request concerns her client.
        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/client-requests')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/client-requests')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_client_cannot_be_renamed_onto_another_one(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Alpha Metals'])->assertCreated();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Beta Metals'])->assertCreated();

        $beta = Client::where('company_name', 'like', 'Beta%')->firstOrFail();
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/clients/{$beta->uuid}", [
            'company_name' => 'Alpha Metals',
        ])->assertStatus(422);

        // Saving it under its own name still works.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/clients/{$beta->uuid}", [
            'company_name' => 'Beta Metals', 'city' => 'pune',
        ])->assertOk()->assertJsonPath('data.city', 'Pune');
    }

    public function test_the_admin_is_told_the_client_exists_without_a_request(): void
    {
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])->assertCreated();

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])
            ->assertStatus(422)
            ->assertJsonPath('duplicate.owner', $this->aliceUser->name);

        $this->assertSame(0, ClientAccessRequest::count());
    }
}
