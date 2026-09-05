<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One set of rights, given to everybody.
 *
 * The thing worth being careful about is not the copying, it is the reach. A
 * button that writes rights onto every row in a company is the widest single
 * act in the CRM, and it must not reach one row further than the caller could
 * already have reached by opening people's screens one at a time.
 *
 * So every test here is really the same question asked from a different seat:
 * who does this touch, and who does it leave alone.
 */
class CrmSharedRightsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $admin;

    private const EVERYDAY = [
        'leads' => ['view', 'create', 'edit'],
        'clients' => ['view', 'create', 'edit'],
        'invoices' => ['view', 'create', 'edit'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = $this->staff('admin');
    }

    private function staff(string $role, array $rights = [], array $capabilities = []): User
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'crm_role' => $role,
            'rights' => $rights,
            'capabilities' => $capabilities,
        ]);

        return $user;
    }

    private function memberOf(User $user): Member
    {
        return Member::where('user_id', $user->id)->firstOrFail();
    }

    private function copy(User $who, array $payload = [])
    {
        return $this->actingAs($who)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->putJson('/api/v1/crm/employees-shared-rights', $payload + [
                'rights' => self::EVERYDAY,
                'apply_to_all' => true,
            ]);
    }

    public function test_one_set_reaches_every_employee(): void
    {
        $first = $this->staff('employee');
        $second = $this->staff('employee', ['leads' => ['view']]);

        $this->copy($this->admin)->assertOk()->assertJsonPath('data.applied', 2);

        // The second one had something of their own, and now has this.
        $this->assertSame(self::EVERYDAY, $this->memberOf($first)->rights);
        $this->assertSame(self::EVERYDAY, $this->memberOf($second)->rights);
    }

    public function test_it_never_writes_rights_onto_an_admin(): void
    {
        /*
         * An Admin holds everything by the job. A rights array on their row
         * would be a lie about what they can do — and the day something reads
         * the array instead of the role, it is a lie that locks them out of
         * their own company.
         */
        $other = $this->staff('admin');
        $this->staff('employee');

        $this->copy($this->admin)->assertOk()->assertJsonPath('data.applied', 1);

        $this->assertEmpty($this->memberOf($other)->rights ?? []);
        $this->assertEmpty($this->memberOf($this->admin)->rights ?? []);
    }

    public function test_a_subadmin_named_for_rights_reaches_employees_and_no_further(): void
    {
        /*
         * The reach that matters. A Subadmin may already set an employee's
         * rights one screen at a time, so doing it to all of them at once is
         * the same authority — but a peer Subadmin's rights were never theirs
         * to touch, because through a peer they could reach their own.
         */
        $subadmin = $this->staff('subadmin', [], ['employees.rights']);
        $peer = $this->staff('subadmin', ['leads' => ['view']]);
        $employee = $this->staff('employee');

        $this->copy($subadmin)->assertOk()->assertJsonPath('data.applied', 1);

        $this->assertSame(self::EVERYDAY, $this->memberOf($employee)->rights);
        $this->assertSame(['leads' => ['view']], $this->memberOf($peer)->rights);
        // And not themselves, which is the whole point of the restriction.
        $this->assertEmpty($this->memberOf($subadmin)->rights ?? []);
    }

    public function test_a_subadmin_not_named_for_rights_cannot_do_it_at_all(): void
    {
        $subadmin = $this->staff('subadmin');
        $employee = $this->staff('employee');

        $this->copy($subadmin)->assertStatus(403);

        $this->assertEmpty($this->memberOf($employee)->rights ?? []);
    }

    public function test_an_admin_reaches_subadmins_too(): void
    {
        // Where a Subadmin may not, an Admin may: a subadmin's rights are the
        // Admin's to set, one at a time or all at once.
        $subadmin = $this->staff('subadmin');

        $this->copy($this->admin)->assertOk()->assertJsonPath('data.applied', 1);

        $this->assertSame(self::EVERYDAY, $this->memberOf($subadmin)->rights);
    }

    public function test_somebody_who_has_left_is_left_alone(): void
    {
        // Restoring a deactivated account should give back the access it had,
        // not whatever the company happened to agree while they were gone.
        $gone = $this->staff('employee', ['leads' => ['view']]);
        $this->memberOf($gone)->update(['status' => 'inactive']);

        $this->copy($this->admin)->assertOk()->assertJsonPath('data.applied', 0);

        $this->assertSame(['leads' => ['view']], $this->memberOf($gone)->rights);
    }

    public function test_it_says_who_it_would_reach_before_it_reaches_them(): void
    {
        /*
         * The count is what the confirmation is built on. "This changes 14
         * people" is a different sentence from "are you sure?", and it is the
         * one that stops the wrong click.
         */
        $this->staff('employee');
        $this->staff('employee');
        $this->staff('subadmin');

        $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->getJson('/api/v1/crm/employees-shared-rights')
            ->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonPath('data.employees', 2)
            ->assertJsonPath('data.subadmins', 1);
    }

    public function test_a_module_nobody_has_heard_of_is_refused(): void
    {
        // Stricter than the one-person form, which ignores what it does not
        // know. A typo that lands on everybody is worth 422-ing over.
        $this->staff('employee');

        $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->putJson('/api/v1/crm/employees-shared-rights', [
                'rights' => ['leadz' => ['view']],
                'apply_to_all' => true,
            ])->assertStatus(422);
    }

    public function test_a_module_with_nothing_ticked_is_not_stored(): void
    {
        $employee = $this->staff('employee');

        $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->putJson('/api/v1/crm/employees-shared-rights', [
                'rights' => ['leads' => ['view'], 'clients' => []],
                'apply_to_all' => true,
            ])->assertOk();

        // A stored set says what somebody has, not everything that exists.
        $this->assertSame(['leads' => ['view']], $this->memberOf($employee)->rights);
    }

    public function test_where_a_new_hire_starts_is_remembered(): void
    {
        $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->putJson('/api/v1/crm/employees-shared-rights', [
                'rights' => self::EVERYDAY,
                'apply_to_all' => false,
                'set_as_default' => true,
            ])->assertOk()->assertJsonPath('data.applied', 0);

        $this->assertSame(self::EVERYDAY, $this->org->fresh()->defaultMemberRights());

        // And the form is told, so a new employee arrives ticked.
        $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->assertJsonPath('data.default_rights.leads', ['view', 'create', 'edit']);
    }

    public function test_a_subadmin_may_set_rights_but_not_a_standing_rule(): void
    {
        /*
         * Setting one person's rights is a decision about that person.
         * Deciding where everybody hired from now on begins is policy, and
         * policy is the Company Admin's — the same line the HR policy sits on.
         */
        $subadmin = $this->staff('subadmin', [], ['employees.rights']);

        $this->actingAs($subadmin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->putJson('/api/v1/crm/employees-shared-rights', [
                'rights' => self::EVERYDAY,
                'set_as_default' => true,
            ])->assertStatus(403);

        $this->assertSame([], $this->org->fresh()->defaultMemberRights());
    }

    public function test_the_widest_click_in_the_crm_is_written_down(): void
    {
        $this->staff('employee');

        $this->copy($this->admin)->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'crm.rights.applied_to_all']);
        $this->assertSame(1, AuditLog::where('action', 'crm.rights.applied_to_all')
            ->value('details')['members']);
    }
}
