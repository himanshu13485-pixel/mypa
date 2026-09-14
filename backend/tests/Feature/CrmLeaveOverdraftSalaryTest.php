<?php

namespace Tests\Feature;

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
 * Leave taken past the balance is cut from that month's salary - even in a
 * month with no punches - and a slip always says how many days it counted.
 */
class CrmLeaveOverdraftSalaryTest extends TestCase
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
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin', 'status' => 'active']);

        $this->employee = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('priyanshu@grapout.test')->id,
            'crm_role' => 'subadmin', 'status' => 'active', 'joined_at' => '2024-01-01',
        ]);
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

    private function slip(): SalarySlip
    {
        return SalarySlip::where('member_id', $this->employee->id)->where('year', 2026)->where('month', 8)->firstOrFail();
    }

    public function test_leave_beyond_the_balance_is_cut_from_that_months_salary_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));
        $account = new LeaveAccount($this->org);

        // One day earned in August, three taken by hand, one earned in September.
        $account->creditMonth($this->employee, Carbon::parse('2026-08-01'));
        $account->adjust($this->employee, -1, Carbon::parse('2026-08-20'), '1 leave');
        $account->adjust($this->employee, -2, Carbon::parse('2026-08-22'), '2 leaves');
        $account->creditMonth($this->employee, Carbon::parse('2026-09-01'));
        $this->assertEquals(-1.0, $account->balance($this->employee, 2026));

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])->assertOk();

        $slip = $this->slip();
        $this->assertSame(31, (int) $slip->month_days);
        $this->assertEquals(29.0, (float) $slip->payable_days);
        $this->assertEquals(2.0, (float) $slip->lop_days);
        $this->assertEquals(2.0, (float) $slip->leave_overdrawn_days);
        $this->assertNull($slip->present_days);
        // 18,000 for 29 of 31 days.
        $this->assertEqualsWithDelta(16838.71, (float) $slip->net_salary, 0.02);

        // The cut settles the account: it is no longer below zero for August.
        $this->assertEquals(1.0, $account->balance($this->employee->fresh(), 2026));

        // Recalculating does not cut twice.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/' . $slip->uuid . '/recalculate')->assertOk();
        $again = $this->slip();
        $this->assertEquals(2.0, (float) $again->leave_overdrawn_days);
        $this->assertEqualsWithDelta(16838.71, (float) $again->net_salary, 0.02);
        $this->assertEquals(1.0, $account->balance($this->employee->fresh(), 2026));

        // September carries no August debt into its own salary.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 9])->assertOk();
        $september = SalarySlip::where('member_id', $this->employee->id)->where('month', 9)->firstOrFail();
        $this->assertEquals(0.0, (float) $september->leave_overdrawn_days);
        $this->assertEquals(30.0, (float) $september->payable_days);

        // The payslip reads the days out.
        $this->actingAs($this->adminUser)->get('/api/v1/crm/salary/' . $again->uuid . '/pdf')->assertOk();
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/salary?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('data.0.leave_overdrawn_days', 2)
            ->assertJsonPath('data.0.month_days', 31);

        // Deleting the slip gives the days back to the account.
        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/salary/' . $again->uuid)->assertOk();
        $this->assertEquals(-1.0, $account->balance($this->employee->fresh(), 2026));
    }

    public function test_a_month_within_the_balance_is_paid_whole_and_still_counts_its_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00'));
        $account = new LeaveAccount($this->org);
        $account->adjust($this->employee, 3, Carbon::parse('2026-07-31'), 'Opening balance');
        $account->adjust($this->employee, -2, Carbon::parse('2026-08-12'), '2 leaves');

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])->assertOk();

        $slip = $this->slip();
        $this->assertSame(31, (int) $slip->month_days);
        $this->assertEquals(31.0, (float) $slip->payable_days);
        $this->assertEquals(0.0, (float) $slip->leave_overdrawn_days);
        $this->assertEquals(18000.0, (float) $slip->net_salary);
    }
}
