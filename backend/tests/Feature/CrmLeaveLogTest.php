<?php

namespace Tests\Feature;

use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Leave Log: every request, and what became of it.
 *
 * Its own right, because reading the record of who was off is a different
 * job from deciding the next one — and its window is the leave list's own,
 * so it cannot become a way of reading other people's leave sideways.
 */
class CrmLeaveLogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);
        [$this->adminUser, $this->admin] = $this->member('admin', 'Jaimin');
    }

    /** A person in the org, with a CRM role and optional module rights. */
    private function member(string $role, string $name, array $rights = []): array
    {
        $user = User::factory()->create(['name' => $name]);
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        $member = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'crm_role' => $role,
            'status' => 'active',
            'rights' => $rights,
        ]);

        return [$user, $member];
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** Someone asks for leave, through the real endpoint. */
    private function request(User $user, array $overrides = [])
    {
        return $this->as($user)->postJson('/api/v1/crm/leaves', array_merge([
            'category' => 'Casual leave',
            'duration' => 'full',
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-02',
            'reason' => 'Family wedding',
        ], $overrides));
    }

    private function log(array $query = [])
    {
        return $this->as($this->adminUser)->getJson('/api/v1/crm/leave-log?' . http_build_query($query));
    }

    // ---- The right ---------------------------------------------------------

    public function test_an_employee_without_the_right_is_refused(): void
    {
        [$staff] = $this->member('employee', 'Riya');

        $this->as($staff)->getJson('/api/v1/crm/leave-log')->assertForbidden();
    }

    public function test_the_right_alone_opens_it(): void
    {
        // Granted the log and nothing else — not even leave approvals.
        [$clerk] = $this->member('employee', 'Nikhil', ['leave_log' => ['view']]);

        $this->as($clerk)->getJson('/api/v1/crm/leave-log')->assertOk();
    }

    public function test_approving_leave_does_not_by_itself_open_the_log(): void
    {
        // The two rights are separate on purpose: deciding the next request
        // is not the same job as reading the record of every past one.
        [$manager] = $this->member('employee', 'Sana', ['leaves' => ['view', 'edit']]);

        $this->as($manager)->getJson('/api/v1/crm/leave-log')->assertForbidden();
    }

    public function test_an_admin_holds_it_by_virtue_of_the_job(): void
    {
        $this->log()->assertOk();
    }

    // ---- What lands on it --------------------------------------------------

    public function test_asking_for_leave_is_recorded(): void
    {
        [$staff] = $this->member('employee', 'Riya');
        $this->request($staff)->assertCreated();

        $this->log()
            ->assertOk()
            ->assertJsonPath('data.0.action', 'leave.requested')
            ->assertJsonPath('data.0.employee', 'Riya')
            ->assertJsonPath('data.0.category', 'Casual leave')
            ->assertJsonPath('data.0.dates', '2026-10-01 → 2026-10-02')
            ->assertJsonPath('data.0.days', 2)
            ->assertJsonPath('data.0.reason', 'Family wedding');
    }

    public function test_a_decision_is_recorded_with_who_made_it(): void
    {
        [$staff] = $this->member('employee', 'Riya');
        $uuid = $this->request($staff)->json('data.uuid');

        $this->as($this->adminUser)
            ->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved', 'note' => 'Enjoy'])
            ->assertOk();

        $this->log()
            ->assertOk()
            ->assertJsonPath('data.0.action', 'leave.approved')
            ->assertJsonPath('data.0.by', 'Jaimin')
            ->assertJsonPath('data.0.employee', 'Riya')
            ->assertJsonPath('data.0.note', 'Enjoy');
    }

    public function test_a_rejection_is_recorded_too(): void
    {
        [$staff] = $this->member('employee', 'Riya');
        $uuid = $this->request($staff)->json('data.uuid');

        $this->as($this->adminUser)
            ->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'rejected', 'note' => 'Quarter end'])
            ->assertOk();

        $this->log()->assertOk()->assertJsonPath('data.0.action', 'leave.rejected');
    }

    public function test_the_whole_life_of_a_request_is_on_it(): void
    {
        [$staff] = $this->member('employee', 'Riya');
        $uuid = $this->request($staff)->json('data.uuid');
        $this->as($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved']);
        $this->as($this->adminUser)->deleteJson("/api/v1/crm/leaves/{$uuid}");

        // Newest first: withdrawn, approved, requested.
        $this->log()
            ->assertOk()
            ->assertJsonPath('data.0.action', 'leave.approval_withdrawn')
            ->assertJsonPath('data.1.action', 'leave.approved')
            ->assertJsonPath('data.2.action', 'leave.requested');
    }

    public function test_the_line_shows_where_the_request_stands_now(): void
    {
        [$staff] = $this->member('employee', 'Riya');
        $uuid = $this->request($staff)->json('data.uuid');
        $this->as($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved']);

        // The asking is still on the log, and still says it was an asking —
        // but it can also say what became of the request since.
        $this->log()
            ->assertOk()
            ->assertJsonPath('data.1.action', 'leave.requested')
            ->assertJsonPath('data.1.leave.status', 'approved');
    }

    // ---- The window --------------------------------------------------------

    public function test_a_team_head_sees_only_their_own_subtree(): void
    {
        [$headUser, $head] = $this->member('employee', 'Vikram', ['leave_log' => ['view']]);
        [$mine, $mineMember] = $this->member('employee', 'Riya');
        [$theirs] = $this->member('employee', 'Arjun');

        $mineMember->update(['reporting_to' => $head->id]);

        $this->request($mine);
        $this->request($theirs);

        $entries = $this->as($headUser)->getJson('/api/v1/crm/leave-log')->assertOk()->json('data');
        $names = array_column($entries, 'employee');

        $this->assertContains('Riya', $names);
        $this->assertNotContains('Arjun', $names);
    }

    public function test_an_admin_sees_everybodys(): void
    {
        [$riya] = $this->member('employee', 'Riya');
        [$arjun] = $this->member('employee', 'Arjun');
        $this->request($riya);
        $this->request($arjun);

        $names = array_column($this->log()->assertOk()->json('data'), 'employee');

        $this->assertContains('Riya', $names);
        $this->assertContains('Arjun', $names);
    }

    public function test_another_organizations_leave_never_appears(): void
    {
        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVAL', 'status' => 'active']);
        $outsider = User::factory()->create(['name' => 'Stranger']);
        $outsider->profile()->create(['timezone' => 'UTC']);
        $outsider->settings()->create([]);
        Member::create([
            'organization_id' => $other->id,
            'user_id' => $outsider->id,
            'crm_role' => 'employee',
            'status' => 'active',
        ]);

        $this->actingAs($outsider)->withHeader('X-Crm-Org', $other->uuid)
            ->postJson('/api/v1/crm/leaves', [
                'category' => 'Sick leave',
                'duration' => 'full',
                'date_from' => '2026-10-01',
                'date_to' => '2026-10-01',
            ])->assertCreated();

        $this->log()->assertOk()->assertJsonPath('summary.total', 0);
    }

    // ---- Filters -----------------------------------------------------------

    public function test_it_filters_by_action(): void
    {
        [$staff] = $this->member('employee', 'Riya');
        $uuid = $this->request($staff)->json('data.uuid');
        $this->as($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved']);

        $entries = $this->log(['action' => 'leave.approved'])->assertOk()->json('data');

        $this->assertCount(1, $entries);
        $this->assertSame('leave.approved', $entries[0]['action']);
    }

    public function test_it_filters_by_whose_leave_it_was(): void
    {
        [$riya] = $this->member('employee', 'Riya');
        [$arjun, $arjunMember] = $this->member('employee', 'Arjun');
        $this->request($riya);
        $this->request($arjun);

        $names = array_column($this->log(['employee' => $arjunMember->uuid])->assertOk()->json('data'), 'employee');

        $this->assertSame(['Arjun'], array_unique($names));
    }

    public function test_it_filters_by_who_acted(): void
    {
        [$riya] = $this->member('employee', 'Riya');
        $uuid = $this->request($riya)->json('data.uuid');
        $this->as($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved']);

        // The admin decided; Riya only asked.
        $actions = array_column($this->log(['member' => $this->admin->uuid])->assertOk()->json('data'), 'action');

        $this->assertSame(['leave.approved'], $actions);
    }

    public function test_it_searches_the_payload(): void
    {
        [$riya] = $this->member('employee', 'Riya');
        [$arjun] = $this->member('employee', 'Arjun');
        $this->request($riya, ['reason' => 'Family wedding']);
        $this->request($arjun, ['reason' => 'Hospital visit']);

        $entries = $this->log(['search' => 'wedding'])->assertOk()->json('data');

        $this->assertCount(1, $entries);
        $this->assertSame('Riya', $entries[0]['employee']);
    }

    public function test_it_filters_by_date(): void
    {
        [$riya] = $this->member('employee', 'Riya');
        $this->request($riya);

        $this->log(['date_from' => now()->addDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('summary.total', 0);

        $this->log(['date_from' => now()->subDay()->toDateString()])
            ->assertOk()
            ->assertJsonPath('summary.total', 1);
    }

    // ---- The summary -------------------------------------------------------

    public function test_the_summary_counts_days_actually_taken(): void
    {
        [$riya] = $this->member('employee', 'Riya');
        [$arjun] = $this->member('employee', 'Arjun');

        $a = $this->request($riya, ['date_from' => '2026-10-01', 'date_to' => '2026-10-02'])->json('data.uuid');
        $b = $this->request($arjun, ['date_from' => '2026-11-01', 'date_to' => '2026-11-01'])->json('data.uuid');

        $this->as($this->adminUser)->postJson("/api/v1/crm/leaves/{$a}/decide", ['status' => 'approved']);
        $this->as($this->adminUser)->postJson("/api/v1/crm/leaves/{$b}/decide", ['status' => 'rejected']);

        $summary = $this->log()->assertOk()->json('summary');

        // Two approved days; the rejected request contributes none.
        $this->assertEqualsWithDelta(2, $summary['approved_days'], 0.001);
        $this->assertSame([['employee' => 'Riya', 'days' => 2, 'count' => 1]], $summary['by_employee']);
    }

    public function test_the_summary_offers_the_actions_actually_recorded(): void
    {
        [$riya] = $this->member('employee', 'Riya');
        $uuid = $this->request($riya)->json('data.uuid');
        $this->as($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved']);

        $this->log()
            ->assertOk()
            ->assertJsonPath('summary.actions', ['leave.approved', 'leave.requested'])
            ->assertJsonPath('summary.total', 2);
    }

    public function test_an_empty_log_still_answers(): void
    {
        $this->log()
            ->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('summary.approved_days', 0)
            ->assertJsonPath('data', []);
    }

    // ---- The right is grantable at all -------------------------------------

    public function test_the_new_right_is_offered_on_the_rights_screen(): void
    {
        $this->as($this->adminUser)
            ->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->assertJsonPath('data.module_labels.leave_log', 'Leave log')
            ->assertJsonPath('data.module_labels.leaves', 'Leave approvals');
    }

    public function test_leave_approval_rights_can_be_granted_to_an_employee(): void
    {
        // The first half of what an Admin needs: hand the module right to a
        // named employee and they can decide somebody else's request.
        [$manager, $managerMember] = $this->member('employee', 'Sana');
        [$riya] = $this->member('employee', 'Riya');
        $uuid = $this->request($riya)->json('data.uuid');

        $this->as($manager)
            ->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])
            ->assertForbidden();

        $managerMember->update(['rights' => ['leaves' => ['view', 'edit']]]);

        $this->as($manager)
            ->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])
            ->assertOk();

        $this->assertSame('approved', Leave::where('uuid', $uuid)->first()->status);
    }
}
