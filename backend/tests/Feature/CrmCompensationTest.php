<?php

namespace Tests\Feature;

use App\Models\Crm\IncentivePlan;
use App\Models\Crm\Loan;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalarySlip;
use App\Models\Crm\SalaryStructure;
use App\Models\User;
use App\Services\Crm\IncentiveCalculator;
use App\Services\Crm\SalaryCalculator;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Salary and incentive, tested against the company's own sheet.
 *
 * The rows in the sample sheet are the ground truth here: a 98,200 basic
 * on PF nets 96,366; an 18,500 gross on PF and ESI nets 24,282 with its
 * fixed incentive; a 19L sale on the 0-10-15-20L slab pays 47,500. If the
 * calculator disagrees with the sheet, the calculator is wrong.
 */
class CrmCompensationTest extends TestCase
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

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->sellerUser = $this->makeUser('seller@acme.test');

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function calculator(): SalaryCalculator
    {
        return new SalaryCalculator($this->org, new IncentiveCalculator($this->org));
    }

    /** @param array<string, mixed> $overrides */
    private function structure(Member $member, array $overrides = []): SalaryStructure
    {
        return SalaryStructure::create($overrides + [
            'member_id' => $member->id,
            'effective_from' => '2026-01-01',
            'basic' => 15000,
            'hra' => 7500,
            'components' => [],
            'has_pf' => true,
            'has_edli' => true,
            'has_esi' => false,
            'has_welfare' => true,
        ]);
    }

    private function sale(Member $member, float $amount, string $date): void
    {
        $client = \App\Models\Crm\Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Bhavya Steel'],
            ['created_by' => $this->adminUser->id],
        );
        \App\Models\Crm\Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . random_int(1000, 999999),
            'issuing_company_id' => $this->issuingCompanyId,
            'client_id' => $client->id,
            'member_id' => $member->id,
            'invoice_date' => $date,
            'subtotal' => $amount,
            'total' => $amount,
            'status' => 'sent',
            // Paid in full, so the payment gate lets the run start at once.
            'payment_status' => 'paid',
            'created_by' => $this->adminUser->id,
        ]);
    }

    // ---- The sheet's own rows ------------------------------------------------

    public function test_the_sheets_first_row_a_98200_basic_on_pf_nets_96366(): void
    {
        // Prashant: basic 98,200, PF, no ESI. The sheet says payable 100,218
        // (basic + 1,800 employer PF + 150 EDLI + 68 welfare), deductions
        // 3,852 (3,750 PF both sides with EDLI + 102 welfare both sides),
        // net 96,366.
        $this->structure($this->seller, ['basic' => 98200, 'hra' => 0]);

        $calc = $this->calculator()->compute($this->seller, Carbon::parse('2026-01-01'), null);

        $this->assertEquals(100218, $calc['gross_payable']);
        $this->assertEquals(3852, $calc['total_deductions']);
        $this->assertEquals(96366, $calc['net_salary']);

        $lines = collect($calc['earnings'])->pluck('amount', 'key');
        $this->assertEquals(1800, $lines['employer_pf']);   // 12% of the 15,000 cap
        $this->assertEquals(150, $lines['edli']);           // 1% of the cap
        $this->assertEquals(68, $lines['welfare_employer']);
    }

    public function test_the_sheets_esi_row_an_18500_gross_with_esi_and_incentive(): void
    {
        // Praveen: basic 11,500 + HRA 7,000, PF and ESI, fixed incentive
        // 7,335 riding as a component. Sheet: payable 28,000 with the
        // employer money in, deductions 3,718, net 24,282.
        $this->structure($this->seller, [
            'basic' => 11500,
            'hra' => 7000,
            'components' => ['fix_allowance' => 7335],
            'has_esi' => true,
        ]);

        $calc = $this->calculator()->compute($this->seller, Carbon::parse('2026-01-01'), null);

        $lines = collect($calc['earnings'])->pluck('amount', 'key');
        $this->assertEquals(1380, $lines['employer_pf']);   // 12% of 11,500
        $this->assertEquals(115, $lines['edli']);
        // ESI employer: 3.25% of 18,500 gross = 601.25, rounded UP.
        $this->assertEquals(602, $lines['employer_esi']);

        $taken = collect($calc['deduction_lines'])->pluck('amount', 'key');
        $this->assertEquals(2760, $taken['pf']);            // 1380 + 1380
        $this->assertEquals(115, $taken['edli']);           // its own line now
        $this->assertEquals(741, $taken['esi']);            // 602 + ceil(138.75)
        $this->assertEquals(102, $taken['welfare']);        // 68 + 34

        // 18,500 + 7,335 + 1,380 PF + 115 EDLI + 68 welfare + 602 ESI.
        $this->assertEquals(28000, $calc['gross_payable']);
        $this->assertEquals(24282, $calc['net_salary']);
    }

    public function test_the_admins_worked_example_gross_16000_nets_14408(): void
    {
        // The example given in words: gross 16,000 = basic 12,000 + HRA
        // 3,000 + others 1,000, every facility taken. CTC 18,144; total
        // deduction 3,736; net in hand 14,408.
        $this->structure($this->seller, [
            'basic' => 12000, 'hra' => 3000,
            'components' => ['other' => 1000],
            'has_pf' => true, 'has_edli' => true, 'has_esi' => true, 'has_welfare' => true,
        ]);

        $calc = $this->calculator()->compute($this->seller, Carbon::parse('2026-01-01'), null);

        $lines = collect($calc['earnings'])->pluck('amount', 'key');
        $this->assertEquals(1440, $lines['employer_pf']);      // 12,000 x 12%
        $this->assertEquals(520, $lines['employer_esi']);      // 16,000 x 3.25%
        $this->assertEquals(120, $lines['edli']);              // 12,000 x 1%
        $this->assertEquals(64, $lines['welfare_employer']);   // 32 x 2

        $taken = collect($calc['deduction_lines'])->pluck('amount', 'key');
        $this->assertEquals(2880, $taken['pf']);               // 1,440 + 1,440
        $this->assertEquals(640, $taken['esi']);               // 520 + 120
        $this->assertEquals(120, $taken['edli']);
        $this->assertEquals(96, $taken['welfare']);            // 64 + 32

        $this->assertEquals(18144, $calc['gross_payable']);    // the CTC
        $this->assertEquals(3736, $calc['total_deductions']);
        $this->assertEquals(14408, $calc['net_salary']);       // in hand
    }

    public function test_every_facility_is_optional_and_none_means_gross_in_hand(): void
    {
        // Some staff want only the discussed in-hand figure: no PF, no ESI,
        // no EDLI, no welfare — the gross IS the net.
        $this->structure($this->seller, [
            'basic' => 12000, 'hra' => 3000, 'components' => ['other' => 1000],
            'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
        ]);

        $calc = $this->calculator()->compute($this->seller, Carbon::parse('2026-01-01'), null);

        $this->assertEquals(16000, $calc['gross_payable']);
        $this->assertEquals(0, $calc['total_deductions']);
        $this->assertEquals(16000, $calc['net_salary']);

        // And EDLI can be held without PF — each switch stands alone.
        SalaryStructure::where('member_id', $this->seller->id)->delete();
        $this->structure($this->seller, [
            'basic' => 12000, 'hra' => 4000,
            'has_pf' => false, 'has_edli' => true, 'has_esi' => false, 'has_welfare' => false,
        ]);
        $again = $this->calculator()->compute($this->seller, Carbon::parse('2026-01-01'), null);
        $this->assertEquals(120, collect($again['deduction_lines'])->pluck('amount', 'key')['edli']);
        $this->assertEquals(16000, $again['net_salary']);
    }

    public function test_the_statutory_rates_move_with_the_hr_policy(): void
    {
        // The law changes; the Admin edits the rate on the HR Policy and
        // every later computation follows.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/hr-policy', [
            'work_start' => '10:00', 'work_end' => '19:00', 'grace_minutes' => 15,
            'half_day_after_minutes' => 180, 'half_day_hours' => 4.5, 'full_day_hours' => 8.0,
            'week_off_days' => [0], 'probation_days' => 180, 'monthly_leave_credit' => 1.0,
            'encash_unused_leave' => true, 'financial_year_start_month' => 4,
            // The sides move separately now: employer to 10%, employee held
            // at 12% — exactly the future the split exists for.
            'pf_employer_rate' => 10, 'pf_employee_rate' => 12,
            'pf_wage_cap' => 20000, 'esi_default' => true,
        ])->assertOk();

        // The PUT touched the database row; this instance must reload it.
        $this->org->refresh();
        $this->structure($this->seller, ['basic' => 18000, 'hra' => 0, 'has_edli' => false, 'has_welfare' => false]);
        $calc = $this->calculator()->compute($this->seller, Carbon::parse('2026-01-01'), null);

        // Employer 10% of 18,000, employee 12% — under the raised cap.
        $this->assertEquals(1800, collect($calc['earnings'])->pluck('amount', 'key')['employer_pf']);
        $this->assertEquals(1800 + 2160, collect($calc['deduction_lines'])->pluck('amount', 'key')['pf']);

        // And the standard prefill follows the policy too.
        $card = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation')
            ->assertOk()->json('data');
        $this->assertTrue($card['scheme_defaults']['has_esi']);
        $this->assertEquals(10, $card['statutory']['pf_employer_rate']);
        $this->assertEquals(12, $card['statutory']['pf_employee_rate']);
    }

    public function test_attendance_prorates_the_components_but_not_the_caps_wrongly(): void
    {
        $this->structure($this->seller, ['basic' => 20000, 'hra' => 10000]);

        // Half the month worked: 15.5 of 31 days.
        $attendance = [
            'has_attendance' => true, 'payable_days' => 15.5, 'lop_days' => 15.5,
        ];
        $calc = $this->calculator()->compute($this->seller, Carbon::parse('2026-01-01'), $attendance);

        $lines = collect($calc['earnings'])->pluck('amount', 'key');
        $this->assertEquals(10000, $lines['basic']);
        $this->assertEquals(5000, $lines['hra']);
        // PF follows the PRORATED basic (10,000 is under the cap): 1,200.
        $this->assertEquals(1200, $lines['employer_pf']);
        $this->assertEquals(15.5, $calc['payable_days']);
    }

    // ---- The incentive plans, against the sheet ------------------------------

    public function test_the_kritika_slab_a_19l_sale_pays_47500(): void
    {
        // '0-10L-15L-20L --> 1-2-2.5-3': the band the TOTAL lands in prices
        // the whole sale. 19L falls in the 15–20L band → 2.5% → 47,500.
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2025-01-01',
            'kind' => 'slab',
            'config' => ['slab_mode' => 'whole', 'slabs' => [
                ['upto' => 1000000, 'percent' => 1],
                ['upto' => 1500000, 'percent' => 2],
                ['upto' => 2000000, 'percent' => 2.5],
                ['upto' => null, 'percent' => 3],
            ]],
        ]);
        $this->sale($this->seller, 1900000, '2026-01-15');

        $result = (new IncentiveCalculator($this->org))->compute($this->seller, Carbon::parse('2026-01-01'));

        $this->assertEquals(1900000, $result['self']['effective']);
        $this->assertEquals(47500, $result['total']);
    }

    public function test_the_satish_shape_a_percent_minus_base_with_a_team_cut(): void
    {
        // 25% of own sale minus the 37,018 base, plus 3% of the team's.
        IncentivePlan::create([
            'member_id' => $this->admin->id,
            'effective_from' => '2025-01-01',
            'kind' => 'percent_minus_base',
            'config' => ['percent' => 25, 'base_amount' => 37018, 'team_percent' => 3],
        ]);
        $this->sale($this->admin, 200000, '2026-01-10');
        $this->sale($this->seller, 300000, '2026-01-12');   // reports to admin

        $result = (new IncentiveCalculator($this->org))->compute($this->admin, Carbon::parse('2026-01-01'));

        $this->assertEquals(12982, $result['self_incentive']);   // 50,000 − 37,018
        $this->assertEquals(9000, $result['team_incentive']);    // 3% of 3L
        $this->assertEquals(21982, $result['total']);

        // The base can never push it below zero.
        \App\Models\Crm\Invoice::query()->delete();
        $this->sale($this->admin, 100000, '2026-01-10');
        $again = (new IncentiveCalculator($this->org))->compute($this->admin, Carbon::parse('2026-01-01'));
        $this->assertEquals(0, $again['self_incentive']);
    }

    public function test_the_effective_sale_nets_off_commission_and_gateway_charges(): void
    {
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2025-01-01',
            'kind' => 'flat_percent',
            'config' => ['percent' => 10],
        ]);
        $this->sale($this->seller, 100000, '2026-01-15');

        $invoice = \App\Models\Crm\Invoice::firstOrFail();
        // 5,000 commission to the client and 1,000 the gateway kept — both
        // already booked against the invoice by their own features.
        \App\Models\Crm\Expense::create([
            'organization_id' => $this->org->id, 'expense_date' => '2026-01-16',
            'invoice_id' => $invoice->id, 'vendor_name' => 'Bhavya Steel',
            'category' => 'Client Commission', 'base_amount' => 5000, 'total_amount' => 5000,
        ]);
        \App\Models\Crm\Expense::create([
            'organization_id' => $this->org->id, 'expense_date' => '2026-01-16',
            'invoice_id' => $invoice->id, 'vendor_name' => 'Cashfree fee',
            'category' => 'Payment Gateway Charges', 'base_amount' => 1000, 'total_amount' => 1000,
        ]);

        $result = (new IncentiveCalculator($this->org))->compute($this->seller, Carbon::parse('2026-01-01'));

        $this->assertEquals(94000, $result['self']['effective']);
        $this->assertEquals(9400, $result['total']);
    }

    // ---- The spread plan -----------------------------------------------------

    public function test_the_spread_plan_pays_the_owners_example_441_a_month(): void
    {
        // The worked example: a 90,000 sale where THIS client deducted 2%
        // TDS — so the invoice's own total is the net 88,200 and carries
        // the 1,800 on its TDS line. 88,200 at 6% = 5,292, paid NOT in one
        // go but at 5,292 / 12 = 441 a month. No plan knob: the invoice is
        // the truth, and a client who deducts 10% or nothing just yields a
        // different total.
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2025-01-01',
            'kind' => 'spread',
            'config' => ['percent' => 6, 'spread_months' => 12],
            'release_offset_months' => 1,
        ]);
        $this->sale($this->seller, 88200, '2026-01-15');
        \App\Models\Crm\Invoice::latest('id')->first()->forceFill(['tds' => 1800])->save();

        $calc = new IncentiveCalculator($this->org);

        // Every month for a year pays the same installment…
        foreach (['2026-01', '2026-06', '2026-12'] as $month) {
            $result = $calc->compute($this->seller, Carbon::parse($month . '-01'));
            $this->assertEquals(441, $result['total'], $month);
        }
        // …and the breakdown says which installment of the run it is.
        $june = $calc->compute($this->seller, Carbon::parse('2026-06-01'));
        $this->assertSame('2026-01', $june['installments'][0]['sale_month']);
        $this->assertSame(6, $june['installments'][0]['number']);
        $this->assertSame(12, $june['installments'][0]['of']);
        $this->assertEquals(5292, $june['installments'][0]['pool']);
        $this->assertEquals(88200, $june['installments'][0]['effective_sale']);

        // Month 13: the run is over, nothing more is owed.
        $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse('2027-01-01'))['total']);

        // A second sale from a client who deducted NOTHING: its own total
        // stands whole, 90,000 x 6% / 12 = 450 beside the 441.
        $this->sale($this->seller, 90000, '2026-03-10');
        $this->assertEquals(891, $calc->compute($this->seller, Carbon::parse('2026-06-01'))['total']);

        // On the slip: January's first installment rides February's payroll.
        Carbon::setTestNow(Carbon::parse('2026-02-05'));
        $this->structure($this->seller, [
            'basic' => 31000, 'hra' => 0,
            'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
        ]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 2])->assertOk();
        $slip = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->assertEquals(441, (float) $slip->incentive_amount);
        $this->assertEquals(31441, (float) $slip->net_salary);
    }

    public function test_a_cancelled_sale_stops_its_remaining_installments(): void
    {
        // The whole reason this plan exists: the client folds, the money
        // goes back — and because nothing was paid up front, the incentive
        // simply stops. No clawback, no loss.
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2025-01-01',
            'kind' => 'spread',
            'config' => ['percent' => 6, 'spread_months' => 12],
        ]);
        $this->sale($this->seller, 88200, '2026-01-15');

        $calc = new IncentiveCalculator($this->org);
        $this->assertEquals(441, $calc->compute($this->seller, Carbon::parse('2026-03-01'))['total']);

        // Only 2 of the 12 installments ever went out (882 in all); the
        // other 4,410 of the 5,292 pool never leaves the company.
        \App\Models\Crm\Invoice::query()->update(['status' => 'cancelled']);
        $this->assertEquals(0, $calc->compute($this->seller, Carbon::parse('2026-03-01'))['total']);
    }

    public function test_a_client_who_deducted_no_tds_pays_on_the_whole_amount(): void
    {
        // No TDS on the invoice, nothing netted: 90,000 x 6% / 12 = 450.
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2025-01-01',
            'kind' => 'spread',
            'config' => ['percent' => 6, 'spread_months' => 12],
        ]);
        $this->sale($this->seller, 90000, '2026-01-15');

        $result = (new IncentiveCalculator($this->org))->compute($this->seller, Carbon::parse('2026-01-01'));
        $this->assertEquals(450, $result['total']);
        // The whole 90,000 is the effective sale.
        $this->assertEquals(90000, $result['installments'][0]['effective_sale']);

        // And with no spread_months of its own, the HR Policy's standard
        // (12) applies — the knob lives in one place.
        $this->assertSame(12, $result['spread_months']);
    }

    // ---- The slip run --------------------------------------------------------

    public function test_the_run_builds_the_slip_with_incentive_and_both_nets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-05'));
        $this->structure($this->seller, ['basic' => 15000, 'hra' => 7500]);
        // Incentive earned in January rides February's slip (offset 1).
        IncentivePlan::create([
            'member_id' => $this->seller->id,
            'effective_from' => '2025-01-01',
            'kind' => 'flat_percent',
            'config' => ['percent' => 5],
            'release_offset_months' => 1,
        ]);
        $this->sale($this->seller, 200000, '2026-01-20');

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', [
            'year' => 2026, 'month' => 2,
        ])->assertOk();

        $slip = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->assertEquals(10000, $slip->incentive_amount);      // 5% of 2L
        $this->assertSame('2026-01', $slip->incentive_month);
        // Both readings, as asked: with the incentive and without.
        $this->assertEquals((float) $slip->net_salary - 10000, (float) $slip->net_without_incentive);

        $row = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/salary?year=2026&month=2')
            ->assertOk()->json('data');
        $mine = collect($row)->firstWhere('member.uuid', $this->seller->uuid);
        $this->assertNotEmpty($mine['earnings']);
        $this->assertEquals(10000, $mine['incentive_breakdown']['total']);
    }

    public function test_a_loan_comes_back_through_the_payroll_and_closes_itself(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-05'));
        $this->structure($this->seller, ['basic' => 30000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false, 'has_welfare' => false]);

        $loan = Loan::create([
            'organization_id' => $this->org->id, 'member_id' => $this->seller->id,
            'kind' => 'loan', 'amount' => 10000, 'monthly_installment' => 6000,
            'taken_on' => '2026-01-10',
        ]);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 2])->assertOk();

        $slip = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->assertEquals(6000, $slip->deductions);
        $this->assertEquals(24000, $slip->net_salary);
        $this->assertEquals(4000, $loan->fresh()->balance());

        // Next month recovers only what is left, and the loan closes.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 3])->assertOk();
        $this->assertEquals(0, $loan->fresh()->balance());
        $this->assertSame('closed', $loan->fresh()->status);
        $march = SalarySlip::where('member_id', $this->seller->id)->where('month', 3)->firstOrFail();
        $this->assertEquals(4000, $march->deductions);
    }

    public function test_rebuilding_pending_slips_picks_up_a_new_structure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-05'));

        // The month was run before any structure existed: legacy number only.
        \App\Models\Crm\SalaryRecord::create([
            'member_id' => $this->seller->id, 'amount' => 25000, 'effective_from' => '2025-01-01',
        ]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 2])->assertOk();
        $before = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->assertSame([], $before->deduction_lines ?? []);

        // The structure arrives; a plain generate skips the existing slip…
        $this->structure($this->seller, ['basic' => 11500, 'hra' => 7000, 'has_esi' => true]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 2])->assertOk();
        $this->assertSame([], SalarySlip::where('member_id', $this->seller->id)->firstOrFail()->deduction_lines ?? []);

        // …and the rebuild computes it afresh, deduction side and all.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', [
            'year' => 2026, 'month' => 2, 'refresh_pending' => true,
        ])->assertOk();

        $after = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $taken = collect($after->deduction_lines)->pluck('amount', 'key');
        $this->assertEquals(2760, $taken['pf']);
        $this->assertEquals(741, $taken['esi']);

        // A PAID slip is history: rebuilding leaves it exactly as it was.
        $after->update(['status' => 'paid', 'paid_on' => '2026-02-28']);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', [
            'year' => 2026, 'month' => 2, 'refresh_pending' => true,
        ])->assertOk();
        $this->assertSame(1, SalarySlip::where('member_id', $this->seller->id)->count());
        $this->assertSame('paid', SalarySlip::where('member_id', $this->seller->id)->firstOrFail()->status);
    }

    public function test_recalculating_keeps_the_admins_manual_additions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-05'));
        $this->structure($this->seller, ['basic' => 31000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false, 'has_welfare' => false]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 2])->assertOk();

        // The admin adds a 1,000 bonus by hand.
        $slip = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/salary/' . $slip->uuid, [
            'additions' => 1000, 'deduction_note' => 'bonus paid',
        ])->assertOk();
        $this->assertEquals(32000, (float) $slip->fresh()->net_salary);

        // Recalculating rebuilds the COMPUTED side — the manual bonus is a
        // decision, and decisions survive.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/' . $slip->uuid . '/recalculate')->assertOk();

        $after = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->assertEquals(1000, (float) $after->additions);
        $this->assertSame('bonus paid', $after->deduction_note);
        $this->assertEquals(32000, (float) $after->net_salary);

        // Rebuild pending tears up the whole month — and the bonus still
        // survives, the same rule by either door.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', [
            'year' => 2026, 'month' => 2, 'refresh_pending' => true,
        ])->assertOk();
        $rebuilt = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->assertEquals(1000, (float) $rebuilt->additions);
        $this->assertSame('bonus paid', $rebuilt->deduction_note);
        $this->assertEquals(32000, (float) $rebuilt->net_salary);
    }

    public function test_many_slips_are_marked_paid_in_one_act(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-05'));
        $this->structure($this->seller, ['basic' => 31000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false, 'has_welfare' => false]);
        $this->structure($this->admin, ['basic' => 50000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false, 'has_welfare' => false]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 2])->assertOk();

        $uuids = SalarySlip::pluck('uuid')->all();
        $this->assertCount(2, $uuids);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/mark-paid', [
            'uuids' => $uuids, 'paid_on' => '2026-03-01', 'payment_mode' => 'NEFT',
        ])->assertOk();

        $this->assertSame(2, SalarySlip::where('status', 'paid')->count());
        $this->assertSame('2026-03-01', SalarySlip::first()->paid_on->toDateString());
        $this->assertSame('NEFT', SalarySlip::first()->payment_mode);
        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'salary.bulk_paid']);

        // Already paid: a second run finds nothing pending.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/mark-paid', [
            'uuids' => $uuids,
        ])->assertStatus(422);

        // An employee cannot run the payout.
        $this->actingAs($this->sellerUser)->postJson('/api/v1/crm/salary/mark-paid', [
            'uuids' => $uuids,
        ])->assertForbidden();
    }

    public function test_deleting_a_slip_gives_the_loan_money_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-05'));
        $this->structure($this->seller, ['basic' => 30000, 'hra' => 0, 'has_pf' => false, 'has_edli' => false, 'has_welfare' => false]);
        $loan = Loan::create([
            'organization_id' => $this->org->id, 'member_id' => $this->seller->id,
            'kind' => 'advance', 'amount' => 5000, 'monthly_installment' => 0, 'taken_on' => '2026-01-10',
        ]);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 2])->assertOk();
        $this->assertSame('closed', $loan->fresh()->status);
        $this->assertEquals(0, $loan->fresh()->balance());

        // The run was wrong and is torn up: the advance is owed again.
        $slip = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/salary/' . $slip->uuid)->assertOk();
        $this->assertSame('open', $loan->fresh()->status);
        $this->assertEquals(5000, $loan->fresh()->balance());
    }

    public function test_the_whole_chain_punch_leave_salary_recalculates_and_leaves_a_trail(): void
    {
        // August 2026, a 31-day month. Basic 31,000 and no schemes, so every
        // day is worth exactly 1,000 and the arithmetic reads at a glance.
        $this->structure($this->seller, [
            'basic' => 31000, 'hra' => 0,
            'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
        ]);

        // Mon 3 Aug: punched on time. Tue 4 Aug: nobody punched — absent.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:55'));
        $this->actingAs($this->sellerUser)->postJson('/api/v1/crm/punch/in')->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-08-03 19:00'));
        $this->actingAs($this->sellerUser)->postJson('/api/v1/crm/punch/out')->assertOk();

        // Wed 5 Aug: approved leave — the calendar reads it as Leave, paid
        // from the account.
        (new \App\Services\Crm\LeaveAccount($this->org))
            ->creditMonth($this->seller, Carbon::parse('2026-08-01'));
        Carbon::setTestNow(Carbon::parse('2026-08-04 10:00'));
        $leaveUuid = $this->actingAs($this->sellerUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'full',
            'date_from' => '2026-08-05', 'date_to' => '2026-08-05',
        ])->assertCreated()->json('data.uuid');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leaves/{$leaveUuid}/decide", ['status' => 'approved'])->assertOk();

        // Month over: generate. Working window 3–5 Aug (joined-at limits
        // nothing here; the calendar counts the whole month), so the absent
        // 4th costs exactly one day.
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00'));
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])->assertOk();

        $slip = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $baseline = (float) $slip->net_salary;
        $this->assertNotNull($slip->payable_days);
        // The punch, the leave and the absence all reached the slip on
        // their own: present 1,000 + leave 1,000 in; the absent day out.

        // The Admin now says the 4th was actually present — the old status
        // dropdown — and recalculates JUST this employee.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/punch/0', [
            'status' => 'present', 'note' => 'Was at the client site',
            'member_uuid' => $this->seller->uuid, 'work_date' => '2026-08-04',
        ])->assertOk();

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/' . $slip->uuid . '/recalculate')->assertOk();

        $recalced = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        // One absent day forgiven = one day of basic back.
        $this->assertEquals($baseline + 1000, (float) $recalced->net_salary);
        $this->assertEquals((float) $slip->payable_days + 1, (float) $recalced->payable_days);

        // And the other direction: the approved leave is withdrawn, the day
        // becomes absence again, the account gets its day back.
        $this->actingAs($this->adminUser)->deleteJson("/api/v1/crm/leaves/{$leaveUuid}")->assertOk();
        $this->assertEquals(1.0, (new \App\Services\Crm\LeaveAccount($this->org))->balance($this->seller->fresh(), 2026));

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/' . $recalced->fresh()->uuid . '/recalculate')->assertOk();
        $after = SalarySlip::where('member_id', $this->seller->id)->firstOrFail();
        $this->assertEquals($baseline, (float) $after->net_salary);

        // Every hand that touched money is on the trail.
        foreach ([
            'punch.overridden' => ['to' => 'present'],
            'leave.approved' => [],
            'leave.approval_withdrawn' => [],
            'salary.generated' => [],
            'salary.recalculated' => [],
        ] as $action => $expect) {
            $row = \App\Models\Crm\ActivityLog::where('action', $action)->latest('id')->first();
            $this->assertNotNull($row, $action . ' missing from the trail');
            foreach ($expect as $key => $value) {
                $this->assertSame($value, $row->changes[$key] ?? null, $action . '.' . $key);
            }
        }
        // The recalculation names the money it moved.
        $recalc = \App\Models\Crm\ActivityLog::where('action', 'salary.recalculated')->orderBy('id')->first();
        $this->assertEquals(1000, $recalc->changes['moved_by']);

        // A PAID slip refuses recomputation — history stays history.
        $after->update(['status' => 'paid', 'paid_on' => '2026-09-01']);
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/' . $after->uuid . '/recalculate')
            ->assertStatus(422);
    }

    public function test_the_compensation_endpoints_are_company_authority(): void
    {
        // An employee cannot set their own terms.
        $this->actingAs($this->sellerUser)
            ->postJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/structures', [
                'effective_from' => '2026-01-01', 'basic' => 900000,
                'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
            ])->assertForbidden();

        // The Admin can, and the card reads it back with the CTC computed.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/structures', [
                'effective_from' => '2026-01-01', 'basic' => 22000, 'hra' => 11000,
                'components' => ['other' => 2500, 'telephone' => 0],
                'has_pf' => true, 'has_edli' => true, 'has_esi' => false, 'has_welfare' => true,
            ])->assertCreated()
            ->assertJsonPath('data.gross_monthly', 35500);

        $card = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation')
            ->assertOk()->json('data');
        $this->assertCount(1, $card['structures']);
        // The zero component was noise and was dropped.
        $this->assertSame(['other' => 2500], (array) $card['structures'][0]['components']);

        // A plan with slabs saves and previews.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/plans', [
                'effective_from' => '2026-01-01', 'kind' => 'slab',
                'config' => ['slab_mode' => 'whole', 'slabs' => [
                    ['upto' => 100000, 'percent' => 5], ['upto' => null, 'percent' => 8],
                ]],
            ])->assertCreated();

        $this->sale($this->seller, 400000, '2026-01-15');
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/incentive-preview?month=2026-01')
            ->assertOk()
            ->assertJsonPath('data.total', 32000);   // 4L lands past 1L → 8%
    }
}
