<?php

namespace Tests\Feature;

use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalarySlip;
use App\Models\Crm\SalaryStructure;
use App\Models\User;
use App\Services\Crm\LeaveAccount;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leave the balance could not pay for comes off the salary.
 *
 * There are two ways somebody's leave account goes short, and only one of
 * them was ever cut from pay.
 *
 * An admin writing days off the account by hand takes the balance negative,
 * and the slip cuts those days - CrmLeaveOverdraftSalaryTest covers that and
 * always has. But a leave applied for and approved never takes the balance
 * negative at all: the account pays for what it can and the rest is written
 * on the leave itself as unpaid_days. Approving three days with one day in
 * hand leaves the balance at zero and two days unpaid.
 *
 * Those two days were being paid in full. The register asked only whether
 * the leave had any paid days at all, which a partly-paid leave does, so
 * every day of it counted as a full day's work. Leave beyond somebody's
 * entitlement cost them nothing, and the payslip said "without pay: 0" for a
 * month that had two.
 */
class CrmUnpaidLeaveCutsSalaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private Member $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);

        $this->adminUser = $this->makeUser('boss@grapout.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->employee = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('praveen@grapout.test')->id,
            'crm_role' => 'employee', 'status' => 'active', 'joined_at' => '2024-01-01',
            /*
             * Does not clock in, so every day that is not a leave day counts
             * as worked. That is what isolates the leave: without it the
             * other twenty-eight days would be absences for want of a punch,
             * and the number this test is about would be lost inside them.
             */
            'punch_waived' => true,
        ]);

        // 18,000 a month, and no statutory deductions, so the arithmetic in
        // these tests is the leave and nothing else.
        SalaryStructure::create([
            'member_id' => $this->employee->id, 'effective_from' => '2026-01-01',
            'basic' => 11500, 'hra' => 2500, 'components' => ['other_allowance' => 4000],
            'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
        ]);
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
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    /** An approved leave over these dates, spending whatever balance exists. */
    private function approvedLeave(string $from, string $to, float $days, string $duration = 'full'): Leave
    {
        $leave = Leave::create([
            'organization_id' => $this->org->id,
            'member_id' => $this->employee->id,
            'category' => 'casual',
            'duration' => $duration,
            'date_from' => $from,
            'date_to' => $to,
            'days' => $days,
            'reason' => 'Family',
            'status' => 'approved',
            'decided_by' => $this->adminUser->id,
            'decided_at' => now(),
        ]);

        // The same call the approval endpoint makes: the account pays for
        // what it can, and the rest is marked unpaid on the leave.
        (new LeaveAccount($this->org))->spend($leave->fresh()->load('member'), $this->adminUser->id);

        return $leave->fresh();
    }

    private function generate(int $month = 8): SalarySlip
    {
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => $month])
            ->assertOk();

        return SalarySlip::where('member_id', $this->employee->id)
            ->where('year', 2026)->where('month', $month)->firstOrFail();
    }

    public function test_the_days_the_balance_could_not_cover_are_not_paid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));

        // One day in hand, three taken.
        (new LeaveAccount($this->org))->adjust($this->employee, 1, Carbon::parse('2026-07-31'), 'Opening balance');
        $leave = $this->approvedLeave('2026-08-10', '2026-08-12', 3);

        // The account paid for one of the three; this is the state the bug
        // was hiding behind.
        $this->assertEquals(1.0, (float) $leave->paid_days);
        $this->assertEquals(2.0, (float) $leave->unpaid_days);

        $slip = $this->generate();

        $this->assertEquals(29.0, (float) $slip->payable_days, 'two of the 31 days were not paid for');
        $this->assertEquals(2.0, (float) $slip->lop_days);
        // 18,000 for 29 of 31 days.
        $this->assertEqualsWithDelta(16838.71, (float) $slip->net_salary, 0.02);
    }

    public function test_leave_the_balance_covers_is_paid_in_full(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));

        // Plenty in hand: this is ordinary paid leave and must not be docked.
        (new LeaveAccount($this->org))->adjust($this->employee, 5, Carbon::parse('2026-07-31'), 'Opening balance');
        $leave = $this->approvedLeave('2026-08-10', '2026-08-12', 3);

        $this->assertEquals(3.0, (float) $leave->paid_days);
        $this->assertEquals(0.0, (float) $leave->unpaid_days);

        $slip = $this->generate();

        $this->assertEquals(31.0, (float) $slip->payable_days);
        $this->assertEquals(0.0, (float) $slip->lop_days);
        $this->assertEquals(18000.0, (float) $slip->net_salary);
    }

    public function test_leave_with_nothing_in_hand_is_wholly_unpaid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));

        // No balance at all: every day of it is unpaid. This was the one
        // case the old rule got right.
        $leave = $this->approvedLeave('2026-08-10', '2026-08-12', 3);

        $this->assertEquals(0.0, (float) $leave->paid_days);

        $slip = $this->generate();

        $this->assertEquals(28.0, (float) $slip->payable_days);
        $this->assertEquals(3.0, (float) $slip->lop_days);
    }

    public function test_the_paid_days_are_the_first_ones(): void
    {
        /*
         * The balance is spent as the leave runs, so the days it ran out on
         * are the later ones. Which particular days are unpaid matters: a
         * month boundary, or a slip recalculated after the fact, must not
         * move money about depending on how the split was decided.
         */
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));
        (new LeaveAccount($this->org))->adjust($this->employee, 1, Carbon::parse('2026-07-31'), 'Opening balance');
        $this->approvedLeave('2026-08-10', '2026-08-12', 3);

        $days = (new \App\Services\Crm\AttendanceCalendar($this->org))->build(
            collect([$this->employee]),
            Carbon::parse('2026-08-10'),
            Carbon::parse('2026-08-12'),
        )->keyBy('work_date');

        $this->assertEquals(1.0, $days['2026-08-10']['day_value']);
        $this->assertEquals(0.0, $days['2026-08-11']['day_value']);
        $this->assertEquals(0.0, $days['2026-08-12']['day_value']);

        // Still leave, all three of them - an unpaid day is not an absence,
        // and the register must not call it one.
        foreach (['2026-08-10', '2026-08-11', '2026-08-12'] as $date) {
            $this->assertSame('leave', $days[$date]['status']);
        }
    }

    public function test_an_unpaid_half_day_is_worth_half_a_day_less(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));

        $leave = $this->approvedLeave('2026-08-10', '2026-08-10', 0.5, 'half');
        $this->assertEquals(0.0, (float) $leave->paid_days);

        $slip = $this->generate();

        /*
         * The whole day, because this office already charges half a day for
         * a half-day leave even when the account pays for it - the rule
         * CrmHrPolicyTest states - and the unpaid half costs the other half.
         * What matters here is the difference from a paid half-day, which is
         * half a day, exactly as it is for a full day's leave.
         */
        $this->assertEquals(30.0, (float) $slip->payable_days);
    }

    public function test_a_half_day_the_balance_covers_still_costs_half_a_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));

        (new LeaveAccount($this->org))->adjust($this->employee, 5, Carbon::parse('2026-07-31'), 'Opening balance');
        $leave = $this->approvedLeave('2026-08-10', '2026-08-10', 0.5, 'half');
        $this->assertEquals(0.5, (float) $leave->paid_days);

        /*
         * Unchanged, and deliberately so. This office charges half a day for
         * a half-day leave whether or not the account pays for it, which is
         * stated outright in CrmHrPolicyTest. Nothing here is an opinion
         * about whether that is the right rule - only that fixing unpaid
         * leave must not quietly rewrite it.
         */
        $this->assertEquals(30.5, (float) $this->generate()->payable_days);
    }
}
