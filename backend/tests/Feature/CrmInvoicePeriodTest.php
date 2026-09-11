<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Which stretch of time a ledger is read in.
 *
 * Named periods rather than two dates, because "the previous financial
 * year" is what people ask for and working the dates out by hand is where
 * the wrong figure comes from. The Indian financial year runs April to
 * March; the calendar year is the calendar year; and confusing the two is
 * how a wrong number reaches an accountant.
 */
class CrmInvoicePeriodTest extends TestCase
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
        // A Thursday in September 2026: FY 2026-27, CY 2026.
        Carbon::setTestNow('2026-09-11 10:00:00');

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel',
        ])->assertCreated()->json('data.uuid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $date, float $amount): string
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => $date,
            'due_date' => '2027-12-31',
            'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance',
            'subscription_type' => 'online', 'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31',
                'plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => $amount,
            ]],
        ])->assertCreated()->json('data.number');
    }

    private function totals(string $period): array
    {
        return $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&period=' . $period)
            ->assertOk()->json('totals');
    }

    public function test_each_named_period_covers_what_it_says(): void
    {
        $this->invoice('2026-09-05', 1000);   // this month
        $this->invoice('2026-08-20', 2000);   // last month
        $this->invoice('2026-05-10', 4000);   // this FY, this CY, 4 months back
        $this->invoice('2026-02-14', 8000);   // previous FY, this CY
        $this->invoice('2025-11-30', 16000);  // previous FY, previous CY

        $this->assertSame(1, $this->totals('this_month')['count']);
        $this->assertSame(1000.0, (float) $this->totals('this_month')['total']);

        $this->assertSame(1, $this->totals('last_month')['count']);
        $this->assertSame(2000.0, (float) $this->totals('last_month')['total']);

        // Rolling from today: since 11 June, so September and August only.
        $this->assertSame(2, $this->totals('last_3_months')['count']);
        // Since 11 March: adds the May one.
        $this->assertSame(3, $this->totals('last_6_months')['count']);
        // Since 11 September 2025: everything but nothing older.
        $this->assertSame(5, $this->totals('last_12_months')['count']);

        // April 2026 to March 2027.
        $this->assertSame(3, $this->totals('this_fy')['count']);
        // April 2025 to March 2026: February and November.
        $this->assertSame(2, $this->totals('prev_fy')['count']);
        $this->assertSame(24000.0, (float) $this->totals('prev_fy')['total']);

        // The calendar year is a different cut of the same rows.
        $this->assertSame(4, $this->totals('this_cy')['count']);
        $this->assertSame(1, $this->totals('prev_cy')['count']);
        $this->assertSame(16000.0, (float) $this->totals('prev_cy')['total']);

        // And no period at all is still everything, so the screens that ask
        // for a document by name are not quietly limited to this month.
        $this->assertSame(5, $this->totals('')['count']);
    }

    public function test_typed_dates_beat_the_named_period(): void
    {
        $this->invoice('2026-09-05', 1000);
        $this->invoice('2026-08-20', 2000);

        $totals = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&period=this_month&date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()->json('totals');

        $this->assertSame(1, $totals['count']);
        $this->assertSame(2000.0, (float) $totals['total']);
    }

    public function test_the_period_comes_back_broken_into_buckets_for_the_charts(): void
    {
        $this->invoice('2026-09-05', 1000);
        $this->invoice('2026-09-05', 500);
        $this->invoice('2026-09-09', 2000);

        // A month is short, so the buckets are days.
        $daily = $this->totals('this_month')['series'];
        $this->assertCount(2, $daily);
        $this->assertSame('05 Sep', $daily[0]['label']);
        $this->assertSame(1500.0, (float) $daily[0]['total']);
        $this->assertSame(2, $daily[0]['count']);
        $this->assertSame('09 Sep', $daily[1]['label']);

        // A year is not, so they are months.
        $this->invoice('2026-05-10', 4000);
        $monthly = $this->totals('last_12_months')['series'];
        $this->assertSame(['May 2026', 'Sep 2026'], collect($monthly)->pluck('label')->all());
        $this->assertSame(3500.0, (float) collect($monthly)->firstWhere('label', 'Sep 2026')['total']);
    }
}
