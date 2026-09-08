<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who the lead popups interrupt.
 *
 * Seeing leads and being chased about them are different things. An Admin
 * can see every lead in the company, which made them the most interrupted
 * person in it — nagged every fifteen minutes about follow-ups belonging to
 * somebody else, with no call of their own to make. The people the nag is
 * for are the ones carrying the leads, and the company already marks them.
 *
 * A preference, not a permission: none of this changes who may READ a lead.
 */
class CrmLeadAlertAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);
        [$this->adminUser] = $this->member('admin', 'Jaimin');
    }

    /** @return array{0: User, 1: Member} */
    private function member(string $role, string $name, array $extra = []): array
    {
        $user = User::factory()->create(['name' => $name]);
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        $member = Member::create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'crm_role' => $role,
            'status' => 'active',
        ], $extra));

        return [$user, $member];
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** What /crm/me says about being interrupted. */
    private function alerts(User $user): bool
    {
        return $this->as($user)->getJson('/api/v1/crm/me')->assertOk()->json('data.lead_alerts');
    }

    private function setAudience(bool $everyone)
    {
        return $this->as($this->adminUser)->putJson('/api/v1/crm/masters/lead-settings', [
            'alert_minutes' => 15,
            'new_alert_minutes' => 15,
            'alerts_everyone' => $everyone,
        ]);
    }

    // ---- The default -------------------------------------------------------

    public function test_a_salesperson_is_interrupted(): void
    {
        [$riya] = $this->member('employee', 'Riya', ['is_salesperson' => true, 'rights' => ['leads' => ['view']]]);

        $this->assertTrue($this->alerts($riya));
    }

    public function test_an_admin_is_not_interrupted_by_default(): void
    {
        // The complaint: an Admin sees every lead in the company and was
        // nagged about all of them.
        $this->assertFalse($this->alerts($this->adminUser));
    }

    public function test_a_subadmin_is_not_interrupted_by_default(): void
    {
        [$nikhil] = $this->member('subadmin', 'Nikhil', ['rights' => ['leads' => ['view', 'edit']]]);

        $this->assertFalse($this->alerts($nikhil));
    }

    public function test_an_employee_who_does_not_sell_is_not_interrupted(): void
    {
        // Accounts can read the leads screen without being asked to ring one.
        [$clerk] = $this->member('employee', 'Nita', ['rights' => ['leads' => ['view']]]);

        $this->assertFalse($this->alerts($clerk));
    }

    public function test_an_admin_who_also_sells_is_interrupted(): void
    {
        // A working owner carries leads of their own, and the tick says so.
        [$boss] = $this->member('admin', 'Vikram', ['is_salesperson' => true]);

        $this->assertTrue($this->alerts($boss));
    }

    // ---- The toggle --------------------------------------------------------

    public function test_the_company_can_open_the_popups_to_everyone(): void
    {
        $this->assertFalse($this->alerts($this->adminUser));

        $this->setAudience(true)->assertOk();

        $this->assertTrue($this->alerts($this->adminUser));
    }

    public function test_turning_it_back_off_returns_to_salespeople(): void
    {
        [$nikhil] = $this->member('subadmin', 'Nikhil', ['rights' => ['leads' => ['view']]]);
        [$riya] = $this->member('employee', 'Riya', ['is_salesperson' => true, 'rights' => ['leads' => ['view']]]);

        $this->setAudience(true)->assertOk();
        $this->assertTrue($this->alerts($nikhil));

        $this->setAudience(false)->assertOk();
        $this->assertFalse($this->alerts($nikhil));
        // The salesperson is never the one who loses them.
        $this->assertTrue($this->alerts($riya));
    }

    public function test_the_setting_is_reported_back_to_the_screen(): void
    {
        $this->setAudience(true)->assertOk();

        $this->as($this->adminUser)
            ->getJson('/api/v1/crm/masters/lead-settings')
            ->assertOk()
            ->assertJsonPath('data.alerts_everyone', true)
            ->assertJsonPath('data.alert_minutes', 15);
    }

    public function test_saving_the_timings_alone_does_not_switch_the_audience_on(): void
    {
        // An older client that knows nothing of this field must not turn it on
        // by omission.
        $this->as($this->adminUser)
            ->putJson('/api/v1/crm/masters/lead-settings', ['alert_minutes' => 20])
            ->assertOk();

        $this->assertFalse($this->alerts($this->adminUser));
    }

    // ---- It is a preference, not a permission ------------------------------

    public function test_an_admin_can_still_read_the_due_leads(): void
    {
        // Not being nagged is not the same as not being allowed to look.
        $this->as($this->adminUser)->getJson('/api/v1/crm/leads-due')->assertOk();
        $this->as($this->adminUser)->getJson('/api/v1/crm/leads-new')->assertOk();
    }

    public function test_somebody_who_cannot_see_leads_is_never_interrupted(): void
    {
        // Even marked as a salesperson: without the module right there is no
        // screen to send them to.
        [$outsider] = $this->member('employee', 'Arjun', ['is_salesperson' => true]);

        $this->assertFalse($this->alerts($outsider));
    }
}
