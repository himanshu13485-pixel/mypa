<?php

namespace Tests\Feature;

use App\Models\Crm\Holiday;
use App\Models\Crm\LeaveLedger;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalaryRecord;
use App\Models\User;
use App\Services\Crm\LeaveAccount;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HR layer: one policy, a holiday calendar, a leave account, and a
 * punch report that can tell absence from leave.
 *
 * What matters: the rules live in one place and everyone can read them but
 * only the Admin can move them; a declared holiday and an approved leave
 * both stop a day being counted as absence; leave is earned only after
 * probation and only from the following month; what the account cannot
 * cover is unpaid; and what nobody used is bought back at year end.
 */
class CrmHrPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $employeeUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->employeeUser = $this->makeUser('worker@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->employee = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->employeeUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'status' => 'active',
            'joined_at' => '2025-01-01',
            'rights' => ['punch' => ['view'], 'leaves' => ['view']],
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
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    // ---- The policy ----------------------------------------------------------

    public function test_the_policy_is_readable_by_all_and_movable_only_by_the_admin(): void
    {
        // An employee may read the rules they are judged by.
        $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/hr-policy')
            ->assertOk()
            ->assertJsonPath('data.policy.probation_days', 180)
            ->assertJsonPath('data.policy.work_start', '10:00')
            ->assertJsonPath('data.can_edit', false);

        $this->actingAs($this->employeeUser)->putJson('/api/v1/crm/hr-policy', $this->policyPayload())
            ->assertForbidden();

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/hr-policy', $this->policyPayload([
            'work_start' => '09:30', 'grace_minutes' => 10, 'probation_days' => 90,
        ]))->assertOk();

        $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/hr-policy')
            ->assertOk()
            ->assertJsonPath('data.policy.work_start', '09:30')
            ->assertJsonPath('data.policy.probation_days', 90);

        // A half day cannot begin before lateness does.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/hr-policy', $this->policyPayload([
            'grace_minutes' => 60, 'half_day_after_minutes' => 30,
        ]))->assertStatus(422);
    }

    public function test_late_and_half_day_follow_the_policy_not_the_code(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/hr-policy', $this->policyPayload([
            'work_start' => '10:00', 'grace_minutes' => 15, 'half_day_after_minutes' => 120,
        ]))->assertOk();

        // 10:10 — inside the grace.
        Carbon::setTestNow(Carbon::parse('next monday')->setTime(10, 10));
        $this->assertSame('present', $this->actingAs($this->employeeUser)
            ->postJson('/api/v1/crm/punch/in')->json('data.status'));

        // 10:40 the next day — late.
        Carbon::setTestNow(Carbon::parse('next monday')->addDay()->setTime(10, 40));
        $this->assertSame('late', $this->actingAs($this->employeeUser)
            ->postJson('/api/v1/crm/punch/in')->json('data.status'));

        // 12:30 the day after — so late it is only half a day.
        Carbon::setTestNow(Carbon::parse('next monday')->addDays(2)->setTime(12, 30));
        $this->assertSame('half_day', $this->actingAs($this->employeeUser)
            ->postJson('/api/v1/crm/punch/in')->json('data.status'));
    }

    // ---- The holiday calendar -------------------------------------------------

    public function test_the_admin_uploads_a_years_holidays_and_punch_reads_them(): void
    {
        $this->actingAs($this->employeeUser)->putJson('/api/v1/crm/hr-policy/holidays', [
            'financial_year' => 2026, 'holidays' => [],
        ])->assertForbidden();

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/hr-policy/holidays', [
            'financial_year' => 2026,
            'holidays' => [
                ['holiday_date' => '2026-08-15', 'name' => 'Independence Day'],
                ['holiday_date' => '2026-10-02', 'name' => 'Gandhi Jayanti'],
                // Outside the year being uploaded: a typo, not a holiday.
                ['holiday_date' => '2025-01-26', 'name' => 'Republic Day'],
            ],
        ])->assertOk()->assertJsonPath('saved', 2);

        $this->assertSame(2, Holiday::count());

        $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/hr-policy/holidays?financial_year=2026')
            ->assertOk()
            ->assertJsonPath('data.label', '2026–27')
            ->assertJsonCount(2, 'data.holidays');

        // The punch report knows the office was shut, with nobody punching.
        Carbon::setTestNow(Carbon::parse('2026-08-17 18:00'));
        $rows = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/punch?date_from=2026-08-15&date_to=2026-08-15')
            ->assertOk()->json('data'))
            ->firstWhere('member.uuid', $this->employee->uuid);

        $this->assertSame('holiday', $rows['status']);
        $this->assertSame('Independence Day', $rows['holiday_name']);
        // And a holiday is a paid day.
        $this->assertEquals(1, $rows['day_value']);
    }

    // ---- Probation and accrual ------------------------------------------------

    public function test_no_leave_accrues_during_probation_and_the_first_lands_the_month_after(): void
    {
        // Joined 1 Jan; the policy's 180 days end on 30 June.
        $account = new LeaveAccount($this->org);
        $this->employee->update(['joined_at' => '2026-01-01']);
        $this->employee->refresh();

        // A month inside probation earns nothing.
        $this->assertSame(0.0, $account->creditMonth($this->employee, Carbon::parse('2026-05-01')));
        // The month probation ends in is still not a full month past it.
        $this->assertSame(0.0, $account->creditMonth($this->employee, Carbon::parse('2026-06-01')));
        // The first of the following month is when the first day lands.
        $this->assertSame(1.0, $account->creditMonth($this->employee, Carbon::parse('2026-07-01')));
        // Running the job twice credits once.
        $this->assertSame(0.0, $account->creditMonth($this->employee, Carbon::parse('2026-07-01')));

        $this->assertSame(1.0, $account->balance($this->employee, 2026));

        // A longer probation for one person delays only that person.
        $this->employee->update(['probation_days' => 365]);
        $this->assertTrue($this->employee->fresh()->onProbation(180, Carbon::parse('2026-08-01')));
    }

    public function test_a_longer_probation_is_written_into_the_trail(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/employees/' . $this->employee->uuid, [
            'name' => $this->employeeUser->name,
            'email' => $this->employeeUser->email,
            'crm_role' => 'employee',
            'status' => 'active',
            'joined_at' => '2025-01-01',
            'probation_days' => 270,
        ])->assertOk()->assertJsonPath('data.probation_days', 270);

        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'employee.probation_changed']);
    }

    // ---- Leave, and what the punch report makes of it -------------------------

    public function test_approved_leave_spends_the_account_and_shows_as_leave_not_absent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00'));
        $account = new LeaveAccount($this->org);
        // Two days already earned.
        $account->creditMonth($this->employee, Carbon::parse('2026-07-01'));
        $account->creditMonth($this->employee, Carbon::parse('2026-08-01'));

        $uuid = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave',
            'duration' => 'full',
            'date_from' => '2026-08-11',
            'date_to' => '2026-08-11',
            'reason' => 'Family work.',
        ])->assertCreated()->json('data.uuid');

        // Pending leave is not yet a reason to be away.
        Carbon::setTestNow(Carbon::parse('2026-08-12 11:00'));
        $before = $this->dayOf('2026-08-11');
        $this->assertSame('absent', $before['status']);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.paid_days', '1.00')
            ->assertJsonPath('data.unpaid_days', '0.00');

        $after = $this->dayOf('2026-08-11');
        $this->assertSame('leave', $after['status']);
        $this->assertSame('Casual Leave', $after['leave_category']);
        $this->assertEquals(1, $after['day_value']);

        // The account paid for it.
        $this->assertSame(1.0, $account->balance($this->employee, 2026));
        $this->assertSame(1, LeaveLedger::where('kind', 'debit')->count());
    }

    public function test_a_half_day_leave_shows_as_a_half_day_and_costs_half_a_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00'));
        (new LeaveAccount($this->org))->creditMonth($this->employee, Carbon::parse('2026-08-01'));

        $uuid = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'half',
            'date_from' => '2026-08-11', 'date_to' => '2026-08-11',
        ])->assertCreated()->json('data.uuid');

        Carbon::setTestNow(Carbon::parse('2026-08-12 11:00'));
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.paid_days', '0.50');

        $row = $this->dayOf('2026-08-11');
        $this->assertSame('half_day', $row['status']);
        $this->assertEquals(0.5, $row['day_value']);
    }

    public function test_the_office_does_not_deal_in_quarter_days(): void
    {
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'quarter',
            'date_from' => '2026-08-11', 'date_to' => '2026-08-11',
        ])->assertStatus(422);
    }

    public function test_leave_the_account_cannot_cover_is_still_leave_but_unpaid(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 11:00'));
        // Nothing earned at all — straight out of probation.
        $uuid = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'full',
            'date_from' => '2026-08-11', 'date_to' => '2026-08-12',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.paid_days', '0.00')
            ->assertJsonPath('data.unpaid_days', '2.00');

        Carbon::setTestNow(Carbon::parse('2026-08-13 11:00'));
        $row = $this->dayOf('2026-08-11');

        // Still leave on the calendar — but it buys nothing.
        $this->assertSame('leave', $row['status']);
        $this->assertEquals(0, $row['day_value']);
    }

    // ---- Year end -------------------------------------------------------------

    public function test_unused_leave_is_bought_back_at_a_day_of_basic_salary(): void
    {
        SalaryRecord::create([
            'member_id' => $this->employee->id,
            'amount' => 31000,
            'effective_from' => '2026-01-01',
        ]);

        $account = new LeaveAccount($this->org);
        foreach (['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'] as $month) {
            $account->creditMonth($this->employee, Carbon::parse($month));
        }
        $this->assertSame(4.0, $account->balance($this->employee, 2026));

        Carbon::setTestNow(Carbon::parse('2027-04-01 00:30'));
        $paid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/hr-policy/year-end', [
            'financial_year' => 2026,
        ])->assertOk()->json('data');

        // 31 March 2027 has 31 days, so a day of basic is 1000.
        $this->assertEquals(4, $paid[0]['days']);
        $this->assertEquals(1000, $paid[0]['day_rate']);
        $this->assertEquals(4000, $paid[0]['amount']);

        // The account is closed, and the new year opens at nothing.
        $this->assertSame(0.0, $account->balance($this->employee, 2026));
        $this->assertSame(0.0, $account->balance($this->employee, 2027));

        // Running it again pays nothing twice.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/hr-policy/year-end', [
            'financial_year' => 2026,
        ])->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- What a month is worth ------------------------------------------------

    public function test_the_summary_counts_payable_days_and_a_salary_follows_them(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 20:00'));

        // Three days: present, present, half day. The rest of the window is
        // untouched, so it reads as absence.
        foreach ([['2026-08-03', 9, 55], ['2026-08-04', 9, 50]] as [$date, $h, $m]) {
            Carbon::setTestNow(Carbon::parse($date)->setTime($h, $m));
            $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/in')->assertCreated();
            Carbon::setTestNow(Carbon::parse($date)->setTime(19, 0));
            $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/out')->assertOk();
        }
        Carbon::setTestNow(Carbon::parse('2026-08-05')->setTime(9, 55));
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/in')->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-08-05')->setTime(12, 0));
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/out')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-05 20:00'));
        $summary = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/punch?date_from=2026-08-03&date_to=2026-08-05')
            ->assertOk()->json('summary.members'))
            ->firstWhere('member_uuid', $this->employee->uuid);

        $this->assertSame(2, $summary['present']);
        $this->assertSame(1, $summary['half_day']);
        $this->assertEquals(2.5, $summary['payable_days']);
        $this->assertEquals(0.5, $summary['lop_days']);
        $this->assertTrue($summary['has_attendance']);
    }

    /** @param array<string, mixed> $overrides */
    private function policyPayload(array $overrides = []): array
    {
        return $overrides + [
            'work_start' => '10:00',
            'work_end' => '19:00',
            'grace_minutes' => 15,
            'half_day_after_minutes' => 180,
            'half_day_hours' => 4.5,
            'full_day_hours' => 8.0,
            'week_off_days' => [0],
            'probation_days' => 180,
            'monthly_leave_credit' => 1.0,
            'encash_unused_leave' => true,
            'financial_year_start_month' => 4,
        ];
    }

    /**
     * This employee's calendar row for one day. The report holds a row per
     * person per day, so the person has to be named.
     *
     * @return array<string, mixed>
     */
    private function dayOf(string $date): array
    {
        return collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/punch?date_from=' . $date . '&date_to=' . $date)
            ->assertOk()->json('data'))
            ->firstWhere('member.uuid', $this->employee->uuid);
    }
}
