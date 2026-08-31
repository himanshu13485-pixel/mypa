<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lead Generation + Lead Log. What matters: numbering, the trail recording
 * every event, the employee's own-leads-only window, and a lead turning
 * into a client.
 */
class CrmLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $salesUser;
    protected Organization $org;
    protected Member $salesMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->salesUser = $this->makeUser('sales@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin']);
        $this->salesMember = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->salesUser->id,
            'crm_role' => 'employee',
            'is_salesperson' => true,
            'rights' => ['leads' => ['view', 'create', 'edit'], 'clients' => ['view', 'create']],
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    public function test_leads_are_numbered_and_logged_from_birth(): void
    {
        $first = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Spsys Group',
            'contact_person' => 'Ravi',
            'mobile' => '6370700180',
            'source' => 'WhatsApp',
            'assigned_member_uuid' => $this->salesMember->uuid,
        ])->assertCreated();

        $second = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Gaurav Chandel',
        ])->assertCreated();

        $this->assertSame(1, $first->json('data.lead_no'));
        $this->assertSame(2, $second->json('data.lead_no'));

        // The Lead Log already carries both birth entries.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/lead-log')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.action', 'lead.created');
    }

    public function test_follow_up_updates_status_and_writes_the_trail(): void
    {
        $uuid = $this->actingAs($this->salesUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Spsys Group', 'assigned_member_uuid' => $this->salesMember->uuid,
        ])->json('data.uuid');

        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/leads/{$uuid}/followup", [
            'note' => 'Call not picked, retry tomorrow',
            'lead_status' => 'follow_up',
            'follow_up_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()
            ->assertJsonPath('data.lead_status', 'follow_up');

        $show = $this->actingAs($this->salesUser)->getJson("/api/v1/crm/leads/{$uuid}")->assertOk();
        $logs = collect($show->json('data.logs'));
        $this->assertTrue($logs->contains(fn ($l) => $l['action'] === 'lead.followup' && $l['note'] === 'Call not picked, retry tomorrow'));
    }

    public function test_an_employee_sees_only_their_own_leads(): void
    {
        // One lead for the salesperson, one for nobody (admin's own).
        $mine = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Mine Ltd', 'assigned_member_uuid' => $this->salesMember->uuid,
        ])->json('data.uuid');
        $others = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Not Yours Ltd',
        ])->json('data.uuid');

        $list = $this->actingAs($this->salesUser)->getJson('/api/v1/crm/leads')->assertOk();
        $this->assertSame(1, $list->json('totals.count'));
        $this->assertSame('Mine Ltd', $list->json('data.0.company_name'));

        $this->actingAs($this->salesUser)->getJson("/api/v1/crm/leads/{$others}")->assertNotFound();
        $this->actingAs($this->salesUser)->getJson("/api/v1/crm/leads/{$mine}")->assertOk();

        // The admin sees the whole pipeline.
        $this->assertSame(2, $this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads')->json('totals.count'));
    }

    public function test_a_won_lead_converts_to_a_client_once(): void
    {
        $uuid = $this->actingAs($this->salesUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Spsys Group',
            'contact_person' => 'Ravi',
            'mobile' => '6370700180',
            'assigned_member_uuid' => $this->salesMember->uuid,
        ])->json('data.uuid');

        $converted = $this->actingAs($this->salesUser)
            ->postJson("/api/v1/crm/leads/{$uuid}/convert")
            ->assertCreated();

        // The client exists, carries the lead's details, and the lead closed.
        $clientUuid = $converted->json('data.client_uuid');
        $this->actingAs($this->adminUser)->getJson("/api/v1/crm/clients/{$clientUuid}")
            ->assertOk()
            ->assertJsonPath('data.company_name', 'Spsys Group')
            ->assertJsonPath('data.mobile', '6370700180');
        $this->actingAs($this->salesUser)->getJson("/api/v1/crm/leads/{$uuid}")
            ->assertJsonPath('data.lead_status', 'closed')
            ->assertJsonPath('data.client.uuid', $clientUuid);

        // Converting twice is refused.
        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/leads/{$uuid}/convert")->assertStatus(422);
    }

    public function test_lead_log_filters_by_lead_number(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', ['company_name' => 'Alpha']);
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', ['company_name' => 'Beta'])->json('data.uuid');
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/leads/{$uuid}", [
            'company_name' => 'Beta', 'lead_status' => 'not_interested',
        ]);

        $log = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/lead-log?lead_no=2')->assertOk();
        $this->assertSame(2, $log->json('total')); // birth + update, Alpha's entry excluded
        $this->assertTrue(collect($log->json('data'))->every(fn ($l) => $l['lead_no'] === 2));
    }
}
