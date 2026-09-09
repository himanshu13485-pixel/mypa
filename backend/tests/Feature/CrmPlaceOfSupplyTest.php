<?php

namespace Tests\Feature;

use App\Models\Crm\CustomField;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CGST and SGST, or IGST — and never the wrong one.
 *
 * A sale within one state carries CGST and SGST; a sale across a state line
 * carries IGST. Which it is, is not an opinion: the issuing company's state
 * code against the first two digits of the client's GST number answers it.
 *
 * The form greys the box that does not apply, but greying is not the rule —
 * a standing CGST rate sat behind that box and would have been charged on
 * every document whichever side the client was on. So the rule lives here,
 * where the document is actually made.
 *
 * The Other tax line, TDS and a discount are untouched: they do not answer
 * the intra/inter-state question, so nothing about the client's state has
 * anything to say about them.
 */
class CrmPlaceOfSupplyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->adminUser->id,
            'crm_role' => 'admin',
            'status' => 'active',
        ]);

        // Haryana.
        $this->companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            'state_code' => '06',
        ])->assertCreated()->json('data.id');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function client(string $name, ?string $gstin): string
    {
        return $this->as()->postJson('/api/v1/crm/clients', array_filter([
            'company_name' => $name,
            'gst_no' => $gstin,
        ]))->assertCreated()->json('data.uuid');
    }

    /** Raise a document charging every line, and read back what stuck. */
    private function raise(string $clientUuid, array $over = []): array
    {
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $over + [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'other_tax_rate' => 2,
            'items' => [[
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01',
                'validity_to' => '2027-08-31',
                'qty' => 1,
                'unit_price' => 10000,
            ]],
        ])->assertCreated()->json('data.uuid');

        return $this->charged($uuid);
    }

    /** What each money line came to on a saved document. */
    private function charged(string $uuid): array
    {
        return collect($this->as()->getJson("/api/v1/crm/invoices/{$uuid}")->assertOk()->json('data.tax_lines'))
            ->mapWithKeys(fn ($line) => [$line['key'] => (float) $line['amount']])
            ->all();
    }

    public function test_a_client_in_the_same_state_is_charged_cgst_and_sgst(): void
    {
        $charged = $this->raise($this->client('Meridian Motors', '06AABCM1234C1ZX'));

        $this->assertSame(900.0, $charged['cgst']);
        $this->assertSame(900.0, $charged['sgst']);
        // Asked for at 18% and still nil: a document cannot carry both.
        $this->assertSame(0.0, $charged['igst']);
    }

    public function test_a_client_in_another_state_is_charged_igst(): void
    {
        $charged = $this->raise($this->client('Kalyani Engineering', '27AABCK1234C1ZX'));

        $this->assertSame(1800.0, $charged['igst']);
        $this->assertSame(0.0, $charged['cgst']);
        $this->assertSame(0.0, $charged['sgst']);
    }

    public function test_the_other_lines_are_charged_wherever_the_client_is(): void
    {
        foreach (['06AABCM1234C1ZX' => 'Meridian Motors', '27AABCK1234C1ZX' => 'Kalyani Engineering'] as $gstin => $name) {
            $charged = $this->raise($this->client($name, $gstin));

            $this->assertSame(200.0, $charged['other_tax'], 'Other tax is nobody\'s place of supply');
        }
    }

    public function test_a_standing_rate_behind_a_greyed_line_is_not_charged_either(): void
    {
        // The trap this rule exists for: a company with CGST and SGST set to
        // 9% by default, raising to a client in another state. Nothing is
        // typed into either box — and before this, both were charged anyway.
        foreach (['cgst', 'sgst'] as $key) {
            CustomField::create([
                'organization_id' => $this->org->id,
                'entity' => 'tax', 'key' => $key, 'label' => strtoupper($key),
                'type' => 'number', 'is_builtin' => true, 'status' => 'approved',
                'default_rate' => 9,
            ]);
        }

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->client('Kalyani Engineering', '27AABCK1234C1ZX'),
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'igst_rate' => 18,
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01', 'validity_to' => '2027-08-31',
                'qty' => 1, 'unit_price' => 10000,
            ]],
        ])->assertCreated()->json('data.uuid');

        $charged = $this->charged($uuid);
        $this->assertSame(0.0, $charged['cgst']);
        $this->assertSame(0.0, $charged['sgst']);
        $this->assertSame(1800.0, $charged['igst']);
        // And the total is the subtotal plus IGST alone.
        $this->assertSame('11800.00', $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")->json('data.total'));
    }

    public function test_nothing_is_ruled_out_when_a_client_has_no_gst_number(): void
    {
        // Unregistered, so there is no state to compare and no line to grey.
        // Refusing to guess is what lets the document be raised at all.
        $charged = $this->raise($this->client('Shreya Consultancy', null));

        $this->assertSame(900.0, $charged['cgst']);
        $this->assertSame(900.0, $charged['sgst']);
        $this->assertSame(1800.0, $charged['igst']);
    }

    public function test_an_edit_is_held_to_the_same_rule(): void
    {
        // The form need not send the issuing company on an edit — the series
        // identity never changes — so the rule reads it off the document.
        $clientUuid = $this->client('Kalyani Engineering', '27AABCK1234C1ZX');

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01',
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online',
            'dispatch_status' => 'pending', 'igst_rate' => 18,
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01', 'validity_to' => '2027-08-31',
                'qty' => 1, 'unit_price' => 10000,
            ]],
        ])->assertCreated()->json('data.uuid');

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", [
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01',
            'client_uuid' => $clientUuid,
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'cgst_rate' => 9, 'sgst_rate' => 9,
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01', 'validity_to' => '2027-08-31',
                'qty' => 1, 'unit_price' => 10000,
            ]],
        ])->assertOk();

        $charged = $this->charged($uuid);
        $this->assertSame(0.0, $charged['cgst']);
        $this->assertSame(0.0, $charged['sgst']);
    }

    public function test_a_company_that_requires_tax_is_answered_by_the_line_that_applies(): void
    {
        $this->as()->putJson("/api/v1/crm/masters/issuing-companies/{$this->companyId}", [
            'name' => 'Acme Billing Pvt Ltd', 'state_code' => '06', 'tax_required' => true,
        ])->assertOk();

        $far = $this->client('Kalyani Engineering', '27AABCK1234C1ZX');
        $document = [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $far,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01',
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01', 'validity_to' => '2027-08-31',
                'qty' => 1, 'unit_price' => 10000,
            ]],
        ];

        // CGST on an inter-state sale is dropped from the document, so it
        // cannot be the tax that satisfies "every document carries tax".
        $this->as()->postJson('/api/v1/crm/invoices', $document + ['cgst_rate' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cgst');

        $this->as()->postJson('/api/v1/crm/invoices', $document + ['igst_rate' => 18])->assertCreated();
    }
}
