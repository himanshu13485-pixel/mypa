<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The invoice list, with a company that bills in dollars among them.
 *
 * Its documents are in dollars, and the list added them in as rupees: a
 * $202 invoice read as ₹202 on the salesperson's card, in the headline and
 * down the charts. The document's currency comes from its issuing company,
 * as it always has; the list now respects it.
 */
class CrmInvoiceListCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private string $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // 96 at market, less the default two-rupee margin: 94 to the dollar.
        Cache::put('fx-inr-USD', 96.0, now()->addHour());

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Northwind Trading LLC',
        ])->assertCreated()->json('data.uuid');

        // A rupee company's ₹1,000 invoice and a dollar company's $202 one.
        $this->raise($this->company('Acme Billing Pvt Ltd', 'INR', 'INV-'), 1000);
        $this->raise($this->company('Acme Global LLC', 'USD', 'AG-'), 202);
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function company(string $name, string $currency, string $prefix): int
    {
        return $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => $name, 'currency' => $currency,
            'invoice_prefix' => $prefix, 'proforma_prefix' => 'P' . $prefix,
        ])->assertCreated()->json('data.id');
    }

    private function raise(int $companyId, float $price): void
    {
        $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1,
                'unit_price' => $price,
            ]],
        ])->assertCreated();
    }

    private function totals(): array
    {
        return $this->as()->getJson('/api/v1/crm/invoices?kind=invoice')->assertOk()->json('totals');
    }

    public function test_the_dollar_companys_invoice_is_in_dollars(): void
    {
        $this->assertSame('USD', Invoice::where('number', 'AG-1')->value('currency'));
    }

    public function test_the_headline_is_one_figure_per_currency(): void
    {
        $rows = collect($this->totals()['by_currency'])->keyBy('currency');

        $this->assertEquals(1000, $rows['INR']['total']);
        // $202, not ₹202 added into the rupees.
        $this->assertEquals(202, $rows['USD']['total']);
        $this->assertEquals(202, $rows['USD']['due']);
    }

    public function test_a_salesperson_card_shows_the_dollars_as_dollars(): void
    {
        $row = collect($this->totals()['by_salesperson'])->first();

        $byCurrency = collect($row['by_currency'])->keyBy('currency');
        $this->assertEquals(202, $byCurrency['USD']['total']);
        $this->assertEquals(1000, $byCurrency['INR']['total']);
        // Ranked in rupees: ₹1,000 plus $202 at 94.
        $this->assertEquals(1000 + 202 * 94, $row['total']);
    }

    public function test_the_consolidated_book_is_one_rupee_figure_naming_the_dollars_in_it(): void
    {
        $totals = $this->totals();

        // ₹1,000 plus $202 at the frozen 94.
        $this->assertEquals(1000 + 202 * 94, $totals['total']);
        $this->assertEquals(1000 + 202 * 94, $totals['due']);
        $this->assertEquals(1000 + 202 * 94, $totals['consolidated']['total']);
        $this->assertArrayNotHasKey('consolidated_by_currency', $totals);

        $foreign = collect($totals['foreign'])->keyBy('currency');
        $this->assertSame(['USD'], $foreign->keys()->all());
        $this->assertEquals(202, $foreign['USD']['total']);
        $this->assertEquals(202 * 94, $foreign['USD']['total_inr']);
    }

    public function test_a_salesperson_card_names_the_dollars_inside_its_rupees(): void
    {
        $row = collect($this->totals()['by_salesperson'])->first();

        $this->assertEquals(1000 + 202 * 94, $row['due']);
        $this->assertSame('USD', $row['foreign'][0]['currency']);
        $this->assertEquals(202, $row['foreign'][0]['due']);
    }

    public function test_a_proforma_for_the_dollar_company_is_in_dollars_too_and_converts_so(): void
    {
        $company = Invoice::where('number', 'AG-1')->value('issuing_company_id');
        $proforma = $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma',
            'issuing_company_id' => $company,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => now()->toDateString(), 'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1, 'unit_price' => 300,
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('USD', $proforma['currency']);
        $this->assertEquals(300 * 94, (float) $proforma['total_fx']);

        $invoice = $this->as()->postJson('/api/v1/crm/invoices/' . $proforma['uuid'] . '/convert')->assertSuccessful()->json('data');
        $this->assertSame('USD', Invoice::where('uuid', $invoice['uuid'])->value('currency'));
    }

    public function test_the_charts_count_the_dollars_at_their_rupee_value(): void
    {
        // One axis, one unit — never ₹202 for $202.
        $this->assertEquals(1000 + 202 * 94, collect($this->totals()['series'])->sum('total'));
    }
}
