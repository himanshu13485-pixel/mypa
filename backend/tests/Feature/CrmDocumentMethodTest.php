<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rest of the document, made a company's own: the header fields it words
 * or switches off, the extra ones it adds, and — the part that matters most —
 * its own money lines instead of our fixed six.
 */
class CrmDocumentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $adminA;
    protected User $adminB;
    protected Organization $orgA;
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
        $orgB = Organization::create(['name' => 'Globex Ltd', 'code' => 'GLOBEX']);
        Member::create(['organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id, 'crm_role' => 'admin']);
        Member::create(['organization_id' => $orgB->id, 'user_id' => $this->adminB->id, 'crm_role' => 'admin']);

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

    private function approved(User $admin, array $payload): string
    {
        $uuid = $this->actingAs($admin)->postJson('/api/v1/crm/workspace-fields', $payload)
            ->assertCreated()->json('data.uuid');

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/crm/field-requests/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk();

        return $uuid;
    }

    private function raise(array $payload = []): array
    {
        $uuid = $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyA,
            'client_uuid' => $this->clientA,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ] + $payload)->assertCreated()->json('data.uuid');

        return $this->actingAs($this->adminA)->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()->json('data');
    }

    private function line(array $invoice, string $key): ?array
    {
        return collect($invoice['tax_lines'])->firstWhere('key', $key);
    }

    // ---- The document's own fields -----------------------------------------

    public function test_a_company_starts_with_our_document_fields(): void
    {
        $method = $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')
            ->assertOk()->json('data.invoice_method');

        $this->assertSame('Due date', collect($method)->firstWhere('key', 'due_date')['label']);
        $this->assertFalse(collect($method)->firstWhere('key', 'due_date')['hidden']);
    }

    public function test_a_header_field_can_be_re_worded_or_switched_off(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'invoice', 'builtin_key' => 'terms_of_payment',
            'label' => 'Payment Terms', 'type' => 'select', 'options' => ['Advance', '30 Days'],
        ]);
        $this->approved($this->adminA, [
            'entity' => 'invoice', 'builtin_key' => 'subscription_type',
            'label' => 'Subscription', 'type' => 'select', 'is_hidden' => true,
        ]);

        $method = collect($this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')
            ->assertOk()->json('data.invoice_method'))->keyBy('key');

        $this->assertSame('Payment Terms', $method['terms_of_payment']['label']);
        $this->assertSame(['Advance', '30 Days'], $method['terms_of_payment']['options']);
        $this->assertTrue($method['subscription_type']['hidden']);

        // A switched-off field carries no data, even posted directly.
        $this->raise(['subscription_type' => 'online']);
        $this->assertNull(Invoice::firstOrFail()->subscription_type);
    }

    public function test_what_is_picked_from_another_section_is_not_a_document_field(): void
    {
        // The issuing company, the client and the salesperson each come from
        // their own section, so they are not on this form to re-word.
        $method = collect($this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')
            ->assertOk()->json('data.invoice_method'))->pluck('key');

        foreach (['issuing_company', 'client', 'member'] as $key) {
            $this->assertFalse($method->contains($key), $key . ' should not be a document field');

            $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
                'entity' => 'invoice', 'builtin_key' => $key, 'label' => 'Customer', 'type' => 'text',
            ])->assertStatus(422);
        }

        // The date is still ours to rename, and cannot be switched off.
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/workspace-fields', [
            'entity' => 'invoice', 'builtin_key' => 'invoice_date', 'label' => 'Raised On',
            'type' => 'date', 'is_hidden' => true,
        ])->assertStatus(422);

        $this->approved($this->adminA, [
            'entity' => 'invoice', 'builtin_key' => 'invoice_date', 'label' => 'Raised On', 'type' => 'date',
        ]);
        $again = collect($this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')->json('data.invoice_method'));
        $this->assertSame('Raised On', $again->firstWhere('key', 'invoice_date')['label']);
    }

    public function test_a_company_can_add_its_own_document_fields(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'invoice', 'label' => 'Purchase Order No', 'type' => 'text', 'is_required' => true,
        ]);

        // Required means required.
        $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyA,
            'client_uuid' => $this->clientA,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'A', 'qty' => 1, 'unit_price' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('custom_fields.purchase_order_no');

        $invoice = $this->raise(['custom_fields' => ['purchase_order_no' => 'PO-4471', 'smuggled' => 'nope']]);

        $this->assertSame('PO-4471', $invoice['custom_fields']['purchase_order_no']);
        $this->assertArrayNotHasKey('smuggled', $invoice['custom_fields']);
    }

    // ---- The money lines ----------------------------------------------------

    public function test_a_company_starts_with_our_money_lines(): void
    {
        $setup = $this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')->assertOk()->json('data.tax_setup');

        $this->assertSame(
            ['discount', 'cgst', 'sgst', 'igst', 'other_tax', 'tds'],
            collect($setup)->pluck('key')->all(),
        );

        // And they still behave exactly as they did before any of this.
        $invoice = $this->raise(['cgst_rate' => 9, 'sgst_rate' => 9]);
        $this->assertEquals(11800, $invoice['total']);
        $this->assertEquals(900, $this->line($invoice, 'cgst')['amount']);
        $this->assertEquals(900, $invoice['cgst']);
    }

    public function test_a_money_line_can_be_re_worded_and_given_a_standing_rate(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'tax', 'builtin_key' => 'other_tax', 'label' => 'Cess', 'type' => 'number',
            'default_rate' => 4,
        ]);

        $setup = collect($this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')->json('data.tax_setup'))
            ->keyBy('key');
        $this->assertSame('Cess', $setup['other_tax']['label']);
        $this->assertEquals(4, $setup['other_tax']['default_rate']);

        // The standing rate applies without anyone typing it.
        $invoice = $this->raise([]);
        $this->assertEquals(400, $this->line($invoice, 'other_tax')['amount']);
        $this->assertSame('Cess', $this->line($invoice, 'other_tax')['label']);
        $this->assertEquals(10400, $invoice['total']);
    }

    public function test_a_money_line_a_company_never_charges_is_gone(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'tax', 'builtin_key' => 'tds', 'label' => 'TDS', 'type' => 'number', 'is_hidden' => true,
        ]);

        $setup = collect($this->actingAs($this->adminA)->getJson('/api/v1/crm/masters')->json('data.tax_setup'));
        $this->assertNull($setup->firstWhere('key', 'tds'));

        // Posted anyway, it changes nothing.
        $invoice = $this->raise(['tds_rate' => 10]);
        $this->assertNull($this->line($invoice, 'tds'));
        $this->assertEquals(10000, $invoice['total']);
    }

    public function test_a_company_can_charge_a_tax_of_its_own(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'tax', 'label' => 'Service Charge', 'type' => 'number',
            'tax_kind' => 'tax', 'tax_basis' => 'subtotal', 'default_rate' => 5,
        ]);

        $invoice = $this->raise(['cgst_rate' => 9]);

        $charge = $this->line($invoice, 'service_charge');
        $this->assertSame('Service Charge', $charge['label']);
        $this->assertEquals(500, $charge['amount']);
        // 10,000 + 900 CGST + 500 service charge.
        $this->assertEquals(11400, $invoice['total']);
    }

    public function test_a_line_of_its_own_can_take_money_off(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'tax', 'label' => 'Loyalty Rebate', 'type' => 'number', 'tax_kind' => 'deduction',
        ]);

        $invoice = $this->raise([
            'tax_lines' => [['key' => 'loyalty_rebate', 'rate' => 10]],
        ]);

        $this->assertEquals(1000, $this->line($invoice, 'loyalty_rebate')['amount']);
        $this->assertEquals(9000, $invoice['total']);
    }

    public function test_a_discount_still_comes_off_before_the_taxes(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'tax', 'label' => 'Early Bird', 'type' => 'number', 'tax_kind' => 'discount',
        ]);

        // 10,000 − 10% early bird = 9,000 taxable; 18% IGST on that.
        $invoice = $this->raise([
            'tax_lines' => [['key' => 'early_bird', 'rate' => 10], ['key' => 'igst', 'rate' => 18]],
        ]);

        $this->assertEquals(1000, $this->line($invoice, 'early_bird')['amount']);
        $this->assertEquals(1620, $this->line($invoice, 'igst')['amount']);
        $this->assertEquals(9000, $invoice['taxable']);
        $this->assertEquals(10620, $invoice['total']);
    }

    public function test_the_document_keeps_the_wording_it_was_raised_with(): void
    {
        $uuid = $this->approved($this->adminA, [
            'entity' => 'tax', 'builtin_key' => 'cgst', 'label' => 'Central GST', 'type' => 'number',
        ]);

        $invoice = $this->raise(['cgst_rate' => 9]);
        $this->assertSame('Central GST', $this->line($invoice, 'cgst')['label']);

        // The company drops the customisation later; old paperwork is not
        // rewritten behind its back.
        $this->actingAs($this->adminA)->deleteJson("/api/v1/crm/workspace-fields/{$uuid}")->assertOk();

        $again = $this->actingAs($this->adminA)->getJson("/api/v1/crm/invoices/{$invoice['uuid']}")
            ->assertOk()->json('data');
        $this->assertSame('Central GST', $this->line($again, 'cgst')['label']);
    }

    public function test_the_money_lines_travel_to_the_converted_invoice(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'tax', 'label' => 'Service Charge', 'type' => 'number', 'default_rate' => 5,
        ]);

        $proforma = $this->actingAs($this->adminA)->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma',
            'issuing_company_id' => $this->companyA,
            'client_uuid' => $this->clientA,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        $invoiceUuid = $this->actingAs($this->adminA)->postJson("/api/v1/crm/invoices/{$proforma}/convert")
            ->assertCreated()->json('data.uuid');

        $invoice = $this->actingAs($this->adminA)->getJson("/api/v1/crm/invoices/{$invoiceUuid}")
            ->assertOk()->json('data');

        $this->assertEquals(500, $this->line($invoice, 'service_charge')['amount']);
        $this->assertEquals(10500, $invoice['total']);
    }

    public function test_one_companys_setup_is_not_anothers(): void
    {
        $this->approved($this->adminA, [
            'entity' => 'tax', 'builtin_key' => 'cgst', 'label' => 'Central GST', 'type' => 'number',
        ]);

        $setup = collect($this->actingAs($this->adminB)->getJson('/api/v1/crm/masters')->json('data.tax_setup'))
            ->keyBy('key');
        $this->assertSame('CGST', $setup['cgst']['label']);
    }
}
