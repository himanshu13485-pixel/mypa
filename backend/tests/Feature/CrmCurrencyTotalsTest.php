<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Totals across documents in more than one currency.
 *
 * Every screen that added invoices up added their raw figures, so a
 * thousand-dollar invoice went into the dashboard, the reports and the
 * list's charts as a thousand rupees — wrong by a factor of ninety-odd, and
 * wrong without anything looking broken.
 *
 * Two different fixes, depending on what the figure is for:
 *
 *   A rupee total — the dashboard, the reports, a chart with one axis —
 *   counts a foreign document at the INR equivalent frozen on it, the rule
 *   the P&L already used, so all of them agree.
 *
 *   A figure about documents — the list's consolidated foot, a salesperson's
 *   card — is given once per currency, since CGST in dollars and CGST in
 *   rupees are two numbers an accountant needs apart.
 */
class CrmCurrencyTotalsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private string $inrDoc;
    private string $usdDoc;

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

        $companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Northwind Trading LLC',
        ])->assertCreated()->json('data.uuid');

        $raise = fn (string $currency) => $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $companyId,
            'client_uuid' => $clientUuid,
            'currency' => $currency,
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
                'unit_price' => 1000,
            ]],
        ])->assertCreated()->json('data.uuid');

        // A thousand rupees, and a thousand dollars of which half is paid.
        $this->inrDoc = $raise('INR');
        $this->usdDoc = $raise('USD');

        InvoicePayment::create([
            'invoice_id' => Invoice::where('uuid', $this->usdDoc)->value('id'),
            'amount' => 500,
            'received_at' => now()->toDateString(),
        ]);
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    // ---- the one rule ------------------------------------------------------

    public function test_a_document_knows_what_it_is_worth_in_rupees(): void
    {
        $usd = Invoice::where('uuid', $this->usdDoc)->firstOrFail();
        $inr = Invoice::where('uuid', $this->inrDoc)->firstOrFail();

        $this->assertEquals(94.0, $usd->rupeeRate());
        $this->assertEquals(47000.0, $usd->inRupees(500));
        $this->assertEquals(1.0, $inr->rupeeRate());
    }

    public function test_a_foreign_document_with_no_stored_rate_counts_at_face_value(): void
    {
        // Saved while no rate could be fetched. It still belongs in the
        // totals, at face value, as the P&L has always counted it.
        $orphan = new Invoice(['currency' => 'EUR', 'total' => 300]);

        $this->assertEquals(1.0, $orphan->rupeeRate());
    }

    // ---- rupee totals ------------------------------------------------------

    public function test_the_dashboard_counts_dollars_as_rupees_at_the_frozen_rate(): void
    {
        $invoices = $this->as()->getJson('/api/v1/crm/dashboard')->assertOk()->json('data.invoices');

        // ₹1,000 + $1,000 × 94.
        $this->assertEquals(95000, $invoices['month_total']);
        // $500 received, × 94.
        $this->assertEquals(47000, $invoices['received_this_month']);
        // ₹1,000 unpaid, and $500 still owed × 94.
        $this->assertEquals(48000, $invoices['outstanding']);
    }

    public function test_the_dashboard_chart_amounts_are_rupees_too(): void
    {
        $due = collect($this->as()->getJson('/api/v1/crm/dashboard')->assertOk()->json('data.charts.invoices_by_payment'))
            ->firstWhere('status', 'due');

        $this->assertSame(2, $due['count']);
        $this->assertEquals(95000, $due['amount']);
    }

    public function test_the_reports_agree(): void
    {
        $data = $this->as()->getJson('/api/v1/crm/reports/overview')->assertOk()->json('data');

        $this->assertEquals(95000, $data['totals']['invoiced']);
        $this->assertEquals(47000, $data['totals']['received']);
        $this->assertEquals(95000, collect($data['top_clients'])->firstWhere('name', 'Northwind Trading LLC')['amount']);
    }

    public function test_the_list_charts_are_drawn_in_rupees(): void
    {
        $series = $this->as()->getJson('/api/v1/crm/invoices?kind=invoice')->assertOk()->json('totals.series');

        // One axis, one unit.
        $this->assertEquals(95000, collect($series)->sum('total'));
        $this->assertEquals(47000, collect($series)->sum('received'));
    }

    // ---- figures about documents, per currency ----------------------------

    public function test_the_consolidated_foot_is_given_once_per_currency(): void
    {
        $blocks = collect($this->as()->getJson('/api/v1/crm/invoices?kind=invoice')
            ->assertOk()->json('totals.consolidated_by_currency'));

        // Rupees first: the book the company keeps.
        $this->assertSame(['INR', 'USD'], $blocks->pluck('currency')->all());
        $this->assertEquals(1000, $blocks[0]['total']);
        $this->assertEquals(0, $blocks[0]['received']);
        $this->assertEquals(1000, $blocks[1]['total']);
        $this->assertEquals(500, $blocks[1]['received']);
        $this->assertEquals(500, $blocks[1]['due']);
    }

    public function test_a_salesperson_card_shows_each_currency_and_ranks_in_rupees(): void
    {
        $row = collect($this->as()->getJson('/api/v1/crm/invoices?kind=invoice')
            ->assertOk()->json('totals.by_salesperson'))->first();

        $this->assertEqualsCanonicalizing(['INR', 'USD'], collect($row['by_currency'])->pluck('currency')->all());
        $this->assertEquals(1000, collect($row['by_currency'])->firstWhere('currency', 'USD')['total']);
        // The figure the cards are ordered by is rupees.
        $this->assertEquals(95000, $row['total']);
    }

    public function test_a_rupee_only_list_reads_exactly_as_it_did(): void
    {
        // The documents of a company that never bills abroad come out
        // unchanged: one currency, one block, the same figures as before.
        Invoice::where('uuid', $this->usdDoc)->delete();

        $totals = $this->as()->getJson('/api/v1/crm/invoices?kind=invoice')->assertOk()->json('totals');

        $this->assertCount(1, $totals['consolidated_by_currency']);
        $this->assertEquals($totals['consolidated']['total'], $totals['consolidated_by_currency'][0]['total']);
        $this->assertEquals(1000, collect($totals['series'])->sum('total'));
    }
}
