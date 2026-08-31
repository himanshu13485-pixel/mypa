<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dedicated Company Workspace fields. What matters: a pending field changes
 * nothing, approval makes it live on that company's form only, values are
 * validated by type, and one company's field never reaches another.
 */
class CrmCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $adminA;
    protected User $adminB;
    protected User $employeeA;
    protected Organization $orgA;
    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeUser('root@netvork.test');
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->adminA = $this->makeUser('a@acme.test');
        $this->adminB = $this->makeUser('b@globex.test');
        $this->employeeA = $this->makeUser('emp@acme.test');

        $this->orgA = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->orgB = Organization::create(['name' => 'Globex Ltd', 'code' => 'GLOBEX']);
        Member::create(['organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id, 'crm_role' => 'admin']);
        Member::create(['organization_id' => $this->orgB->id, 'user_id' => $this->adminB->id, 'crm_role' => 'admin']);
        Member::create([
            'organization_id' => $this->orgA->id, 'user_id' => $this->employeeA->id, 'crm_role' => 'employee',
            'rights' => ['clients' => ['view', 'create']],
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function requestField(array $overrides = []): string
    {
        return $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', array_merge([
            'entity' => 'client',
            'label' => 'GST Registration Type',
            'type' => 'select',
            'options' => ['Regular', 'Composition'],
            'reason' => 'We file returns by registration type.',
        ], $overrides))->assertCreated()->json('data.uuid');
    }

    public function test_a_pending_field_changes_nothing_until_approved(): void
    {
        $uuid = $this->requestField();

        // Not on the form yet.
        $this->assertCount(0, $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')->json('data.client_custom_fields'));

        // The Super Admin sees it waiting; the other company does not.
        $pending = $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/crm/field-requests')->assertOk();
        $this->assertSame(1, $pending->json('pending_count'));
        $this->assertSame('Acme Pvt Ltd', $pending->json('data.0.organization.name'));
        $this->assertCount(0, $this->actingAs($this->adminB)->getJson('/api/v1/crm/masters')->json('data.client_custom_fields'));

        // Approve → live on Acme's client form, still absent from Globex.
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk();

        $fields = $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')->json('data.client_custom_fields');
        $this->assertCount(1, $fields);
        $this->assertSame('gst_registration_type', $fields[0]['key']);
        $this->assertCount(0, $this->actingAs($this->adminB)->getJson('/api/v1/crm/masters')->json('data.client_custom_fields'));

        // Deciding twice is refused.
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", ['status' => 'rejected'])
            ->assertStatus(422);
    }

    public function test_approved_fields_are_saved_and_validated_by_type(): void
    {
        $select = $this->requestField();
        $credit = $this->requestField(['label' => 'Credit Days', 'type' => 'number', 'options' => null]);
        foreach ([$select, $credit] as $uuid) {
            $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", ['status' => 'approved']);
        }

        // A good payload round-trips…
        $clientUuid = $this->actingAs($this->employeeA)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel',
            'custom_fields' => ['gst_registration_type' => 'Regular', 'credit_days' => 30],
        ])->assertCreated()->json('data.uuid');

        $shown = $this->actingAs($this->adminA)->getJson("/api/v1/crm/clients/{$clientUuid}")->json('data.custom_fields');
        $this->assertSame('Regular', $shown['gst_registration_type']);
        $this->assertEquals(30, $shown['credit_days']);

        // …a value outside the dropdown, or a non-number, is refused.
        $this->actingAs($this->employeeA)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bad Co', 'custom_fields' => ['gst_registration_type' => 'Nonsense'],
        ])->assertStatus(422);
        $this->actingAs($this->employeeA)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bad Co', 'custom_fields' => ['credit_days' => 'many'],
        ])->assertStatus(422);

        // Unknown keys are dropped, never stored.
        $sneaky = $this->actingAs($this->employeeA)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Sneaky Co', 'custom_fields' => ['not_a_field' => 'x'],
        ])->assertCreated()->json('data.uuid');
        $this->assertSame([], (array) $this->actingAs($this->adminA)
            ->getJson("/api/v1/crm/clients/{$sneaky}")->json('data.custom_fields'));
    }

    public function test_the_trail_records_both_sides_and_the_queue_filters_by_company(): void
    {
        $acme = $this->requestField(['label' => 'Port of Loading', 'type' => 'text', 'options' => null]);
        $this->actingAs($this->adminB)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'client', 'label' => 'Globex Ref', 'type' => 'text',
        ])->assertCreated();

        // The queue filters company-wise and offers the companies as options.
        $all = $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/crm/field-requests')->assertOk();
        $this->assertSame(2, $all->json('total'));
        $this->assertCount(2, $all->json('organizations'));

        $onlyAcme = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/crm/field-requests?organization=' . $this->orgA->uuid)->assertOk();
        $this->assertSame(1, $onlyAcme->json('total'));
        $this->assertSame('Acme Pvt Ltd', $onlyAcme->json('data.0.organization.name'));

        // Both sides carry timestamps: requested now, decided on approval.
        $this->assertNotNull($onlyAcme->json('data.0.created_at'));
        $this->assertNull($onlyAcme->json('data.0.decided_at'));

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/crm/field-requests/{$acme}/decide", ['status' => 'approved', 'note' => 'Fine'])
            ->assertOk();

        $mine = collect($this->actingAs($this->adminA)->getJson('/api/v1/crm/workspace-fields')->json('data'))
            ->firstWhere('uuid', $acme);
        $this->assertNotNull($mine['created_at']);
        $this->assertNotNull($mine['decided_at']);
        $this->assertSame('Fine', $mine['decision_note']);

        // The company's User log tells the whole story, request then decision.
        $log = collect($this->actingAs($this->adminA)->getJson('/api/v1/crm/user-log')->json('data'));
        $requested = $log->firstWhere('action', 'dcw.requested');
        $approved = $log->firstWhere('action', 'dcw.approved');
        $this->assertSame('Port of Loading', $requested['changes']['label']);
        $this->assertSame($this->adminA->name, $requested['changes']['by']);
        $this->assertSame($this->superAdmin->name, $approved['changes']['by']);
        $this->assertSame($this->adminA->name, $approved['changes']['requested_by']);
    }

    public function test_only_managers_request_fields_and_only_super_admin_decides(): void
    {
        // An employee with client rights still cannot shape the workspace.
        $this->actingAs($this->employeeA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'client', 'label' => 'Sneaky', 'type' => 'text',
        ])->assertForbidden();
        $this->actingAs($this->employeeA)->getJson('/api/v1/crm/workspace-fields')->assertForbidden();

        $uuid = $this->requestField(['label' => 'Port of Loading', 'type' => 'text', 'options' => null]);

        // A company admin cannot approve their own request.
        $this->actingAs($this->adminA)
            ->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", ['status' => 'approved'])
            ->assertForbidden();
        $this->actingAs($this->adminA)->getJson('/api/v1/admin/crm/field-requests')->assertForbidden();

        // A dropdown with one option is refused at request time.
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'client', 'label' => 'Half Baked', 'type' => 'select', 'options' => ['only'],
        ])->assertStatus(422);
    }
}
