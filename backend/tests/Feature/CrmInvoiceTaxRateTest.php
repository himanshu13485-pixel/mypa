<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tax by percentage. Nobody quotes GST in rupees, so a rate may be given
 * instead of a figure — and when it is, the server works the figure out, so
 * the two can never end up disagreeing on the same document.
 */
class CrmInvoiceTaxRateTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected int $issuingCompanyId;
    protected string $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])
            ->assertCreated()->json('data.uuid');
    }

    /** Raise a document and read it back in full — the figures live there. */
    private function raise(array $money): array
    {
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ] + $money)->assertCreated()->json('data.uuid');

        return $this->full($uuid);
    }

    private function full(string $uuid): array
    {
        return $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()->json('data');
    }

    public function test_a_rate_becomes_an_amount(): void
    {
        $invoice = $this->raise(['cgst_rate' => 9, 'sgst_rate' => 9]);

        $this->assertEquals(900, $invoice['cgst']);
        $this->assertEquals(900, $invoice['sgst']);
        $this->assertEquals(11800, $invoice['total']);
        // The rate is kept, so the document can print "CGST @ 9%".
        $this->assertEquals(9, $invoice['cgst_rate']);
    }

    public function test_the_discount_comes_off_before_the_tax_is_worked_out(): void
    {
        // 10,000 − 10% = 9,000 taxable; 18% IGST on that is 1,620.
        $invoice = $this->raise(['discount_rate' => 10, 'igst_rate' => 18]);

        $this->assertEquals(1000, $invoice['discount']);
        $this->assertEquals(1620, $invoice['igst']);
        $this->assertEquals(9000, $invoice['taxable']);
        $this->assertEquals(10620, $invoice['total']);
    }

    public function test_tds_is_deducted_at_its_rate(): void
    {
        $invoice = $this->raise(['tds_rate' => 2]);

        $this->assertEquals(200, $invoice['tds']);
        $this->assertEquals(9800, $invoice['total']);
    }

    public function test_a_typed_amount_still_works_and_a_rate_overrules_it(): void
    {
        // No rate: the figure typed is the figure used.
        $plain = $this->raise(['cgst' => 750]);
        $this->assertEquals(750, $plain['cgst']);
        $this->assertNull($plain['cgst_rate']);

        // Both given: the rate is the one the company meant.
        $both = $this->raise(['cgst' => 750, 'cgst_rate' => 9]);
        $this->assertEquals(900, $both['cgst']);
    }

    public function test_a_rate_is_a_percentage_not_a_multiplier(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'cgst_rate' => 190,
            'items' => [['plan_name' => 'A', 'qty' => 1, 'unit_price' => 100]],
        ])->assertStatus(422)->assertJsonValidationErrors('cgst_rate');
    }

    public function test_editing_recomputes_from_the_new_lines(): void
    {
        $invoice = $this->raise(['cgst_rate' => 9, 'sgst_rate' => 9]);

        // The lines double; the percentages stay, so the tax doubles too.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/invoices/{$invoice['uuid']}", [
            'invoice_date' => '2026-08-20',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 2, 'unit_price' => 10000]],
        ])->assertOk();
        $updated = $this->full($invoice['uuid']);

        $this->assertEquals(20000, $updated['subtotal']);
        $this->assertEquals(1800, $updated['cgst']);
        $this->assertEquals(23600, $updated['total']);
    }

    public function test_a_converted_proforma_keeps_the_rates(): void
    {
        $proforma = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        $invoice = $this->full(
            $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$proforma}/convert")
                ->assertCreated()->json('data.uuid')
        );

        $this->assertEquals(9, $invoice['cgst_rate']);
        $this->assertEquals(900, $invoice['cgst']);
        $this->assertEquals(11800, $invoice['total']);
    }
}
