<?php

namespace Tests\Feature;

use App\Models\Crm\Approval;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\Punch;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The company as it is right now.
 *
 * The dashboard answers how the month is going. None of it answers what an
 * admin opens the app asking on a Tuesday: who is here, and what is running.
 */
class CrmOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private User $staffUser;
    private Member $admin;
    private Member $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        [$this->adminUser, $this->admin] = $this->member('admin');
        [$this->staffUser, $this->staff] = $this->member('employee');
    }

    private function member(string $role): array
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        $member = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'crm_role' => $role,
            'status' => 'active',
        ]);

        return [$user, $member];
    }

    private function overview(?User $as = null)
    {
        // The header takes the organization's uuid; without it the first
        // membership wins, which is the only one anybody here has.
        return $this->actingAs($as ?? $this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson('/api/v1/crm/overview');
    }

    public function test_an_employee_cannot_see_the_company_wide_view(): void
    {
        $this->overview($this->staffUser)->assertForbidden();
    }

    public function test_it_says_who_is_online(): void
    {
        // forceFill: last_active_at is not fillable, and update() would
        // drop it without a word.
        $this->staffUser->forceFill(['last_active_at' => now()])->save();
        $this->adminUser->forceFill(['last_active_at' => now()->subHours(3)])->save();

        $members = $this->overview()->assertOk()->json('data.members');

        $staff = collect($members)->firstWhere('uuid', $this->staff->uuid);
        $admin = collect($members)->firstWhere('uuid', $this->admin->uuid);

        $this->assertTrue($staff['online']);
        $this->assertFalse($admin['online']);

        // Whoever is here leads: an admin opening this is looking for
        // somebody who can answer.
        $this->assertSame($this->staff->uuid, $members[0]['uuid']);
    }

    /**
     * Punched in and online are different facts.
     *
     * Somebody can be punched in and out on a site visit, or online at nine
     * on a day they never punched.
     */
    public function test_punched_in_is_reported_separately_from_online(): void
    {
        Punch::create([
            'organization_id' => $this->org->id,
            'member_id' => $this->staff->id,
            'work_date' => now()->toDateString(),
            'punch_in' => now()->subHours(2),
        ]);

        $members = $this->overview()->assertOk()->json('data.members');
        $staff = collect($members)->firstWhere('uuid', $this->staff->uuid);

        $this->assertTrue($staff['punched_in']);
        $this->assertFalse($staff['online']);
    }

    public function test_somebody_who_punched_out_is_no_longer_in(): void
    {
        Punch::create([
            'organization_id' => $this->org->id,
            'member_id' => $this->staff->id,
            'work_date' => now()->toDateString(),
            'punch_in' => now()->subHours(9),
            'punch_out' => now()->subHour(),
        ]);

        $members = $this->overview()->assertOk()->json('data.members');
        $this->assertFalse(collect($members)->firstWhere('uuid', $this->staff->uuid)['punched_in']);
    }

    public function test_it_lists_meetings_that_are_actually_running(): void
    {
        $live = Meeting::create([
            'host_id' => $this->staffUser->id,
            'code' => Meeting::generateCode(),
            'title' => 'Standup',
            'type' => 'video',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
        ]);

        // A diary entry is not a meeting anybody walked into.
        Meeting::create([
            'host_id' => $this->staffUser->id,
            'code' => Meeting::generateCode(),
            'title' => 'Later today',
            'type' => 'video',
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]);

        // Nor is one that finished.
        Meeting::create([
            'host_id' => $this->staffUser->id,
            'code' => Meeting::generateCode(),
            'title' => 'This morning',
            'type' => 'video',
            'status' => 'ended',
            'ended_at' => now()->subHour(),
        ]);

        $meetings = $this->overview()->assertOk()->json('data.meetings');

        $this->assertCount(1, $meetings);
        $this->assertSame('Standup', $meetings[0]['title']);
        $this->assertSame($live->code, $meetings[0]['code']);
    }

    /** Another company's meeting is not this company's business. */
    public function test_a_meeting_hosted_outside_the_company_is_not_listed(): void
    {
        $stranger = User::factory()->create();
        $stranger->profile()->create(['timezone' => 'UTC']);

        Meeting::create([
            'host_id' => $stranger->id,
            'code' => Meeting::generateCode(),
            'title' => 'Somebody else',
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->assertCount(0, $this->overview()->assertOk()->json('data.meetings'));
    }

    public function test_the_standing_numbers_add_up(): void
    {
        Approval::create([
            'organization_id' => $this->org->id,
            'requested_by' => $this->staff->id,
            'type' => 'expense',
            'approval_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $numbers = $this->overview()->assertOk()->json('data.overview');

        $this->assertSame(2, $numbers['members_total']);
        $this->assertSame(2, $numbers['members_active']);
        $this->assertSame(1, $numbers['approvals_pending']);
        $this->assertSame(0, $numbers['punched_in_today']);
    }
}
