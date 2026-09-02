<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A company admin sitting in one of their staff's seats.
 *
 * Signing in as somebody else is the most powerful thing in the product, and
 * the account being borrowed is not an employee record — it is a person's
 * Netvork, with their private notes, files and messages in it. So almost
 * everything here is about what the borrowed session CANNOT do.
 *
 * The grant is the platform's to give. A company cannot switch this on for
 * itself, cannot widen what it was given, and gets nothing at all by default.
 */
class CrmImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $admin;
    private User $employee;
    private Organization $org;
    private Member $employeeMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeUser('root@netvork.test');
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $this->admin = $this->makeUser('boss@acme.test');
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->admin->id,
            'crm_role' => 'admin',
        ]);

        $this->employee = $this->makeUser('worker@acme.test');
        $this->employeeMember = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->employee->id,
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

    private function grant(string $level): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/admin/crm/organizations/{$this->org->uuid}", ['impersonation_level' => $level])
            ->assertOk();

        $this->org->refresh();
    }

    /** Take the seat, and hand back the token that holds it. */
    private function borrow(?string $uuid = null): string
    {
        return $this->actingAs($this->admin)
            ->postJson('/api/v1/crm/employees/' . ($uuid ?? $this->employeeMember->uuid) . '/impersonate')
            ->assertOk()
            ->json('data.token');
    }

    /**
     * A request made by the borrowed session and nobody else.
     *
     * forgetGuards() first, and it is the whole point of this helper. Taking
     * the seat needs actingAs($admin), and actingAs leaves that user resolved
     * on the guard for every request afterwards — so a bearer header added on
     * top is simply never reached, and each of these calls would quietly go on
     * being the admin. Every assertion below would then be testing the admin's
     * own session while appearing to test the borrowed one, and the ones about
     * what a borrowed session cannot do would pass by never having been in one.
     */
    private function asBorrowed(string $token, string $method, string $path, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->json($method, $path, $body);
    }

    // --- The grant ----------------------------------------------------------

    public function test_a_company_gets_nothing_until_the_platform_says_so(): void
    {
        // Not merely refused — not offered. The screen has no button to press.
        $this->actingAs($this->admin)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.impersonation_level', null);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/crm/employees/{$this->employeeMember->uuid}/impersonate")
            ->assertForbidden();
    }

    public function test_a_company_cannot_grant_it_to_itself(): void
    {
        // The route is the super admin's. An org admin is not one, whatever
        // they are inside their own company — which is the whole reason the
        // level lives on the organization and not in company settings, where
        // the person it restrains would be the person editing it.
        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/crm/organizations/{$this->org->uuid}", ['impersonation_level' => 'account'])
            ->assertForbidden();

        $this->assertSame('none', $this->org->fresh()->impersonation_level);
    }

    public function test_the_granted_level_is_what_the_admin_is_told_they_have(): void
    {
        $this->grant('crm_read');

        $this->actingAs($this->admin)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.impersonation_level', 'crm_read');
    }

    // --- Who may be borrowed ------------------------------------------------

    public function test_it_never_reaches_sideways_or_upwards(): void
    {
        $this->grant('crm');

        // Another admin: sideways, and refused.
        $peer = $this->makeUser('peer@acme.test');
        $peerMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $peer->id, 'crm_role' => 'admin',
        ]);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/crm/employees/{$peerMember->uuid}/impersonate")
            ->assertForbidden();

        // An employee who also holds Netvork's own roles: borrowing them
        // would turn a company login into the platform's admin panel.
        $staff = $this->makeUser('staff@acme.test');
        $staff->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $staffMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $staff->id, 'crm_role' => 'employee',
        ]);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/crm/employees/{$staffMember->uuid}/impersonate")
            ->assertForbidden();

        // And somebody else's company entirely.
        $rival = Organization::create(['name' => 'Rival Corp', 'code' => 'RIVAL', 'impersonation_level' => 'account']);
        $theirs = $this->makeUser('theirs@rival.test');
        $theirMember = Member::create([
            'organization_id' => $rival->id, 'user_id' => $theirs->id, 'crm_role' => 'employee',
        ]);
        $this->actingAs($this->admin)
            ->postJson("/api/v1/crm/employees/{$theirMember->uuid}/impersonate")
            ->assertNotFound();
    }

    public function test_a_subadmin_cannot_open_anybody(): void
    {
        $this->grant('account');

        $sub = $this->makeUser('sub@acme.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $sub->id, 'crm_role' => 'subadmin',
        ]);

        $this->actingAs($sub)
            ->postJson("/api/v1/crm/employees/{$this->employeeMember->uuid}/impersonate")
            ->assertForbidden();
    }

    // --- What the borrowed session may reach --------------------------------

    public function test_a_crm_seat_opens_the_workspace_and_nothing_else(): void
    {
        $this->grant('crm');
        $token = $this->borrow();

        // It really is them: the CRM answers as the employee.
        $this->asBorrowed($token, 'get', '/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.employee_code', 'EMP-101');

        // Their private Netvork is shut. These are the reason the scope is an
        // allow-list: notes, files and messages are exactly the things a
        // deny-list forgets.
        $this->asBorrowed($token, 'get', '/api/v1/notes')->assertForbidden();
        $this->asBorrowed($token, 'get', '/api/v1/files')->assertForbidden();
        $this->asBorrowed($token, 'get', '/api/v1/conversations')->assertForbidden();
        $this->asBorrowed($token, 'get', '/api/v1/connections')->assertForbidden();

        // The shell still needs to know whose face to draw.
        $this->asBorrowed($token, 'get', '/api/v1/me')->assertOk();
    }

    public function test_a_read_only_seat_can_look_but_not_touch(): void
    {
        $this->grant('crm_read');
        $token = $this->borrow();

        $this->asBorrowed($token, 'get', '/api/v1/crm/me')->assertOk();

        // Same workspace, and every way of changing it refused — by the
        // scope, which the message has to confirm: a lead the employee had
        // no right to create would be refused with a 403 as well, and the
        // test would pass without the read-only rail existing at all.
        $this->asBorrowed($token, 'post', '/api/v1/crm/leads', ['name' => 'Anything'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This workspace was opened to look at, not to work in.');
    }

    public function test_the_widest_seat_still_cannot_take_the_account(): void
    {
        $this->grant('account');
        $token = $this->borrow();

        // 'account' is the whole of Netvork, so the personal side opens.
        $this->asBorrowed($token, 'get', '/api/v1/notes')->assertOk();

        /*
         * And these are still refused, because they are not about privacy —
         * they are about the borrower being unable to keep the seat. A
         * changed password locks the owner out of their own account; a
         * changed e-mail sends every future sign-in code to the borrower.
         */
        $this->asBorrowed($token, 'post', '/api/v1/auth/change-password', [
            'current_password' => 'Password123', 'password' => 'Newpassword123', 'password_confirmation' => 'Newpassword123',
        ])->assertForbidden();

        $this->asBorrowed($token, 'get', '/api/v1/auth/sessions')->assertForbidden();
        $this->asBorrowed($token, 'delete', '/api/v1/me')->assertForbidden();
        $this->asBorrowed($token, 'post', '/api/v1/subscription/checkout')->assertForbidden();
    }

    public function test_a_borrowed_seat_cannot_borrow_another(): void
    {
        $this->grant('account');

        $second = $this->makeUser('second@acme.test');
        $secondMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $second->id, 'crm_role' => 'employee',
        ]);

        $token = $this->borrow();

        // Chaining would end the audit trail at the first hop — and the
        // employee is not an admin anyway, so this is belt and braces.
        $this->asBorrowed($token, 'post', "/api/v1/crm/employees/{$secondMember->uuid}/impersonate")
            ->assertForbidden();
    }

    public function test_a_crm_seat_cannot_listen_to_the_owners_private_channel(): void
    {
        /*
         * The side door. Channel authorisation is registered outside the big
         * route group with middleware of its own, so a scope enforced only
         * there would have left a CRM-only session able to subscribe to the
         * borrowed account's private channel and read their messages arriving
         * live. It is why the scope is global rather than per-group.
         */
        $this->grant('crm');
        $token = $this->borrow();

        $this->asBorrowed($token, 'post', '/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-user.' . $this->employee->uuid,
        ])->assertForbidden();
    }

    // --- Giving it back -----------------------------------------------------

    public function test_handing_the_seat_back_destroys_the_token(): void
    {
        $this->grant('crm');
        $token = $this->borrow();

        $this->asBorrowed($token, 'post', '/api/v1/impersonation/stop')->assertOk();

        // Not merely forgotten by the browser: a token the client has dropped
        // is still a token, valid and belonging to nobody who remembers it.
        $this->asBorrowed($token, 'get', '/api/v1/crm/me')->assertUnauthorized();

        // The admin's own session was never touched — they held it throughout.
        $this->actingAs($this->admin)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.crm_role', 'admin');
    }

    public function test_an_ordinary_session_cannot_pretend_to_be_a_borrowed_one(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/impersonation/stop')
            ->assertStatus(422);
    }

    public function test_both_ends_are_written_down(): void
    {
        $this->grant('crm');
        $token = $this->borrow();
        $this->asBorrowed($token, 'post', '/api/v1/impersonation/stop')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->admin->id,
            'action' => 'crm.impersonation.started',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'crm.impersonation.ended',
        ]);
        // The grant itself too: who widened it, and to what.
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->superAdmin->id,
            'action' => 'crm.impersonation.granted',
        ]);
    }

    public function test_the_list_says_whose_seat_may_be_taken(): void
    {
        $this->grant('crm');

        $rows = $this->actingAs($this->admin)
            ->getJson('/api/v1/crm/employees')
            ->assertOk()
            ->json('data');

        $byName = collect($rows)->keyBy('email');

        $this->assertTrue($byName['worker@acme.test']['can_impersonate']);
        // Not their own row: an admin is already signed in as themselves.
        $this->assertFalse($byName['boss@acme.test']['can_impersonate']);
    }
}
