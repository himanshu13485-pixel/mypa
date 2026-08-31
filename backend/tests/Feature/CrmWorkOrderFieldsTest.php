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
 * The Work Order method: the lines of a proforma or invoice.
 *
 * Every company words a work order differently, so each defines its own line
 * fields through the Dedicated Company Workspace — requested by the company,
 * approved by the Super Admin, live in that workspace alone.
 */
class CrmWorkOrderFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $adminA;
    protected User $adminB;
    protected Organization $orgA;
    protected Organization $orgB;
    protected int $companyA;
    protected int $companyB;

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

        $this->companyA = $this->issuingCompany($this->adminA);
        $this->companyB = $this->issuingCompany($this->adminB);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function issuingCompany(User $admin): int
    {
        return $this->actingAs($admin)->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');
    }

    /** Ask for a Work Order field and have the Super Admin approve it. */
    private function approvedField(User $admin, array $payload): string
    {
        $uuid = $this->actingAs($admin)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order',
        ] + $payload)->assertCreated()->json('data.uuid');

        $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", [
            'status' => 'approved',
        ])->assertOk();

        return $uuid;
    }

    private function clientUuid(User $admin, string $name = 'Bhavya Steel'): string
    {
        return $this->actingAs($admin)->postJson('/api/v1/crm/clients', ['company_name' => $name])
            ->assertCreated()->json('data.uuid');
    }

    public function test_a_pending_work_order_field_is_not_on_the_form_yet(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'work_order', 'label' => 'Port Of Loading', 'type' => 'text',
        ])->assertCreated();

        $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')
            ->assertOk()->assertJsonCount(0, 'data.work_order_custom_fields');
    }

    public function test_an_approved_field_becomes_part_of_this_companys_work_order(): void
    {
        $this->approvedField($this->adminA, ['label' => 'Port Of Loading', 'type' => 'text']);

        $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->assertJsonCount(1, 'data.work_order_custom_fields')
            ->assertJsonPath('data.work_order_custom_fields.0.key', 'port_of_loading')
            ->assertJsonPath('data.work_order_custom_fields.0.entity', 'work_order');

        // The other company's Work Order is untouched.
        $this->actingAs($this->adminB)->getJson('/api/v1/crm/masters')
            ->assertOk()->assertJsonCount(0, 'data.work_order_custom_fields');
    }

    public function test_work_order_values_are_saved_on_the_line_and_read_back(): void
    {
        $this->approvedField($this->adminA, ['label' => 'Port Of Loading', 'type' => 'text']);
        $this->approvedField($this->adminA, ['label' => 'Site Visit Done', 'type' => 'checkbox']);

        $uuid = $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyA,
            'client_uuid' => $this->clientUuid($this->adminA),
            'invoice_date' => '2026-08-20',
            'items' => [
                [
                    'plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 8000,
                    'custom_fields' => ['port_of_loading' => 'Nhava Sheva', 'site_visit_done' => true],
                ],
                [
                    'plan_name' => 'B2B PAGES', 'qty' => 1, 'unit_price' => 2000,
                    'custom_fields' => ['port_of_loading' => 'Mundra', 'site_visit_done' => false],
                ],
            ],
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminA)->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.items.0.custom_fields.port_of_loading', 'Nhava Sheva')
            ->assertJsonPath('data.items.0.custom_fields.site_visit_done', true)
            ->assertJsonPath('data.items.1.custom_fields.port_of_loading', 'Mundra')
            ->assertJsonPath('data.items.1.custom_fields.site_visit_done', false);
    }

    public function test_the_work_order_method_is_enforced_line_by_line(): void
    {
        $this->approvedField($this->adminA, ['label' => 'Port Of Loading', 'type' => 'text', 'is_required' => true]);
        $this->approvedField($this->adminA, [
            'label' => 'Shipment Mode', 'type' => 'select', 'options' => ['Sea', 'Air'],
        ]);

        $base = [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyA,
            'client_uuid' => $this->clientUuid($this->adminA),
            'invoice_date' => '2026-08-20',
        ];

        // A required field missing on the SECOND line still fails.
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', $base + ['items' => [
            ['plan_name' => 'A', 'qty' => 1, 'unit_price' => 100, 'custom_fields' => ['port_of_loading' => 'Mundra']],
            ['plan_name' => 'B', 'qty' => 1, 'unit_price' => 100],
        ]])->assertStatus(422)->assertJsonValidationErrors('items.1.custom_fields.port_of_loading');

        // A value outside the company's own options is refused.
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', $base + ['items' => [
            ['plan_name' => 'A', 'qty' => 1, 'unit_price' => 100, 'custom_fields' => [
                'port_of_loading' => 'Mundra', 'shipment_mode' => 'Rocket',
            ]],
        ]])->assertStatus(422)->assertJsonValidationErrors('items.0.custom_fields.shipment_mode');

        // A key this company never asked for is dropped, not stored.
        $uuid = $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', $base + ['items' => [
            ['plan_name' => 'A', 'qty' => 1, 'unit_price' => 100, 'custom_fields' => [
                'port_of_loading' => 'Mundra', 'smuggled' => 'nope',
            ]],
        ]])->assertCreated()->json('data.uuid');

        $stored = InvoiceItem::firstOrFail()->custom_fields;
        $this->assertSame(['port_of_loading' => 'Mundra'], $stored);
        $this->assertNotNull($uuid);
    }

    public function test_the_work_order_travels_from_proforma_to_invoice(): void
    {
        $this->approvedField($this->adminA, ['label' => 'Port Of Loading', 'type' => 'text']);

        $proforma = $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma',
            'issuing_company_id' => $this->companyA,
            'client_uuid' => $this->clientUuid($this->adminA),
            'invoice_date' => '2026-08-20',
            'items' => [[
                'plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 8000,
                'custom_fields' => ['port_of_loading' => 'Nhava Sheva'],
            ]],
        ])->assertCreated()->json('data.uuid');

        $invoice = $this->actingAs($this->adminA)->postJson("/api/v1/crm/invoices/{$proforma}/convert")
            ->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminA)->getJson("/api/v1/crm/invoices/{$invoice}")
            ->assertOk()
            ->assertJsonPath('data.items.0.custom_fields.port_of_loading', 'Nhava Sheva');
    }

    public function test_one_companys_work_order_field_never_reaches_another(): void
    {
        $this->approvedField($this->adminA, ['label' => 'Port Of Loading', 'type' => 'text', 'is_required' => true]);

        // Globex has no such field: their line saves without it, and the
        // value is not stored even if someone posts it.
        $uuid = $this->actingAs($this->adminB)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyB,
            'client_uuid' => $this->clientUuid($this->adminB, 'Globex Client'),
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'A', 'qty' => 1, 'unit_price' => 100, 'custom_fields' => [
                'port_of_loading' => 'Mundra',
            ]]],
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminB)->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.items.0.custom_fields', []);
    }

    public function test_only_the_two_known_forms_can_carry_dcw_fields(): void
    {
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'salary', 'label' => 'Anything', 'type' => 'text',
        ])->assertStatus(422)->assertJsonValidationErrors('entity');
    }
}
