<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\LeaveLedger;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalaryRecord;
use App\Models\Crm\SalarySlip;
use App\Models\User;
use App\Services\Crm\LeaveAccount;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The paid-leave account as the Company Admin runs it: an opening balance,
 * a month credited on demand, adjustments that can be taken back - and the
 * balance paying for absent days before any salary is cut.
 */
class CrmLeaveBalanceAccountTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Member> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);

        foreach ([
            'admin' => ['admin', '2024-01-01'],
            'priyanshu' => ['subadmin', '2024-01-01'],
            'seller' => ['employee', '2025-01-01'],
            // Joined in June: still on probation through August.
            'newbie' => ['employee', '2026-06-01'],
        ] as $key => [$role, $joined]) {
            $user = User::factory()->create(['name' => ucfirst($key)]);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'Asia/Kolkata']);
            $this->users[$key] = $user;

            $this->members[$key] = Member::create([
                'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => $role,
                'status' => 'active', 'joined_at' => $joined,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adjust(string $who, float $days, string $on, string $note, string $as = 'admin')
    {
        return $this->actingAs($this->users[$as])->postJson(
            '/api/v1/crm/hr-policy/leave-accounts/' . $this->members[$who]->uuid . '/adjust',
            ['days' => $days, 'effective_on' => $on, 'note' => $note],
        );
    }

    private function balance(string $who): float
    {
        return (new LeaveAccount($this->org))->balance($this->members[$who]->fresh(), 2026);
    }

    public function test_only_the_company_admin_adjusts_and_credits(): void
    {
        foreach (['priyanshu', 'seller'] as $who) {
            $this->adjust('seller', 5, '2026-07-31', 'Opening balance', $who)->assertForbidden();
            $this->actingAs($this->users[$who])->postJson('/api/v1/crm/hr-policy/accrual', ['month' => '2026-08'])->assertForbidden();
        }

        // Reading the accounts is still open to them.
        $this->actingAs($this->users['priyanshu'])->getJson('/api/v1/crm/hr-policy/leave-accounts?financial_year=2026')
            ->assertOk()
            ->assertJsonPath('data.can_edit', false);

        $this->assertSame(0.0, $this->balance('seller'));
    }

    public function test_an_opening_balance_a_credited_month_and_a_mistake_taken_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00'));

        $this->adjust('seller', 5, '2026-07-31', 'Opening balance up to 31 Jul 2026')->assertOk();
        $this->adjust('seller', -0.5, '2026-07-31', 'Half day settled')->assertOk();
        $this->adjust('seller', 0, '2026-07-31', 'Nothing')->assertStatus(422);

        // August credited on demand - past probation only, and only once.
        $this->actingAs($this->users['admin'])->postJson('/api/v1/crm/hr-policy/accrual', ['month' => '2026-08'])->assertOk();
        $this->actingAs($this->users['admin'])->postJson('/api/v1/crm/hr-policy/accrual', ['month' => '2026-08'])->assertOk();

        $this->assertSame(5.5, $this->balance('seller'));
        $this->assertSame(0.0, $this->balance('newbie'));

        $row = collect($this->actingAs($this->users['admin'])->getJson('/api/v1/crm/hr-policy/leave-accounts?financial_year=2026')
            ->assertOk()->assertJsonPath('data.can_edit', true)->json('data.members'))
            ->firstWhere('member_uuid', $this->members['seller']->uuid);
        $this->assertEquals(4.5, $row['adjusted']);
        $this->assertEquals(1, $row['earned']);
        $this->assertEquals(5.5, $row['balance']);

        // The half day was a mistake: taken back.
        $entries = collect($this->actingAs($this->users['admin'])
            ->getJson('/api/v1/crm/hr-policy/leave-accounts/' . $this->members['seller']->uuid . '?financial_year=2026')
            ->assertOk()->json('data.entries'));
        $half = $entries->first(fn ($e) => $e['kind'] === 'adjust' && (float) $e['days'] === -0.5);
        $this->actingAs($this->users['admin'])->deleteJson('/api/v1/crm/hr-policy/leave-ledger/' . $half['uuid'])->assertOk();
        $this->assertSame(6.0, $this->balance('seller'));

        // An earned month is not an adjustment to delete.
        $credit = $entries->firstWhere('kind', 'credit');
        $this->actingAs($this->users['admin'])->deleteJson('/api/v1/crm/hr-policy/leave-ledger/' . $credit['uuid'])->assertStatus(422);

        $this->assertTrue(ActivityLog::where('action', 'hr.leave_adjusted')->exists());
        $this->assertTrue(ActivityLog::where('action', 'hr.leave_adjustment_deleted')->exists());
    }

    public function test_absent_days_are_paid_from_the_balance_before_the_salary_is_cut(): void
    {
        SalaryRecord::create([
            'member_id' => $this->members['seller']->id, 'amount' => 31000, 'effective_from' => '2026-01-01',
        ]);

        // One day punched in August, so the month has attendance to go on;
        // every other working day is absent.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:55'));
        $this->actingAs($this->users['seller'])->postJson('/api/v1/crm/punch/in')->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-08-03 19:00'));
        $this->actingAs($this->users['seller'])->postJson('/api/v1/crm/punch/out')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00'));
        $this->adjust('seller', 3, '2026-07-31', 'Opening balance up to 31 Jul 2026')->assertOk();

        $this->actingAs($this->users['admin'])->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])->assertOk();
        $slip = SalarySlip::where('member_id', $this->members['seller']->id)->firstOrFail();

        // Three absences paid for by the account; the rest still cut.
        $this->assertEquals(3, (float) $slip->leave_covered_days);
        $this->assertSame(0.0, $this->balance('seller'));
        $this->assertEquals(3, (float) LeaveLedger::where('kind', 'absence')->where('period', '2026-08')->value('days'));
        $this->assertGreaterThan(0, (float) $slip->lop_days);
        $coveredPayable = (float) $slip->payable_days;

        // Deleting the slip gives the days back.
        $this->actingAs($this->users['admin'])->deleteJson('/api/v1/crm/salary/' . $slip->uuid)->assertOk();
        $this->assertSame(3.0, $this->balance('seller'));
        $this->assertSame(0, LeaveLedger::where('kind', 'absence')->count());

        // With the rule switched off, the same month costs those three days.
        $settings = $this->org->fresh()->settings ?? [];
        $settings['hr'] = array_merge((array) ($settings['hr'] ?? []), ['cover_absence_from_leave' => false]);
        $this->org->update(['settings' => $settings]);

        $this->actingAs($this->users['admin'])->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])->assertOk();
        $plain = SalarySlip::where('member_id', $this->members['seller']->id)->firstOrFail();

        $this->assertEquals(0, (float) $plain->leave_covered_days);
        $this->assertEquals($coveredPayable - 3, (float) $plain->payable_days);
        $this->assertSame(3.0, $this->balance('seller'));
    }
}
