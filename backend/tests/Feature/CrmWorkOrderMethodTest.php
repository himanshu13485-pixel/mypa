<?php

namespace Tests\Feature;

use App\Models\Crm\InvoiceItem;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A company's own Work Order method: our line columns are only a starting
 * point. Through the same DCW approval flow a company renames them, drops
 * the ones it does not use, or turns one into a dropdown of its own products
 * — and the invoice engine then holds every line to that wording.
 */
class CrmWorkOrderMethodTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $adminA;
    protected User $adminB;
    protected Organization $orgA;
    protected Organization $orgB;
    protected int $companyA;
    protected string $clientA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeUser('root@netvork.test');
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->adminA = $this->makeUser('a@acme.test');
        $this->adminB = $this->makeUser('b@globex.test');

        $this->orgA = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->orgB = Organization::create(['name' => 'Globex Ltd', 'code' => 'GLOBEX']);
        Member::create(['organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id, 'crm_role' => 'admin']);
        Member::create(['organization_id' => $this->orgB->id, 'user_id' => $this->adminB->id, 'crm_role' => 'admin']);

        $this->companyA = $this->actingAs($this->adminA)->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $this->clientA = $this->actingAs($this->adminA)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel',
        ])->assertCreated()->json('data.uuid');
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    /** Ask to customise one of our columns, and have it approved. */
    private function customise(User $admin, array $payload, bool $approve = true): string
    {
        $uuid = $this->actingAs($admin)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order',
        ] + $payload)->assertCreated()->json('data.uuid');

        if ($approve) {
            $this->actingAs($this->superAdmin)
                ->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", ['status' => 'approved'])
                ->assertOk();
        }

        return $uuid;
    }

    private function column(User $admin, string $key): array
    {
        $method = $this->actingAs($admin)->getJson('/api/v1/crm/masters')->assertOk()->json('data.work_order_method');

        return collect($method)->firstWhere('key', $key);
    }

    private function raise(array $items, string $kind = 'invoice')
    {
        return $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->companyA,
            'client_uuid' => $this->clientA,
            'invoice_date' => '2026-08-20',
            'items' => $items,
        ]);
    }

    public function test_a_company_starts_with_our_columns(): void
    {
        $method = $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')
            ->assertOk()->json('data.work_order_method');

        $this->assertSame(
            ['membership', 'plan_name', 'description', 'validity', 'qty', 'unit_price'],
            collect($method)->pluck('key')->all(),
        );
        $this->assertSame('Plan name', collect($method)->firstWhere('key', 'plan_name')['label']);
        $this->assertFalse(collect($method)->firstWhere('key', 'plan_name')['customised']);
    }

    public function test_a_column_keeps_its_old_wording_until_the_request_is_approved(): void
    {
        $this->customise($this->adminA, ['builtin_key' => 'plan_name', 'label' => 'Service', 'type' => 'text'], approve: false);

        $this->assertSame('Plan name', $this->column($this->adminA, 'plan_name')['label']);
    }

    public function test_an_approved_column_takes_the_companys_own_wording(): void
    {
        $this->customise($this->adminA, ['builtin_key' => 'membership', 'label' => 'Service Package', 'type' => 'text']);

        $column = $this->column($this->adminA, 'membership');
        $this->assertSame('Service Package', $column['label']);
        $this->assertTrue($column['customised']);

        // Globex is untouched — this is one company's method, not ours.
        $this->assertSame('Membership', $this->column($this->adminB, 'membership')['label']);
    }

    public function test_a_column_can_become_a_dropdown_of_the_companys_products(): void
    {
        $this->customise($this->adminA, [
            'builtin_key' => 'plan_name',
            'label' => 'Service',
            'type' => 'select',
            'options' => ['Annual Listing', 'Banner Ad', 'Site Visit'],
            'is_required' => true,
        ]);

        // Their own products go through…
        $this->raise([['plan_name' => 'Banner Ad', 'qty' => 1, 'unit_price' => 1000]])->assertCreated();

        // …anything else does not.
        $this->raise([['plan_name' => 'Something Else', 'qty' => 1, 'unit_price' => 1000]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.plan_name');

        // And it is required, so an empty line is refused by name.
        $this->raise([['qty' => 1, 'unit_price' => 1000]])
            ->assertStatus(422)->assertJsonValidationErrors('items.0.plan_name');
    }

    public function test_a_column_a_company_does_not_use_is_dropped_from_its_lines(): void
    {
        $this->customise($this->adminA, [
            'builtin_key' => 'membership', 'label' => 'Membership', 'type' => 'text', 'is_hidden' => true,
        ]);

        $this->assertTrue($this->column($this->adminA, 'membership')['hidden']);

        // Even posted directly, a switched-off column carries no data.
        $this->raise([[
            'membership' => 'Gold', 'plan_name' => 'A', 'qty' => 1, 'unit_price' => 1000,
        ]])->assertCreated();

        $this->assertNull(InvoiceItem::firstOrFail()->membership);
    }

    public function test_the_money_columns_are_rename_only(): void
    {
        // Renaming is fine…
        $this->customise($this->adminA, ['builtin_key' => 'unit_price', 'label' => 'Rate Per Unit', 'type' => 'number']);
        $this->assertSame('Rate Per Unit', $this->column($this->adminA, 'unit_price')['label']);

        // …hiding is not: the line total is worked out from it.
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order', 'builtin_key' => 'qty', 'label' => 'Qty', 'type' => 'number', 'is_hidden' => true,
        ])->assertStatus(422);
    }

    public function test_a_date_pair_cannot_become_a_dropdown(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order', 'builtin_key' => 'validity', 'label' => 'Period',
            'type' => 'select', 'options' => ['Q1', 'Q2'],
        ])->assertStatus(422);

        // Renaming it is fine.
        $this->customise($this->adminA, ['builtin_key' => 'validity', 'label' => 'Service Period', 'type' => 'daterange']);
        $this->assertSame('Service Period', $this->column($this->adminA, 'validity')['label']);
    }

    public function test_a_column_carries_one_customisation_at_a_time(): void
    {
        $this->customise($this->adminA, ['builtin_key' => 'plan_name', 'label' => 'Service', 'type' => 'text']);

        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order', 'builtin_key' => 'plan_name', 'label' => 'Product', 'type' => 'text',
        ])->assertStatus(422);
    }

    public function test_removing_a_customisation_restores_our_column(): void
    {
        $uuid = $this->customise($this->adminA, ['builtin_key' => 'plan_name', 'label' => 'Service', 'type' => 'text']);
        $this->assertSame('Service', $this->column($this->adminA, 'plan_name')['label']);

        $this->actingAs($this->adminA)->deleteJson("/api/v1/crm/workspace-fields/{$uuid}")->assertOk();

        $column = $this->column($this->adminA, 'plan_name');
        $this->assertSame('Plan name', $column['label']);
        $this->assertFalse($column['customised']);
    }

    public function test_only_work_order_columns_can_be_customised_this_way(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'client', 'builtin_key' => 'plan_name', 'label' => 'Service', 'type' => 'text',
        ])->assertStatus(422);

        // An unknown column is refused by name, not by a field error — the
        // message says which form has no such column.
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order', 'builtin_key' => 'not_a_column', 'label' => 'X', 'type' => 'text',
        ])->assertStatus(422);
    }

    public function test_the_method_lists_added_fields_after_our_own(): void
    {
        $this->customise($this->adminA, ['label' => 'Port Of Loading', 'type' => 'text']);

        $method = $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')
            ->assertOk()->json('data.work_order_method');

        $last = end($method);
        $this->assertSame('custom', $last['source']);
        $this->assertSame('port_of_loading', $last['key']);
    }
}
