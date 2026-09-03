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

    public function test_a_subadmin_cannot_lend_a_seat_to_themselves(): void
    {
        /*
         * The escalation this field exists to avoid.
         *
         * The employee screen is open to Subadmins — they register staff and
         * run their teams — so a Subadmin can edit another Subadmin and can
         * edit themselves. Anything grantable there is therefore a right they
         * could hand themselves in two clicks, which is exactly why this is
         * not one of the tick-box capabilities.
         */
        $this->grant('account');
        [$sub, $subMember] = $this->subadmin('sub@acme.test');
        [$peer, $peerMember] = $this->subadmin('peer@acme.test');

        // Themselves: the field is dropped, and the save otherwise succeeds.
        $this->lend($subMember, 'account', $sub)->assertOk();
        $this->assertNull($subMember->fresh()->impersonation_level);

        // Nor onto each other.
        $this->lend($peerMember, 'account', $sub)->assertOk();
        $this->assertNull($peerMember->fresh()->impersonation_level);

        // The Admin's hand is the only one that writes it.
        $this->lend($subMember, 'account')->assertOk();
        $this->assertSame('account', $subMember->fresh()->impersonation_level);
    }

    public function test_an_admin_cannot_pass_on_more_than_the_platform_gave(): void
    {
        $this->grant('crm');
        [, $subMember] = $this->subadmin('sub@acme.test');

        // Refused outright rather than quietly trimmed: an Admin who picked
        // 'account' should be told the company does not have it.
        $this->lend($subMember, 'account')->assertStatus(422);
        $this->assertNull($subMember->fresh()->impersonation_level);

        $this->lend($subMember, 'crm')->assertOk();
        $this->assertSame('crm', $subMember->fresh()->impersonation_level);
    }

    public function test_lowering_the_company_ceiling_lowers_everybody_under_it(): void
    {
        $this->grant('account');
        [$sub, $subMember] = $this->subadmin('sub@acme.test');
        $this->lend($subMember, 'account')->assertOk();

        $this->assertSame('account', $subMember->fresh()->impersonationLevel());

        // Netvork cuts the company back. The stored grant is untouched — the
        // Admin's choice is not rewritten behind their back — but what it is
        // worth is capped from now on.
        $this->grant('crm_read');

        $fresh = $subMember->fresh()->load('organization');
        $this->assertSame('account', $fresh->impersonation_level, 'the stored choice stands');
        $this->assertSame('crm_read', $fresh->impersonationLevel(), 'what it is worth is capped');

        $this->actingAs($sub)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.impersonation_level', 'crm_read');
    }

    public function test_demoting_a_subadmin_takes_the_seat_back(): void
    {
        $this->grant('crm');
        [, $subMember] = $this->subadmin('sub@acme.test');
        $this->lend($subMember, 'crm')->assertOk();

        $this->actingAs($this->admin)->putJson(
            "/api/v1/crm/employees/{$subMember->uuid}",
            ['crm_role' => 'employee'],
        )->assertOk();

        // Not merely inert while they are an employee — cleared, so that
        // promoting them again does not quietly restore it.
        $this->assertNull($subMember->fresh()->impersonation_level);
    }

    public function test_a_subadmin_holds_nothing_until_the_admin_names_them(): void
    {
        $this->grant('account');

        [$sub, $subMember] = $this->subadmin('sub@acme.test');

        // A Subadmin has most of the delicate acts by virtue of the job. This
        // is not one of them: it opens somebody else's account, so it is held
        // by name or not at all — and the screen does not offer it either.
        $this->actingAs($sub)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.impersonation_level', null);

        $this->actingAs($sub)
            ->postJson("/api/v1/crm/employees/{$this->employeeMember->uuid}/impersonate")
            ->assertForbidden();
    }

    public function test_a_named_subadmin_may_open_an_employee_and_only_an_employee(): void
    {
        $this->grant('crm');

        [$sub, $subMember] = $this->subadmin('sub@acme.test');
        $this->lend($subMember, 'crm');

        $this->actingAs($sub)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.member.impersonation_level', 'crm');

        // An employee: yes.
        $this->actingAs($sub)
            ->postJson("/api/v1/crm/employees/{$this->employeeMember->uuid}/impersonate")
            ->assertOk();

        // A peer subadmin: no. Sideways is not what the grant is for — it is
        // a manager looking down at their own team, not across at each other.
        [, $peerMember] = $this->subadmin('peer@acme.test');
        $this->actingAs($sub)
            ->postJson("/api/v1/crm/employees/{$peerMember->uuid}/impersonate")
            ->assertForbidden();

        // And never the Admin.
        $adminMember = Member::where('organization_id', $this->org->id)->where('crm_role', 'admin')->firstOrFail();
        $this->actingAs($sub)
            ->postJson("/api/v1/crm/employees/{$adminMember->uuid}/impersonate")
            ->assertForbidden();
    }

    public function test_the_named_subadmin_sees_buttons_only_on_employees(): void
    {
        $this->grant('crm');

        [$sub, $subMember] = $this->subadmin('sub@acme.test');
        $this->lend($subMember, 'crm');
        [, $peerMember] = $this->subadmin('peer@acme.test');

        $rows = collect($this->actingAs($sub)->getJson('/api/v1/crm/employees')->assertOk()->json('data'))
            ->keyBy('email');

        $this->assertTrue($rows['worker@acme.test']['can_impersonate']);
        $this->assertFalse($rows['peer@acme.test']['can_impersonate']);
        $this->assertFalse($rows['boss@acme.test']['can_impersonate']);
        $this->assertFalse($rows['sub@acme.test']['can_impersonate']);
    }

    public function test_the_company_cannot_use_the_named_grant_to_escape_the_platforms(): void
    {
        // Named by their Admin, but the platform granted the company nothing.
        // The narrower gate still holds: a company cannot let itself in.
        [$sub, $subMember] = $this->subadmin('sub@acme.test');
        $this->lend($subMember, 'crm');

        $this->actingAs($sub)
            ->postJson("/api/v1/crm/employees/{$this->employeeMember->uuid}/impersonate")
            ->assertForbidden();
    }

    /**
     * The Admin granting a Subadmin a seat, through the screen that does it.
     *
     * Deliberately the HTTP route and not a direct update: the whole point of
     * the field is who is allowed to send it, and a test that writes the
     * column itself would prove nothing about that.
     */
    private function lend(Member $subadmin, ?string $level, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->putJson(
            "/api/v1/crm/employees/{$subadmin->uuid}",
            ['crm_role' => 'subadmin', 'impersonation_level' => $level],
        );
    }

    /** @return array{0: User, 1: Member} */
    private function subadmin(string $email): array
    {
        $user = $this->makeUser($email);
        $member = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'crm_role' => 'subadmin',
            // A Subadmin holds module rights by grant, not by role, and
            // somebody who is to open an employee's workspace has to be able
            // to see the Users screen the button lives on in the first place.
            'rights' => ['employees' => ['view']],
        ]);

        return [$user, $member];
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

    public function test_the_scope_holds_with_no_guard_behind_it(): void
    {
        /*
         * The regression test for the bug the feature shipped with, and it
         * deliberately does not go through the HTTP kernel.
         *
         * ImpersonationScope is global middleware: it runs before the route's
         * auth:sanctum, so at that moment nothing has authenticated anybody
         * and no guard holds a user. The first version asked $request->user()
         * anyway — which consults the default guard, 'web', a session guard on
         * a request with no session — got null, concluded the session was not
         * a borrowed one, and let every crm and crm_read session through into
         * the private Netvork of the person whose seat it was in.
         *
         * Every test around this one passed while that was true, because the
         * test kernel leaves a guard resolved from earlier calls in the same
         * test and the question got answered by accident. So this one hands
         * the middleware a bare request carrying nothing but the bearer token
         * — exactly what the global pipeline sees — and no guard state at all.
         */
        $this->grant('crm');
        $plain = $this->borrow();

        $this->app['auth']->forgetGuards();

        $request = \Illuminate\Http\Request::create('/api/v1/notes', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plain);

        $this->assertNull($request->user(), 'the premise: nothing has authenticated this request yet');

        $reached = false;
        try {
            (new \App\Http\Middleware\ImpersonationScope)->handle(
                $request,
                function () use (&$reached) {
                    $reached = true;

                    return response('through');
                },
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertFalse($reached, 'a CRM-scoped session reached a personal route with no guard resolved');
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
