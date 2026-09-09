<?php

namespace Tests\Feature;

use App\Models\Crm\CustomField;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a column sits on a form.
 *
 * Our columns came first and a company's own were appended after them, in
 * the order they were approved — so a Type column that belongs beside the
 * plan it describes sat out past the unit price, and nothing could move it.
 * The arrows in Workspace fields could only shuffle a company's own fields
 * among themselves, which is no help when the place it needs is between two
 * of ours.
 *
 * A form is arranged as one list now. Wording and what a column collects
 * still go to the Super Admin; where it sits does not — that is the
 * company's own business, and no document is changed by it.
 */
class CrmColumnArrangementTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $admin;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeUser('root@netvork.test');
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->admin = $this->makeUser('admin@acme.test');
        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->admin->id, 'crm_role' => 'admin',
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function as()
    {
        return $this->actingAs($this->admin)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** A column of this company's own, live on the Work Order. */
    private function ownColumn(string $label = 'Type'): string
    {
        $uuid = $this->as()->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order',
            'label' => $label,
            'type' => 'select',
            'options' => ['Renewal', 'Fresh'],
            'is_required' => true,
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk();

        return $uuid;
    }

    /** The Work Order's columns, in the order the form draws them. */
    private function order(string $entity = 'work_order'): array
    {
        return collect(CustomField::methodFor($this->org->id, $entity))->pluck('key')->all();
    }

    public function test_a_companys_own_column_starts_after_ours(): void
    {
        $this->ownColumn();

        $this->assertSame(
            ['membership', 'plan_name', 'description', 'validity', 'qty', 'unit_price', 'type'],
            $this->order(),
        );
    }

    public function test_it_can_be_moved_to_sit_beside_the_column_it_belongs_with(): void
    {
        $this->ownColumn();

        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'work_order',
            'keys' => ['membership', 'plan_name', 'type', 'description', 'validity', 'qty', 'unit_price'],
        ])->assertOk();

        $this->assertSame(
            ['membership', 'plan_name', 'type', 'description', 'validity', 'qty', 'unit_price'],
            $this->order(),
        );
    }

    public function test_the_form_is_served_in_that_order(): void
    {
        // The form, the workspace screen and the validator all read the one
        // method, so it is enough that the method is served arranged.
        $this->ownColumn();

        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'work_order',
            'keys' => ['membership', 'plan_name', 'type', 'description', 'validity', 'qty', 'unit_price'],
        ])->assertOk();

        $this->as()->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->assertJsonPath('data.work_order_method.2.key', 'type');

        $this->as()->getJson('/api/v1/crm/workspace-fields')
            ->assertOk()
            ->assertJsonPath('work_order_method.2.key', 'type');
    }

    public function test_the_document_fields_are_arranged_separately(): void
    {
        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'invoice',
            'keys' => ['invoice_date', 'due_date', 'notes', 'client_category', 'pricing_tier',
                'terms_of_payment', 'subscription_type', 'dispatch_status', 'fx'],
        ])->assertOk();

        $this->assertSame('notes', $this->order('invoice')[2]);
        // The Work Order is untouched by it.
        $this->assertSame('membership', $this->order()[0]);
    }

    public function test_a_column_approved_later_lands_at_the_end_rather_than_the_front(): void
    {
        $this->ownColumn();
        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'work_order',
            'keys' => ['membership', 'plan_name', 'type', 'description', 'validity', 'qty', 'unit_price'],
        ])->assertOk();

        $this->ownColumn('Channel');

        $order = $this->order();
        $this->assertSame('type', $order[2], 'the arrangement holds');
        $this->assertSame('channel', end($order), 'and the new column waits at the end');
    }

    public function test_a_list_that_is_not_this_forms_columns_is_refused(): void
    {
        $this->ownColumn();

        // Short: whatever it left out would silently fall to the end.
        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'work_order',
            'keys' => ['membership', 'plan_name'],
        ])->assertStatus(422);

        // And a key this form does not have.
        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'work_order',
            'keys' => ['membership', 'plan_name', 'description', 'validity', 'qty', 'unit_price', 'invented'],
        ])->assertStatus(422);

        $this->assertSame(
            ['membership', 'plan_name', 'description', 'validity', 'qty', 'unit_price', 'type'],
            $this->order(),
            'nothing was saved',
        );
    }

    public function test_arranging_is_the_companys_own_call(): void
    {
        // Unlike wording, which waits for the Super Admin: no request is
        // raised, nothing is left pending, and the order is live at once.
        $this->ownColumn();

        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'work_order',
            'keys' => ['type', 'membership', 'plan_name', 'description', 'validity', 'qty', 'unit_price'],
        ])->assertOk();

        $this->assertSame(0, CustomField::where('organization_id', $this->org->id)
            ->where('status', 'pending')->count());
        $this->assertSame('type', $this->order()[0]);
    }

    public function test_one_companys_arrangement_is_its_own(): void
    {
        $other = Organization::create(['name' => 'Globex Ltd', 'code' => 'GLOBEX']);

        $this->ownColumn();
        $this->as()->putJson('/api/v1/crm/workspace-fields/arrangement', [
            'entity' => 'work_order',
            'keys' => ['type', 'membership', 'plan_name', 'description', 'validity', 'qty', 'unit_price'],
        ])->assertOk();

        $this->assertSame(
            ['membership', 'plan_name', 'description', 'validity', 'qty', 'unit_price'],
            collect(CustomField::methodFor($other->id, 'work_order'))->pluck('key')->all(),
        );
    }

    public function test_an_employee_cannot_rearrange_the_companys_forms(): void
    {
        $employee = $this->makeUser('emp@acme.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $employee->id, 'crm_role' => 'employee',
        ]);

        $this->actingAs($employee)->withHeader('X-Crm-Org', $this->org->uuid)
            ->putJson('/api/v1/crm/workspace-fields/arrangement', [
                'entity' => 'work_order',
                'keys' => ['plan_name', 'membership', 'description', 'validity', 'qty', 'unit_price'],
            ])->assertForbidden();
    }
}
