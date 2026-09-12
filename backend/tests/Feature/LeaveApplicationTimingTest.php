<?php

namespace Tests\Feature;

use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Asked for before the leave, or after it was taken.
 *
 * The question an approver is asking is "did I get a say before this
 * happened". So a leave applied on a day strictly before its first day is
 * pre-applied, and one applied on the day itself or later is post-applied -
 * on that morning, nobody got a say.
 */
class LeaveApplicationTimingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected Member $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin']);

        $staffUser = User::factory()->create(['email' => 'priyanshu@acme.test']);
        $staffUser->settings()->create([]);
        $staffUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->staff = Member::create(['organization_id' => $this->org->id, 'user_id' => $staffUser->id, 'crm_role' => 'employee']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A leave for $from, applied at $appliedAt. */
    private function leave(string $appliedAt, string $from, string $reason): void
    {
        Carbon::setTestNow($appliedAt);

        Leave::create([
            'organization_id' => $this->org->id,
            'member_id' => $this->staff->id,
            'category' => 'Sick Leave',
            'duration' => 'full',
            'date_from' => $from,
            'date_to' => $from,
            'days' => 1,
            'paid_days' => 0,
            'unpaid_days' => 1,
            'reason' => $reason,
            'status' => 'pending',
        ]);

        Carbon::setTestNow();
    }

    public function test_each_leave_says_whether_it_was_asked_for_in_advance(): void
    {
        $this->leave('2026-09-01 10:00:00', '2026-09-08', 'planned trip');   // a week ahead
        $this->leave('2026-09-08 09:30:00', '2026-09-08', 'fever this morning'); // same day
        $this->leave('2026-09-10 11:00:00', '2026-09-08', 'forgot to apply');    // after

        $all = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/leaves')->assertOk()->json('data'))
            ->keyBy('reason');

        $this->assertSame('pre', $all['planned trip']['applied_timing']);
        // The morning of the day itself is after the fact.
        $this->assertSame('post', $all['fever this morning']['applied_timing']);
        $this->assertSame('post', $all['forgot to apply']['applied_timing']);
    }

    public function test_the_list_filters_by_when_it_was_asked_for(): void
    {
        $this->leave('2026-09-01 10:00:00', '2026-09-08', 'planned trip');
        $this->leave('2026-09-10 11:00:00', '2026-09-08', 'forgot to apply');

        $pre = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/leaves?timing=pre')->assertOk();
        $this->assertSame(['planned trip'], collect($pre->json('data'))->pluck('reason')->all());

        $post = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/leaves?timing=post')->assertOk();
        $this->assertSame(['forgot to apply'], collect($post->json('data'))->pluck('reason')->all());
    }
}
