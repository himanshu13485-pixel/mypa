<?php

namespace Tests\Feature;

use App\Models\Crm\KpiParameter;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DWR + Punch. What matters: the weighted score and band come out of the
 * assignment arithmetic, entries snapshot the day's weightage/target, an
 * employee's window is their own reports, punch status is computed against
 * office hours on the server, and the admin override sticks.
 */
class CrmDwrPunchTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $employeeUser;
    protected Organization $org;
    protected Member $adminMember;
    protected Member $employeeMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->employeeUser = $this->makeUser('emp@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->employeeMember = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->employeeUser->id,
            'crm_role' => 'employee',
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

    /** Two KPIs assigned: calls (weight 60, target 50) + closing (weight 40, target 20000). */
    private function assignKpis(): array
    {
        $calls = KpiParameter::create(['organization_id' => $this->org->id, 'name' => 'Fresh Calls', 'unit' => 'count']);
        $closing = KpiParameter::create(['organization_id' => $this->org->id, 'name' => 'Closing (INR)', 'unit' => 'currency']);

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/dwr-assignments/' . $this->employeeMember->uuid, [
            'kpis' => [
                ['parameter_id' => $calls->id, 'weightage' => 60, 'daily_target' => 50],
                ['parameter_id' => $closing->id, 'weightage' => 40, 'daily_target' => 20000],
            ],
        ])->assertOk();

        return [$calls, $closing];
    }

    public function test_score_and_band_come_from_the_weighted_arithmetic(): void
    {
        [$calls, $closing] = $this->assignKpis();

        // 25/50 calls = 50% on weight 60; 20000/20000 = 100% on weight 40
        // → (60*0.5 + 40*1.0) / 100 = 70% → "good".
        $response = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/dwr', [
            'work_date' => now()->toDateString(),
            'entries' => [
                ['parameter_id' => $calls->id, 'value' => 25],
                ['parameter_id' => $closing->id, 'value' => 20000],
            ],
        ])->assertCreated();

        $this->assertEquals(70.0, $response->json('data.score'));
        $this->assertSame('good', $response->json('data.band'));
        // Entries carry the snapshotted weightage and target.
        $this->assertEquals(60, $response->json('data.entries.0.weightage'));
        $this->assertEquals(50, $response->json('data.entries.0.target'));

        // Submitted is FINAL: the owner cannot resubmit the day.
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/dwr', [
            'work_date' => now()->toDateString(),
            'entries' => [
                ['parameter_id' => $calls->id, 'value' => 50],
                ['parameter_id' => $closing->id, 'value' => 20000],
            ],
        ])->assertStatus(422);

        // The Admin corrects the day on the employee's behalf — one row,
        // replaced, never duplicated.
        $again = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/dwr', [
            'work_date' => now()->toDateString(),
            'member_uuid' => $this->employeeMember->uuid,
            'entries' => [
                ['parameter_id' => $calls->id, 'value' => 50],
                ['parameter_id' => $closing->id, 'value' => 20000],
            ],
        ])->assertCreated();
        $this->assertEquals(100.0, $again->json('data.score'));
        $this->assertSame('outstanding', $again->json('data.band'));
        $this->assertSame(1, $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/dwr')->json('total'));
    }

    public function test_an_employee_sees_only_their_own_reports_and_admin_sees_all(): void
    {
        [$calls] = $this->assignKpis();
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/dwr', [
            'work_date' => now()->toDateString(),
            'entries' => [['parameter_id' => $calls->id, 'value' => 10]],
        ])->assertCreated();

        // The admin also files one for themselves (needs an assignment first).
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/dwr-assignments/' . $this->adminMember->uuid, [
            'kpis' => [['parameter_id' => $calls->id, 'weightage' => 100, 'daily_target' => 10]],
        ]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/dwr', [
            'work_date' => now()->toDateString(),
            'entries' => [['parameter_id' => $calls->id, 'value' => 10]],
        ])->assertCreated();

        $this->assertSame(1, $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/dwr')->json('total'));
        $this->assertSame(2, $this->actingAs($this->adminUser)->getJson('/api/v1/crm/dwr')->json('total'));

        // The stats feed obeys the same window.
        $bands = $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/dwr/stats')->json('data.bands');
        $this->assertSame(1, collect($bands)->sum('count'));
    }

    public function test_old_reports_cannot_be_backfilled_by_employees(): void
    {
        [$calls] = $this->assignKpis();

        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/dwr', [
            'work_date' => now()->subDays(10)->toDateString(),
            'entries' => [['parameter_id' => $calls->id, 'value' => 10]],
        ])->assertStatus(422);
    }

    public function test_punch_in_is_late_after_grace_and_short_days_become_half_days(): void
    {
        // Monday 10:30 — past 10:00 + 15 min grace → late. (The Monday is
        // pinned once: parsing "next monday" again after setTestNow would
        // land a week later.)
        $monday = Carbon::parse('next monday');
        Carbon::setTestNow($monday->copy()->setTime(10, 30));

        $in = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/in')->assertCreated();
        $this->assertSame('late', $in->json('data.status'));
        $this->assertNotNull($in->json('data.punch_in'));

        // Punching in twice is refused.
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/in')->assertStatus(422);

        // Out after 3 hours — under the 4.5h half-day line.
        Carbon::setTestNow($monday->copy()->setTime(13, 30));
        $out = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/out')->assertOk();
        $this->assertSame('half_day', $out->json('data.status'));
        $this->assertEquals(3.0, $out->json('data.hours'));

        // On-time punch the next day stays present.
        Carbon::setTestNow($monday->copy()->addDay()->setTime(9, 55));
        $this->assertSame('present', $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/in')->json('data.status'));
    }

    public function test_admin_override_sticks_and_report_summarises(): void
    {
        $monday = Carbon::parse('next monday')->setTime(9, 30);
        Carbon::setTestNow($monday);
        $id = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/punch/in')->json('data.id');

        // Employees cannot override; punch,edit can.
        $this->actingAs($this->employeeUser)->putJson("/api/v1/crm/punch/{$id}", ['status' => 'holiday'])->assertForbidden();
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/punch/{$id}", ['status' => 'holiday', 'note' => 'Festival'])
            ->assertOk()
            ->assertJsonPath('data.status', 'holiday')
            ->assertJsonPath('data.status_source', 'manual');

        // The report is a calendar now — one row per person per day — so it
        // is read a day at a time to be read exactly.
        $day = '?date_from=' . $monday->toDateString() . '&date_to=' . $monday->toDateString();
        $report = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/punch' . $day)->assertOk();
        $this->assertSame(1, collect($report->json('summary.statuses'))->firstWhere('status', 'holiday')['count']);
        $this->assertSame(1, collect($report->json('summary.members'))
            ->firstWhere('name', $this->employeeUser->name)['holiday']);

        // The employee's own window holds only their own days.
        $mine = $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/punch' . $day)->assertOk();
        $this->assertSame(1, $mine->json('total'));
        $this->assertSame($this->employeeUser->name, $mine->json('data.0.member.name'));
        $this->assertSame('holiday', $mine->json('data.0.status'));
    }
}
