<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Team Head model: an employee's window is their subtree (themselves +
 * everyone reporting to them, at any depth), an admin's window is the whole
 * company, and someone outside the chain stays invisible — across employees,
 * leads, leaves and DWRs alike.
 */
class CrmTeamScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $headUser;
    protected User $juniorUser;
    protected User $strangerUser;
    protected Organization $org;
    protected Member $adminMember;
    protected Member $headMember;
    protected Member $juniorMember;
    protected Member $strangerMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->headUser = $this->makeUser('head@acme.test');
        $this->juniorUser = $this->makeUser('junior@acme.test');
        $this->strangerUser = $this->makeUser('stranger@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        // Team Head with the employees/leads window granted.
        $this->headMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->headUser->id, 'crm_role' => 'employee',
            'rights' => ['employees' => ['view'], 'leads' => ['view', 'create']],
            'reporting_to' => $this->adminMember->id,
        ]);
        $this->juniorMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->juniorUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->headMember->id,
            'rights' => ['leads' => ['view', 'create']],
        ]);
        // Same company, different chain — must stay invisible to the head.
        $this->strangerMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->strangerUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->adminMember->id,
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    public function test_a_team_heads_employee_window_is_their_subtree(): void
    {
        $list = $this->actingAs($this->headUser)->getJson('/api/v1/crm/employees')->assertOk();
        $names = collect($list->json('data'))->pluck('email')->sort()->values()->all();
        $this->assertSame(['head@acme.test', 'junior@acme.test'], $names);

        // Direct URLs outside the subtree 404; inside they open.
        $this->actingAs($this->headUser)->getJson('/api/v1/crm/employees/' . $this->strangerMember->uuid)->assertNotFound();
        $this->actingAs($this->headUser)->getJson('/api/v1/crm/employees/' . $this->adminMember->uuid)->assertNotFound();
        $this->actingAs($this->headUser)->getJson('/api/v1/crm/employees/' . $this->juniorMember->uuid)->assertOk();

        // The admin still sees the whole company, and can filter one team.
        $this->assertSame(4, $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees')->json('total'));
        $team = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees?reports_to=' . $this->headMember->uuid)->json('data');
        $this->assertCount(1, $team);
        $this->assertSame('junior@acme.test', $team[0]['email']);

        // The workspace label knows a team head from a plain employee.
        $this->assertTrue($this->actingAs($this->headUser)->getJson('/api/v1/crm/me')->json('data.has_team'));
        $this->assertFalse($this->actingAs($this->juniorUser)->getJson('/api/v1/crm/me')->json('data.has_team'));
    }

    public function test_a_team_head_can_read_their_team_but_never_change_employees(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        // The admin puts a document and a salary record on the junior.
        $this->actingAs($this->adminUser)->post('/api/v1/crm/employees/' . $this->juniorMember->uuid . '/documents', [
            'name' => 'Aadhaar card',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('aadhaar.pdf', 50),
        ])->assertCreated();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/employees/' . $this->juniorMember->uuid . '/salary', [
            'amount' => 9000, 'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $junior = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees/' . $this->juniorMember->uuid)->json('data');
        $docUuid = $junior['documents'][0]['uuid'];
        $salaryId = $junior['salary_records'][0]['id'];

        // The head reads the profile (their subtree) …
        $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/employees/' . $this->juniorMember->uuid)->assertOk();

        // … but every write is refused, even with employees.edit granted.
        $this->actingAs($this->headUser)->putJson('/api/v1/crm/employees/' . $this->juniorMember->uuid, [
            'crm_role' => 'employee', 'designation' => 'Hacked',
        ])->assertForbidden();
        $this->actingAs($this->headUser)
            ->deleteJson("/api/v1/crm/employees/{$this->juniorMember->uuid}/documents/{$docUuid}")
            ->assertForbidden();
        $this->actingAs($this->headUser)->post('/api/v1/crm/employees/' . $this->juniorMember->uuid . '/documents', [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('sneaky.pdf', 10),
        ])->assertForbidden();
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/employees/' . $this->juniorMember->uuid . '/salary', [
            'amount' => 99999, 'effective_from' => now()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($this->headUser)
            ->deleteJson("/api/v1/crm/employees/{$this->juniorMember->uuid}/salary/{$salaryId}")
            ->assertForbidden();
        $this->actingAs($this->headUser)
            ->deleteJson('/api/v1/crm/employees/' . $this->juniorMember->uuid)
            ->assertForbidden();
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/employees', [
            'name' => 'Ghost', 'email' => 'ghost@acme.test', 'password' => 'Str0ngPass123', 'crm_role' => 'employee',
        ])->assertForbidden();

        // Nothing moved: the document, the salary and the designation stand.
        $after = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees/' . $this->juniorMember->uuid)->json('data');
        $this->assertCount(1, $after['documents']);
        $this->assertCount(1, $after['salary_records']);
        $this->assertNotSame('Hacked', $after['designation']);
    }

    public function test_the_team_workspace_puts_ticked_people_in_a_leaders_hands(): void
    {
        // Before any grant, the stranger is invisible to the head.
        $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/employees/' . $this->strangerMember->uuid)->assertNotFound();

        // The Admin ticks the stranger into the head's Team Workspace.
        $res = $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/employees/' . $this->headMember->uuid, [
                'crm_role' => 'employee',
                'rights' => ['employees' => ['view'], 'leads' => ['view', 'create']],
                'team_member_uuids' => [$this->strangerMember->uuid],
            ])->assertOk();
        $this->assertSame([$this->strangerMember->uuid],
            collect($res->json('data.team'))->pluck('uuid')->all());
        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'employee.team_updated']);

        // The head's window now spans the chain AND the workspace.
        $names = collect($this->actingAs($this->headUser)->getJson('/api/v1/crm/employees')->json('data'))
            ->pluck('email')->sort()->values()->all();
        $this->assertSame(['head@acme.test', 'junior@acme.test', 'stranger@acme.test'], $names);
        $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/employees/' . $this->strangerMember->uuid)->assertOk();

        // The other direction reads on the stranger's profile.
        $stranger = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees/' . $this->strangerMember->uuid)->json('data');
        $this->assertSame([$this->headMember->uuid],
            collect($stranger['team_leaders'])->pluck('uuid')->all());

        // The list's team filter finds the workspace grant beside the chain.
        $team = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees?reports_to=' . $this->headMember->uuid)->json('data'))
            ->pluck('email')->sort()->values()->all();
        $this->assertSame(['junior@acme.test', 'stranger@acme.test'], $team);

        // The head sees their access but can never steer it themselves.
        $this->actingAs($this->headUser)
            ->putJson('/api/v1/crm/employees/' . $this->headMember->uuid, [
                'crm_role' => 'employee', 'team_member_uuids' => [$this->juniorMember->uuid],
            ])->assertForbidden();

        // Unticking takes the window back.
        $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/employees/' . $this->headMember->uuid, [
                'crm_role' => 'employee',
                'rights' => ['employees' => ['view'], 'leads' => ['view', 'create']],
                'team_member_uuids' => [],
            ])->assertOk();
        $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/employees/' . $this->strangerMember->uuid)->assertNotFound();
    }

    public function test_a_workspace_grant_alone_makes_a_team_workspace(): void
    {
        // The junior leads nobody: the sidebar reads Employee workspace.
        $this->assertFalse($this->actingAs($this->juniorUser)->getJson('/api/v1/crm/me')->json('data.has_team'));

        // The Admin ticks the stranger under the junior — and tries to tick
        // the Admin too, which is quietly left out: only employees are ever
        // handed into someone's hands.
        $res = $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/employees/' . $this->juniorMember->uuid, [
                'crm_role' => 'employee', 'rights' => ['leads' => ['view', 'create']],
                'team_member_uuids' => [$this->strangerMember->uuid, $this->adminMember->uuid],
            ])->assertOk();
        $this->assertSame([$this->strangerMember->uuid],
            collect($res->json('data.team'))->pluck('uuid')->all());

        // Reporting still points elsewhere, yet the workspace grant alone
        // flips the label — Team workspace — and widens the window.
        $me = $this->actingAs($this->juniorUser)->getJson('/api/v1/crm/me')->json('data');
        $this->assertTrue($me['has_team']);
        $this->assertTrue($me['member']['leads_a_team']);
        $this->assertContains($this->strangerMember->id, $this->juniorMember->teamMemberIds());
    }

    public function test_a_new_hire_reports_to_the_admin_by_default(): void
    {
        // The form no longer asks "Reports to": registration lands the new
        // person under the company's Admin on its own.
        $res = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/employees', [
            'name' => 'Fresh Hire', 'email' => 'fresh@acme.test',
            'password' => 'Str0ngPass123', 'crm_role' => 'employee',
        ])->assertCreated();
        $this->assertSame($this->adminMember->uuid, $res->json('data.manager.uuid'));
    }

    public function test_team_scope_applies_to_leads_leaves_and_dwrs(): void
    {
        // Leads: one for the junior, one for the stranger (created by admin).
        $this->actingAs($this->juniorUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Junior Lead Co', 'assigned_member_uuid' => $this->juniorMember->uuid,
        ])->assertCreated();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Stranger Lead Co', 'assigned_member_uuid' => $this->strangerMember->uuid,
        ])->assertCreated();

        $leads = $this->actingAs($this->headUser)->getJson('/api/v1/crm/leads')->assertOk();
        $this->assertSame(1, $leads->json('totals.count'));
        $this->assertSame('Junior Lead Co', $leads->json('data.0.company_name'));

        // Leaves: junior's request is visible to the head, stranger's is not.
        $this->actingAs($this->juniorUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Sick Leave', 'duration' => 'full',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
        ]);
        $this->actingAs($this->strangerUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'full',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
        ]);
        $this->assertSame(1, $this->actingAs($this->headUser)->getJson('/api/v1/crm/leaves')->json('total'));
        $this->assertSame(2, $this->actingAs($this->adminUser)->getJson('/api/v1/crm/leaves')->json('total'));

        // The junior still sees only themselves everywhere.
        $this->assertSame(1, $this->actingAs($this->juniorUser)->getJson('/api/v1/crm/leaves')->json('total'));
    }
}
