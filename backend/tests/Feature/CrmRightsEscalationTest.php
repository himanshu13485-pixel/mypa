<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Subadmin cannot promote themselves, and cannot tick their own rights.
 *
 * The employee screen is company authority — registering staff, editing their
 * profile — and it is open to Subadmins, who run teams. But module rights and
 * the special-permissions list are what decide what a person may do, so a
 * screen that let a Subadmin edit their own row let them grant themselves
 * anything on it. Editing a peer's row was the same thing one step removed,
 * and crm_role rode in the same payload, so the shortest route of all was to
 * make yourself an Admin and stop needing any of the ticks.
 *
 * None of that took a bug. It was simply never restricted, which is the kind
 * of hole that never appears in a bug report because nothing looks wrong.
 */
class CrmRightsEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Organization $org;
    private User $sub;
    private Member $subMember;
    private Member $employeeMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $this->admin = $this->makeUser('boss@acme.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->admin->id, 'crm_role' => 'admin',
        ]);

        $this->sub = $this->makeUser('sub@acme.test');
        $this->subMember = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->sub->id,
            'crm_role' => 'subadmin',
            'rights' => ['employees' => ['view', 'edit']],
        ]);

        $this->employeeMember = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->makeUser('worker@acme.test')->id,
            'crm_role' => 'employee',
            'employee_code' => 'EMP-101',
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function edit(User $as, Member $target, array $payload)
    {
        return $this->actingAs($as)->putJson(
            "/api/v1/crm/employees/{$target->uuid}",
            $payload + ['status' => 'active'],
        );
    }

    public function test_a_subadmin_cannot_tick_their_own_rights(): void
    {
        $this->edit($this->sub, $this->subMember, [
            'rights' => ['salary' => ['view', 'edit'], 'reports' => ['view']],
            'capabilities' => ['exports.excel', 'reports.view'],
        ])->assertOk();

        // The save succeeds — the rest of the profile is theirs to edit — and
        // the two fields that decide what they may do are simply not in it.
        $fresh = $this->subMember->fresh();
        $this->assertSame([], (array) ($fresh->rights['salary'] ?? []));
        $this->assertSame([], (array) $fresh->capabilities);
    }

    public function test_a_subadmin_cannot_tick_a_peers_rights_either(): void
    {
        // The same grant, one step removed: a Subadmin who can write another
        // Subadmin's rights can write their own through them.
        $peer = $this->makeUser('peer@acme.test');
        $peerMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $peer->id, 'crm_role' => 'subadmin',
        ]);

        $this->edit($this->sub, $peerMember, [
            'rights' => ['salary' => ['view', 'edit']],
            'capabilities' => ['exports.excel'],
        ])->assertOk();

        $this->assertSame([], (array) ($peerMember->fresh()->rights['salary'] ?? []));
        $this->assertSame([], (array) $peerMember->fresh()->capabilities);
    }

    public function test_a_subadmin_cannot_promote_anybody_including_themselves(): void
    {
        /*
         * The shortest route, and the one that made every other restriction
         * here decorative: never mind the ticks, become an Admin instead.
         */
        $this->edit($this->sub, $this->subMember, ['crm_role' => 'admin'])->assertOk();
        $this->assertSame('subadmin', $this->subMember->fresh()->crm_role);

        $this->edit($this->sub, $this->employeeMember, ['crm_role' => 'subadmin'])->assertOk();
        $this->assertSame('employee', $this->employeeMember->fresh()->crm_role);
    }

    public function test_a_subadmin_registering_staff_creates_an_employee(): void
    {
        // The same door from the other side: a Subadmin who could choose the
        // role of somebody they were creating could create an Admin.
        $this->actingAs($this->sub)->postJson('/api/v1/crm/employees', [
            'name' => 'New Hire',
            'email' => 'hire@acme.test',
            'password' => 'Password123',
            'crm_role' => 'admin',
            'status' => 'active',
            'rights' => ['salary' => ['view', 'edit']],
            'capabilities' => ['exports.excel'],
        ])->assertCreated();

        $made = Member::whereHas('user', fn ($u) => $u->where('email', 'hire@acme.test'))->firstOrFail();

        $this->assertSame('employee', $made->crm_role);
        $this->assertSame([], (array) ($made->rights['salary'] ?? []));
        $this->assertSame([], (array) $made->capabilities);
    }

    public function test_the_admin_sets_all_of_it(): void
    {
        $this->edit($this->admin, $this->employeeMember, [
            'crm_role' => 'subadmin',
            'rights' => ['salary' => ['view']],
            'capabilities' => ['exports.excel'],
        ])->assertOk();

        $fresh = $this->employeeMember->fresh();
        $this->assertSame('subadmin', $fresh->crm_role);
        $this->assertSame(['view'], (array) $fresh->rights['salary']);
        $this->assertSame(['exports.excel'], (array) $fresh->capabilities);
    }

    public function test_a_named_subadmin_may_set_an_employees_rights_and_no_one_elses(): void
    {
        // The Admin hands it over by name — the one way a Subadmin gets it.
        $this->edit($this->admin, $this->subMember, [
            'crm_role' => 'subadmin',
            'capabilities' => ['employees.rights'],
        ])->assertOk();
        $this->assertSame(['employees.rights'], (array) $this->subMember->fresh()->capabilities);

        // Now they may, for an employee.
        $this->edit($this->sub, $this->employeeMember, [
            'rights' => ['leads' => ['view', 'edit']],
        ])->assertOk();
        $this->assertSame(['view', 'edit'], (array) $this->employeeMember->fresh()->rights['leads']);

        // And still not for themselves, which is the point of the whole thing:
        // the grant points downwards or it is not a grant, it is a loophole.
        $this->edit($this->sub, $this->subMember, [
            'rights' => ['salary' => ['view', 'edit']],
            'capabilities' => ['exports.excel', 'employees.rights'],
        ])->assertOk();

        $fresh = $this->subMember->fresh();
        $this->assertSame([], (array) ($fresh->rights['salary'] ?? []));
        $this->assertSame(['employees.rights'], (array) $fresh->capabilities);
    }

    public function test_the_screen_is_told_whether_to_offer_the_editor(): void
    {
        $this->actingAs($this->sub)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.can_set_rights', false);

        $this->edit($this->admin, $this->subMember, [
            'crm_role' => 'subadmin',
            'capabilities' => ['employees.rights'],
        ])->assertOk();

        $this->actingAs($this->sub)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.can_set_rights', true);

        $this->actingAs($this->admin)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.can_set_rights', true);
    }
}
