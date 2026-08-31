<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\IncentivePlan;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalarySlip;
use App\Models\Crm\SalaryStructure;
use App\Services\Crm\IncentiveCalculator;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Incentives ledger and its rulings.
 *
 * What matters: the ledger reads client by client with each run's schedule;
 * a one-month hold pays the next month automatically as an arrear; a
 * standing hold pays everything withheld the month it is released, remarked
 * on the slip; a cancellation loses the months it covers and a regain
 * resumes only the future; and every ruling is on the trail.
 */
class CrmIncentiveLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $sellerUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $seller;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        $this->sellerUser = User::factory()->create(['email' => 'seller@acme.test']);
        $this->sellerUser->settings()->create([]);
        $this->sellerUser->profile()->create(['timezone' => 'UTC']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->seller = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->sellerUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'status' => 'active',
            'joined_at' => '2024-01-01', 'is_salesperson' => true,
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');

        // 6% over 12 months, no TDS: a 90,000 sale pays 450 a month.
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2025-01-01',
            'kind' => 'spread',
            'config' => ['percent' => 6, 'spread_months' => 12],
            'release_offset_months' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $client, float $amount, string $date): string
    {
        $c = \App\Models\Crm\Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => $client],
            ['created_by' => $this->adminUser->id],
        );

        return \App\Models\Crm\Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . random_int(100000, 999999),
            'issuing_company_id' => $this->issuingCompanyId,
            'client_id' => $c->id,
            'member_id' => $this->seller->id,
            'invoice_date' => $date,
            'subtotal' => $amount,
            'total' => $amount,
            'status' => 'sent',
            // Paid in full, so the payment gate lets the run start at once.
            'payment_status' => 'paid',
            'created_by' => $this->adminUser->id,
        ])->uuid;
    }

    public function test_the_ledger_reads_client_by_client_with_each_runs_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));
        $this->sale('Bhavya Steel', 90000, '2026-01-15');
        $this->sale('Surat Textiles', 180000, '2026-02-20');

        // February's slip exists (it carried January's first installment).
        SalarySlip::create([
            'organization_id' => $this->org->id, 'member_id' => $this->seller->id,
            'year' => 2026, 'month' => 2, 'monthly_salary' => 0, 'payable' => 450,
            'net_salary' => 450, 'status' => 'paid', 'paid_on' => '2026-02-28',
        ]);

        // The employee reads their own ledger, no rights needed.
        $data = $this->actingAs($this->sellerUser)->getJson('/api/v1/crm/incentives')
            ->assertOk()->json('data');

        $this->assertCount(2, $data['rows']);
        $rows = collect($data['rows'])->keyBy('client');

        $steel = $rows['Bhavya Steel'];
        $this->assertEquals(5400, $steel['pool']);          // 90,000 × 6%
        $this->assertEquals(450, $steel['installment']);
        $this->assertCount(12, $steel['schedule']);
        // Jan's installment landed on Feb's PAID slip; Feb's payroll month
        // (March) has no slip yet, so it reads as due; April is upcoming.
        $this->assertSame('paid', $steel['schedule'][0]['status']);
        $this->assertSame('2026-02', $steel['schedule'][0]['payroll_month']);
        $this->assertSame('due', $steel['schedule'][1]['status']);
        $this->assertSame('upcoming', $steel['schedule'][2]['status']);
        $this->assertEquals(450, $steel['paid_so_far']);

        $this->assertEquals(900, $rows['Surat Textiles']['installment']);   // 1,80,000 × 6% / 12

        // What next month brings: both runs paying = 1,350.
        $this->assertEquals(1350, $data['next_month']['total']);

        // A stranger's ledger needs authority; one's own does not.
        $this->actingAs($this->sellerUser)
            ->getJson('/api/v1/crm/incentives?member=' . $this->admin->uuid)->assertForbidden();
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/incentives?member=' . $this->seller->uuid)->assertOk();
    }

    public function test_a_one_month_hold_pays_the_next_month_as_an_arrear_automatically(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10'));
        $invoice = $this->sale('Bhavya Steel', 90000, '2026-01-15');
        $calc = new IncentiveCalculator($this->org);

        // An employee cannot rule on incentives.
        $this->actingAs($this->sellerUser)->postJson('/api/v1/crm/incentives/hold', [
            'member_uuid' => $this->seller->uuid, 'invoice_uuid' => $invoice,
            'scope' => 'once', 'month' => '2026-02',
        ])->assertForbidden();

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/incentives/hold', [
            'member_uuid' => $this->seller->uuid, 'invoice_uuid' => $invoice,
            'scope' => 'once', 'month' => '2026-02', 'note' => 'Client disputing February',
        ])->assertCreated();

        // February pays nothing from this run…
        $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse('2026-02-01'))['total']);
        // …March pays its own 450 PLUS February's 450 as an arrear, on its own.
        $march = $calc->compute($this->seller, Carbon::parse('2026-03-01'));
        $this->assertEquals(900, $march['total']);
        $this->assertEquals(450, $march['arrear_total']);
        $this->assertSame('Bhavya Steel', $march['arrears'][0]['client']);
        // April is back to normal.
        $this->assertEquals(450, $calc->compute($this->seller, Carbon::parse('2026-04-01'))['total']);

        // And the slip's incentive line says why March is bigger.
        Carbon::setTestNow(Carbon::parse('2026-04-05'));
        SalaryStructure::create([
            'member_id' => $this->seller->id, 'effective_from' => '2026-01-01',
            'basic' => 31000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false,
            'has_esi' => false, 'has_welfare' => false,
        ]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 4])->assertOk();
        $slip = SalarySlip::where('member_id', $this->seller->id)->where('month', 4)->firstOrFail();
        $label = collect($slip->earnings)->firstWhere('key', 'incentive')['label'];
        $this->assertStringContainsString('arrear incentive release', $label);
        $this->assertEquals(900, $slip->incentive_amount);

        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'incentive.held']);
    }

    public function test_a_standing_hold_pays_everything_withheld_when_released(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10'));
        $invoice = $this->sale('Bhavya Steel', 90000, '2026-01-15');
        $calc = new IncentiveCalculator($this->org);

        $hold = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/incentives/hold', [
            'member_uuid' => $this->seller->uuid, 'invoice_uuid' => $invoice,
            'scope' => 'remaining', 'month' => '2026-02',
        ])->assertCreated()->json('data.uuid');

        // Held months pay nothing, however many pass.
        foreach (['2026-02', '2026-03', '2026-04'] as $m) {
            $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse($m . '-01'))['total'], $m);
        }

        // Released in May: Feb + Mar + Apr pay as one arrear beside May's own.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/incentives/holds/' . $hold . '/release', ['month' => '2026-05'])
            ->assertOk();

        $may = $calc->compute($this->seller, Carbon::parse('2026-05-01'));
        $this->assertEquals(450 * 3, $may['arrear_total']);
        $this->assertEquals(450 * 4, $may['total']);
        $this->assertEquals(3, $may['arrears'][0]['months']);
        // June is just June again.
        $this->assertEquals(450, $calc->compute($this->seller, Carbon::parse('2026-06-01'))['total']);

        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'incentive.released']);
    }

    public function test_a_cancellation_loses_its_months_and_a_regain_resumes_the_future(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10'));
        $invoice = $this->sale('Bhavya Steel', 90000, '2026-01-15');
        $calc = new IncentiveCalculator($this->org);

        $hold = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/incentives/hold', [
            'member_uuid' => $this->seller->uuid, 'invoice_uuid' => $invoice,
            'scope' => 'cancel', 'month' => '2026-02', 'note' => 'Company folded, money returned',
        ])->assertCreated()->json('data.uuid');

        $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse('2026-02-01'))['total']);
        $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse('2026-07-01'))['total']);

        // The client comes back; the Admin regains from August. February to
        // July are gone for good — no arrear.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/incentives/holds/' . $hold . '/release', ['month' => '2026-08'])
            ->assertOk();

        $august = $calc->compute($this->seller, Carbon::parse('2026-08-01'));
        $this->assertEquals(450, $august['total']);
        $this->assertEquals(0, $august['arrear_total']);
        // January's run still ends after 12 months — Dec 2026 is the last.
        $this->assertEquals(450, $calc->compute($this->seller, Carbon::parse('2026-12-01'))['total']);
        $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse('2027-01-01'))['total']);

        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'incentive.cancelled']);
        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'incentive.regained']);

        // The ledger shows the lost months as cancelled.
        $data = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/incentives?member=' . $this->seller->uuid)
            ->assertOk()->json('data');
        $statuses = collect($data['rows'][0]['schedule'])->pluck('status', 'earned_month');
        $this->assertSame('cancelled', $statuses['2026-03']);
        $this->assertSame('upcoming', $statuses['2026-09']);
    }

    public function test_the_spread_follows_the_work_orders_own_validity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10'));

        // A short-term plan: validity 26 Aug -> 26 Nov 2026 = 3 months. Its
        // 90,000 x 6% = 5,400 divides over 3, not the plan's 12.
        $short = $this->sale('Bhavya Steel', 90000, '2026-08-26');
        $shortInvoice = \App\Models\Crm\Invoice::where('uuid', $short)->firstOrFail();
        $shortInvoice->items()->create([
            'plan_name' => 'Growth Short', 'qty' => 1, 'unit_price' => 90000, 'amount' => 90000,
            'validity_from' => '2026-08-26', 'validity_to' => '2026-11-26', 'sort' => 1,
        ]);

        // A one-year plan: 26 Aug 2026 -> 26 Aug 2027 = 12 months.
        $year = $this->sale('Surat Textiles', 90000, '2026-08-26');
        \App\Models\Crm\Invoice::where('uuid', $year)->firstOrFail()->items()->create([
            'plan_name' => 'Growth Plus 12', 'qty' => 1, 'unit_price' => 90000, 'amount' => 90000,
            'validity_from' => '2026-08-26', 'validity_to' => '2027-08-26', 'sort' => 1,
        ]);

        $calc = new IncentiveCalculator($this->org);
        $aug = $calc->compute($this->seller, Carbon::parse('2026-08-01'));
        $byClient = collect($aug['installments'])->keyBy('client');

        // 5,400 / 3 = 1,800 a month; 5,400 / 12 = 450 a month.
        $this->assertEquals(1800, $byClient['Bhavya Steel']['installment']);
        $this->assertSame(3, $byClient['Bhavya Steel']['of']);
        $this->assertEquals(450, $byClient['Surat Textiles']['installment']);
        $this->assertSame(12, $byClient['Surat Textiles']['of']);

        // The short run ends after its 3 months; the year rolls on.
        $nov = collect($calc->compute($this->seller, Carbon::parse('2026-11-01'))['installments'])->keyBy('client');
        $this->assertArrayNotHasKey('Bhavya Steel', $nov->all());
        $this->assertEquals(450, $nov['Surat Textiles']['installment']);

        // The ledger's rows carry each sale's own run length.
        $rows = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/incentives?member=' . $this->seller->uuid)
            ->assertOk()->json('data.rows'))->keyBy('client');
        $this->assertSame(3, $rows['Bhavya Steel']['months']);
        $this->assertCount(3, $rows['Bhavya Steel']['schedule']);
        $this->assertSame(12, $rows['Surat Textiles']['months']);
    }

    public function test_no_incentive_until_the_client_has_paid_in_full(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10'));
        // A January sale the client has NOT paid: the run waits.
        $c = \App\Models\Crm\Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Slow Payer Ltd'],
            ['created_by' => $this->adminUser->id],
        );
        $invoice = \App\Models\Crm\Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice',
            'number' => 'INV-SLOW-1', 'issuing_company_id' => $this->issuingCompanyId,
            'client_id' => $c->id, 'member_id' => $this->seller->id,
            'invoice_date' => '2026-01-15', 'subtotal' => 90000, 'total' => 90000,
            'status' => 'sent', 'created_by' => $this->adminUser->id,
        ]);

        $calc = new IncentiveCalculator($this->org);
        foreach (['2026-01', '2026-02', '2026-03'] as $m) {
            $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse($m . '-01'))['total'], $m);
        }

        // The ledger says why, cell by cell.
        $row = collect($this->actingAs($this->sellerUser)->getJson('/api/v1/crm/incentives')
            ->assertOk()->json('data.rows'))->firstWhere('invoice_no', 'INV-SLOW-1');
        $this->assertTrue($row['awaiting_payment']);
        $this->assertSame('awaiting_payment', $row['schedule'][0]['status']);

        // Part payment is not payment: still nothing.
        \App\Models\Crm\InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 40000, 'received_at' => '2026-02-10',
        ]);
        $invoice->refreshPaymentStatus();
        $this->assertSame('partial', $invoice->fresh()->payment_status);
        $this->assertEquals(0, (new IncentiveCalculator($this->org))->compute($this->seller, Carbon::parse('2026-02-01'))['total']);

        // Full payment lands in March: the three waiting months release
        // themselves — Jan + Feb as an arrear beside March's own — with no
        // button pressed anywhere.
        \App\Models\Crm\InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 50000, 'received_at' => '2026-03-12',
        ]);
        $invoice->refreshPaymentStatus();
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        $march = (new IncentiveCalculator($this->org))->compute($this->seller, Carbon::parse('2026-03-01'));
        $this->assertEquals(450 * 3, $march['total']);          // Jan + Feb arrear + Mar own
        $this->assertEquals(900, $march['arrear_total']);
        $this->assertSame('payment_received', $march['arrears'][0]['reason']);

        // April onward: just the normal installment.
        $this->assertEquals(450, (new IncentiveCalculator($this->org))->compute($this->seller, Carbon::parse('2026-04-01'))['total']);

        // And the ledger's waiting cells now read as arrears paying at March.
        $after = collect($this->actingAs($this->sellerUser)->getJson('/api/v1/crm/incentives')
            ->assertOk()->json('data.rows'))->firstWhere('invoice_no', 'INV-SLOW-1');
        $this->assertFalse($after['awaiting_payment']);
        $this->assertSame('arrear', $after['schedule'][0]['status']);
        $this->assertSame('2026-04', $after['schedule'][0]['pays_at']);
    }

    public function test_one_employees_gate_can_be_overridden_from_their_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10'));
        // Policy says wait — but this one employee is released.
        $c = \App\Models\Crm\Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Slow Payer Ltd'],
            ['created_by' => $this->adminUser->id],
        );
        \App\Models\Crm\Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice',
            'number' => 'INV-SLOW-2', 'issuing_company_id' => $this->issuingCompanyId,
            'client_id' => $c->id, 'member_id' => $this->seller->id,
            'invoice_date' => '2026-01-15', 'subtotal' => 90000, 'total' => 90000,
            'status' => 'sent', 'created_by' => $this->adminUser->id,
        ]);

        // Unpaid + policy on: nothing. An employee cannot free themselves.
        $this->assertEquals(0, (new IncentiveCalculator($this->org))
            ->compute($this->seller, Carbon::parse('2026-01-01'))['total']);
        $this->actingAs($this->sellerUser)
            ->postJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/payment-gate', ['mode' => 'release'])
            ->assertForbidden();

        // The Admin releases this one person; the unpaid sale pays at once.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/payment-gate', ['mode' => 'release'])
            ->assertOk();
        $this->assertEquals(450, (new IncentiveCalculator($this->org))
            ->compute($this->seller->fresh(), Carbon::parse('2026-01-01'))['total']);

        // Back to the policy, and the wait returns. Both moves on the trail.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/payment-gate', ['mode' => 'policy'])
            ->assertOk();
        $this->assertEquals(0, (new IncentiveCalculator($this->org))
            ->compute($this->seller->fresh(), Carbon::parse('2026-01-01'))['total']);
        $this->assertSame(2, \App\Models\Crm\ActivityLog::where('action', 'incentive.payment_gate_set')->count());
    }

    public function test_the_payment_gate_can_be_switched_off_in_hr_policy(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10'));
        $settings = $this->org->settings ?? [];
        $settings['hr'] = ['incentive_needs_full_payment' => false];
        $this->org->update(['settings' => $settings]);

        $c = \App\Models\Crm\Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Trusted Ltd'],
            ['created_by' => $this->adminUser->id],
        );
        \App\Models\Crm\Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice',
            'number' => 'INV-TRUST-1', 'issuing_company_id' => $this->issuingCompanyId,
            'client_id' => $c->id, 'member_id' => $this->seller->id,
            'invoice_date' => '2026-01-15', 'subtotal' => 90000, 'total' => 90000,
            'status' => 'sent', 'created_by' => $this->adminUser->id,
        ]);

        // Gate off: the unpaid sale earns from its own month, as before.
        $this->assertEquals(450, (new IncentiveCalculator($this->org->fresh()))
            ->compute($this->seller, Carbon::parse('2026-01-01'))['total']);
    }

    public function test_a_percent_change_applies_from_the_next_invoice_onwards(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));
        // January sale under the standing 6% plan.
        $this->sale('Bhavya Steel', 90000, '2026-01-15');

        // From 1 March the plan moves to 8% — a NEW dated row, never an edit.
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2026-03-01',
            'kind' => 'spread',
            'config' => ['percent' => 8, 'spread_months' => 12],
            'release_offset_months' => 1,
        ]);
        // March sale under the new terms.
        $this->sale('Surat Textiles', 90000, '2026-03-05');

        $calc = new IncentiveCalculator($this->org);
        $march = $calc->compute($this->seller, Carbon::parse('2026-03-01'));

        // The January run finishes on its OLD 6% (450); the March run pays
        // the new 8% (600). Neither bleeds into the other.
        $byClient = collect($march['installments'])->keyBy('client');
        $this->assertEquals(450, $byClient['Bhavya Steel']['installment']);
        $this->assertEquals(600, $byClient['Surat Textiles']['installment']);
        $this->assertEquals(1050, $march['total']);

        // And in December the January run is still 450 — old terms to the end.
        $dec = collect($calc->compute($this->seller, Carbon::parse('2026-12-01'))['installments'])->keyBy('client');
        $this->assertEquals(450, $dec['Bhavya Steel']['installment']);

        // The ledger says the same, each row under its own vintage.
        $rows = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/incentives?member=' . $this->seller->uuid)
            ->assertOk()->json('data.rows'))->keyBy('client');
        $this->assertEquals(6, $rows['Bhavya Steel']['percent']);
        $this->assertEquals(8, $rows['Surat Textiles']['percent']);
    }

    public function test_a_returned_sale_takes_its_paid_incentive_back_as_a_minus(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-05'));
        $invoice = $this->sale('Bhavya Steel', 90000, '2026-01-15');
        SalaryStructure::create([
            'member_id' => $this->seller->id, 'effective_from' => '2026-01-01',
            'basic' => 31000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false,
            'has_esi' => false, 'has_welfare' => false,
        ]);

        // Feb and Mar payrolls ran: installments 1 and 2 (Jan, Feb anchors)
        // went out — 900 paid in all.
        foreach ([2, 3] as $m) {
            $this->actingAs($this->adminUser)
                ->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => $m])->assertOk();
        }

        // The client returns the money in full. Cancel WITH recovery.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/incentives/hold', [
            'member_uuid' => $this->seller->uuid, 'invoice_uuid' => $invoice,
            'scope' => 'cancel', 'month' => '2026-03', 'recover' => true,
            'note' => 'Full refund — company closed',
        ])->assertCreated();

        $calc = new IncentiveCalculator($this->org);
        $march = $calc->compute($this->seller, Carbon::parse('2026-03-01'));

        // March pays nothing new and claws the two paid installments back.
        $this->assertEquals(900, $march['recovery_total']);
        $this->assertEquals(-900, $march['total']);
        $this->assertEquals(-900, $march['arrears'][0]['amount']);
        $this->assertSame(2, $march['arrears'][0]['months']);

        // On April's slip (offset 1) the incentive line is the minus, named.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 4])->assertOk();
        $slip = SalarySlip::where('member_id', $this->seller->id)->where('month', 4)->firstOrFail();
        $this->assertEquals(-900, (float) $slip->incentive_amount);
        $this->assertEquals(31000 - 900, (float) $slip->net_salary);
        $label = collect($slip->earnings)->firstWhere('key', 'incentive')['label'];
        $this->assertStringContainsString('incentive recovery', $label);
        $this->assertStringContainsString('sale returned', $label);

        // The recovery fires once: April's anchor holds nothing.
        $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse('2026-04-01'))['total']);
    }

    public function test_the_months_between_view_reads_a_span_at_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-10'));
        $this->sale('Bhavya Steel', 90000, '2026-01-15');
        $this->sale('Surat Textiles', 90000, '2026-02-10');

        $months = $this->actingAs($this->sellerUser)
            ->getJson('/api/v1/crm/incentives?month_from=2026-01&month_to=2026-04')
            ->assertOk()->json('data.months');

        $this->assertCount(4, $months);
        $this->assertEquals(450, $months[0]['total']);      // Jan: one run
        $this->assertEquals(900, $months[1]['total']);      // Feb: both
        $this->assertEquals(900, $months[2]['total']);
        $this->assertSame('2026-02', $months[0]['payroll_month']);
        // No slips generated: payroll months already arrived read as due;
        // April's earnings ride May's payroll, which is still to come.
        $this->assertSame('due', $months[0]['status']);
        $this->assertSame('due', $months[2]['status']);
        $this->assertSame('upcoming', $months[3]['status']);
    }

    public function test_a_team_head_can_run_combined_or_separate_team_incentive(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10'));
        // A reportee under the admin, so the admin is a Team Head.
        $this->sale('Bhavya Steel', 100000, '2026-01-15');           // seller's own sale

        $adminSale = \App\Models\Crm\Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Head Client'],
            ['created_by' => $this->adminUser->id],
        );
        \App\Models\Crm\Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice',
            'number' => 'INV-HEAD-1', 'issuing_company_id' => $this->issuingCompanyId,
            'client_id' => $adminSale->id, 'member_id' => $this->admin->id,
            'invoice_date' => '2026-01-20', 'subtotal' => 200000, 'total' => 200000,
            'status' => 'sent', 'payment_status' => 'paid', 'created_by' => $this->adminUser->id,
        ]);

        $calc = new IncentiveCalculator($this->org);

        // Scenario B (separate): own structure on own sale + flat team %.
        IncentivePlan::create([
            'member_id' => $this->admin->id, 'effective_from' => '2025-01-01',
            'kind' => 'flat_percent',
            'config' => ['percent' => 5, 'team_percent' => 2, 'team_mode' => 'separate'],
        ]);
        $separate = $calc->compute($this->admin, Carbon::parse('2026-01-01'));
        $this->assertEquals(10000, $separate['self_incentive']);    // 5% of 2,00,000
        $this->assertEquals(2000, $separate['team_incentive']);     // 2% of the seller's 1,00,000
        $this->assertEquals(12000, $separate['total']);

        // Scenario A (combined): self + team as ONE figure through the
        // Head's own structure.
        IncentivePlan::create([
            'member_id' => $this->admin->id, 'effective_from' => '2026-01-01',
            'kind' => 'flat_percent',
            'config' => ['percent' => 5, 'team_mode' => 'combined'],
        ]);
        // A fresh calculator: plans are cached per instance.
        $combined = (new IncentiveCalculator($this->org))->compute($this->admin, Carbon::parse('2026-01-01'));
        $this->assertEquals(15000, $combined['self_incentive']);    // 5% of 3,00,000
        $this->assertEquals(0, $combined['team_incentive']);
        $this->assertEquals(15000, $combined['total']);
    }

    public function test_a_team_workspace_grant_drives_the_team_incentive(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-10'));

        // A leader with no chain over the seller — only the Admin's tick.
        $leadUser = User::factory()->create(['email' => 'lead@acme.test']);
        $leadUser->settings()->create([]);
        $leadUser->profile()->create(['timezone' => 'UTC']);
        $lead = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $leadUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'status' => 'active', 'joined_at' => '2024-01-01',
        ]);
        IncentivePlan::create([
            'member_id' => $lead->id, 'effective_from' => '2025-01-01', 'kind' => 'flat_percent',
            'config' => ['percent' => 5, 'team_percent' => 3, 'team_mode' => 'separate'],
        ]);

        $this->sale('Bhavya Steel', 100000, '2026-01-15');   // the seller's sale

        // Without the grant the leader has no team, so no team incentive.
        $none = (new IncentiveCalculator($this->org))->compute($lead, Carbon::parse('2026-01-01'));
        $this->assertEquals(0, $none['team_incentive']);

        // Ticked into the Team Workspace, the seller's sale starts paying.
        $lead->team()->attach($this->seller->id);
        $with = (new IncentiveCalculator($this->org))->compute($lead, Carbon::parse('2026-01-01'));
        $this->assertEquals(0, $with['self_incentive']);
        $this->assertEquals(3000, $with['team_incentive']);  // 3% of 1,00,000
        $this->assertEquals(3000, $with['total']);
    }

    public function test_a_spread_plans_team_percent_spreads_the_teams_sales_too(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10'));

        // A leader on a spread plan carrying a 3% team percent, with the
        // seller ticked into their Team Workspace.
        $leadUser = User::factory()->create(['email' => 'lead2@acme.test']);
        $leadUser->settings()->create([]);
        $leadUser->profile()->create(['timezone' => 'UTC']);
        $lead = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $leadUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'status' => 'active', 'joined_at' => '2024-01-01',
        ]);
        IncentivePlan::create([
            'member_id' => $lead->id, 'effective_from' => '2025-01-01', 'kind' => 'spread',
            'config' => ['percent' => 6, 'spread_months' => 12, 'team_percent' => 3, 'team_mode' => 'separate'],
            'release_offset_months' => 1,
        ]);
        $lead->team()->attach($this->seller->id);

        $this->sale('Bhavya Steel', 100000, '2026-06-15');   // the seller's sale

        // The seller's 1,00,000 pays the LEADER 3% / 12 = 250 a month, the
        // same spread shape as their own sales — labelled as a team line.
        $calc = new IncentiveCalculator($this->org);
        $june = $calc->compute($lead, Carbon::parse('2026-06-01'));
        $this->assertEquals(0, $june['self_incentive']);
        $this->assertEquals(250, $june['team_incentive']);
        $this->assertEquals(250, $june['total']);
        $line = collect($june['installments'])->firstWhere('team', true);
        $this->assertSame('Bhavya Steel', $line['client']);
        $this->assertSame($this->sellerUser->name, $line['seller']);
        $this->assertSame(12, $line['of']);

        // And it keeps paying in later months of the run.
        $this->assertEquals(250, $calc->compute($lead, Carbon::parse('2026-08-01'))['team_incentive']);

        // The leader's ledger shows the run as its own Team row.
        $rows = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/incentives?member=' . $lead->uuid)
            ->assertOk()->json('data.rows');
        $teamRow = collect($rows)->firstWhere('team', true);
        $this->assertNotNull($teamRow);
        $this->assertSame('Bhavya Steel', $teamRow['client']);
        $this->assertSame($this->sellerUser->name, $teamRow['seller']);
        $this->assertEquals(3, $teamRow['percent']);
        $this->assertSame(12, $teamRow['months']);
        $this->assertEquals(250, $teamRow['installment']);

        // The seller's own ledger is untouched: their 6% run, no team rows.
        $own = $this->actingAs($this->sellerUser)->getJson('/api/v1/crm/incentives')
            ->assertOk()->json('data.rows');
        $this->assertNull(collect($own)->firstWhere('team', true));
        $this->assertEquals(6, $own[0]['percent']);
    }

    public function test_withdrawn_team_access_keeps_earned_runs_but_takes_no_new_sales(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10'));

        $leadUser = User::factory()->create(['email' => 'lead3@acme.test']);
        $leadUser->settings()->create([]);
        $leadUser->profile()->create(['timezone' => 'UTC']);
        $lead = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $leadUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'status' => 'active', 'joined_at' => '2024-01-01',
        ]);
        IncentivePlan::create([
            'member_id' => $lead->id, 'effective_from' => '2025-01-01', 'kind' => 'spread',
            'config' => ['percent' => 6, 'spread_months' => 12, 'team_percent' => 3, 'team_mode' => 'separate'],
            'release_offset_months' => 1,
        ]);
        $lead->team()->attach($this->seller->id);

        // A sale made while the access stood: 3% / 12 = 250 a month.
        $this->sale('Bhavya Steel', 100000, '2026-06-15');

        // The Admin withdraws the access in September...
        \Illuminate\Support\Facades\DB::table('crm_team_access')
            ->where('leader_id', $lead->id)->where('member_id', $this->seller->id)
            ->update(['revoked_at' => '2026-09-05 10:00:00']);

        // ...and the seller sells again AFTER the withdrawal.
        $this->sale('Surat Textiles', 50000, '2026-09-20');

        // The June run is already the leader's RIGHT: September still pays
        // its 250, and the run finishes its scheduled 12 months.
        $calc = new IncentiveCalculator($this->org);
        $sep = $calc->compute($lead, Carbon::parse('2026-09-01'));
        $this->assertEquals(250, $sep['team_incentive']);
        $lines = collect($sep['installments'])->where('team', true);
        $this->assertCount(1, $lines);
        $this->assertSame('Bhavya Steel', $lines->first()['client']);
        $this->assertEquals(250, (new IncentiveCalculator($this->org))
            ->compute($lead, Carbon::parse('2027-05-01'))['team_incentive']);   // 12th month
        $this->assertEquals(0, (new IncentiveCalculator($this->org))
            ->compute($lead, Carbon::parse('2027-06-01'))['team_incentive']);   // run over

        // The ledger keeps the run with the withdrawal remark; the new sale
        // never joins.
        $rows = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/incentives?member=' . $lead->uuid)
            ->assertOk()->json('data.rows'))->where('team', true);
        $this->assertCount(1, $rows);
        $this->assertSame('Bhavya Steel', $rows->first()['client']);
        $this->assertSame('2026-09', $rows->first()['withdrawn_month']);

        // The leader's window is closed: the seller is no longer visible.
        $this->assertNotContains($this->seller->id, $lead->teamMemberIds());
    }

    public function test_the_emergency_brake_rules_every_run_at_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-10'));
        $this->sale('Bhavya Steel', 90000, '2026-01-15');
        $this->sale('Surat Textiles', 180000, '2026-02-20');

        // An employee cannot pull the brake.
        $this->actingAs($this->sellerUser)->postJson('/api/v1/crm/incentives/hold-all', [
            'member_uuid' => $this->seller->uuid, 'scope' => 'remaining', 'month' => '2026-03',
        ])->assertForbidden();

        // The Admin rules once over both runs.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/incentives/hold-all', [
            'member_uuid' => $this->seller->uuid, 'scope' => 'remaining',
            'month' => '2026-03', 'note' => 'emergency stop',
        ])->assertCreated();

        $holds = \App\Models\Crm\IncentiveHold::where('member_id', $this->seller->id)->get();
        $this->assertCount(2, $holds);
        $this->assertTrue($holds->every(fn ($h) => $h->kind === 'hold' && $h->from_month === '2026-03'));
        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'incentive.bulk_held']);

        // March pays nothing while everything is held.
        $mar = (new IncentiveCalculator($this->org))->compute($this->seller, Carbon::parse('2026-03-01'));
        $this->assertEquals(0, $mar['total']);

        // A second pull finds every run already ruled.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/incentives/hold-all', [
            'member_uuid' => $this->seller->uuid, 'scope' => 'cancel', 'month' => '2026-03',
        ])->assertStatus(422);
    }

    public function test_the_payslip_downloads_as_a_pdf_and_slips_read_between_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-05'));
        SalaryStructure::create([
            'member_id' => $this->seller->id, 'effective_from' => '2026-01-01',
            'basic' => 31000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false,
            'has_esi' => false, 'has_welfare' => false,
        ]);
        foreach ([2, 3] as $m) {
            $this->actingAs($this->adminUser)
                ->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => $m])->assertOk();
        }
        $slip = SalarySlip::where('member_id', $this->seller->id)->where('month', 2)->firstOrFail();

        // Their own payslip downloads; a colleague's is refused.
        $this->actingAs($this->sellerUser)->get('/api/v1/crm/salary/' . $slip->uuid . '/pdf')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $other = SalarySlip::where('member_id', $this->admin->id)->first();
        if ($other) {
            $this->actingAs($this->sellerUser)->get('/api/v1/crm/salary/' . $other->uuid . '/pdf')->assertForbidden();
        }

        // Between dates: both months in one read, totals across the span.
        $range = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/salary?month_from=2026-02&month_to=2026-03')
            ->assertOk()->json();
        $mine = collect($range['data'])->where('member.uuid', $this->seller->uuid);
        $this->assertCount(2, $mine);
        $this->assertEquals(62000, $mine->sum(fn ($s) => (float) $s['net_salary']));
    }
}
