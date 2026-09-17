<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Communication is a right of its own now.
 *
 * The mailboxes the company writes from sat inside Billing setup, so one tick
 * for tax rates also handed over the address every client hears from - and
 * the menu said Subadmin while the API said masters, which is two answers to
 * one question. Nobody holds it until the Admin gives it.
 */
class CrmCommunicationRightTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->person('boss@grapout.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    /** @return array{0: User, 1: Member} */
    private function member(string $email, string $role, ?array $rights = null): array
    {
        $user = $this->person($email);
        $member = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id,
            'crm_role' => $role, 'status' => 'active', 'rights' => $rights,
        ]);

        return [$user, $member];
    }

    public function test_the_company_admin_holds_it_by_the_job(): void
    {
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters/communication')->assertOk();
    }

    public function test_a_subadmin_does_not_have_it_merely_for_being_one(): void
    {
        [$sub] = $this->member('sub@grapout.test', 'subadmin', ['invoices' => ['view', 'edit']]);

        $this->actingAs($sub)->getJson('/api/v1/crm/masters/communication')->assertForbidden();
        $this->actingAs($sub)->putJson('/api/v1/crm/masters/communication', ['from_name' => 'Grapout'])->assertForbidden();
    }

    public function test_the_billing_setup_right_no_longer_carries_it(): void
    {
        // The tick that sets tax rates is not the tick that decides which
        // mailbox writes to every client.
        [$billing] = $this->member('billing@grapout.test', 'employee', ['masters' => ['view', 'edit']]);

        $this->actingAs($billing)->getJson('/api/v1/crm/masters/communication')->assertForbidden();
    }

    public function test_it_can_be_given_to_one_person_by_name(): void
    {
        [$reader] = $this->member('reader@grapout.test', 'employee', ['communication' => ['view']]);
        [$editor] = $this->member('editor@grapout.test', 'employee', ['communication' => ['view', 'edit']]);

        $this->actingAs($reader)->getJson('/api/v1/crm/masters/communication')->assertOk();
        // Reading is not changing.
        $this->actingAs($reader)->putJson('/api/v1/crm/masters/communication', ['from_name' => 'Grapout'])->assertForbidden();

        $this->actingAs($editor)->putJson('/api/v1/crm/masters/communication', [
            'from_name' => 'Grapout', 'from_address' => 'hello@grapout.com',
        ])->assertOk();
    }

    public function test_the_rights_screen_offers_it(): void
    {
        $this->assertArrayHasKey('communication', Member::MODULES);

        $labels = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters')->assertOk()->json('data.module_labels');
        $this->assertArrayHasKey('communication', $labels);
    }
}
