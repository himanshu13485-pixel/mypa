<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use App\Support\NotificationTopics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which CRM menus send mail and alerts is the company Admin's decision, for
 * everybody in the company - not each employee's, and not a Subadmin's.
 */
class CompanyNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $subUser;
    protected User $staffUser;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        foreach (['adminUser' => 'admin', 'subUser' => 'subadmin', 'staffUser' => 'employee'] as $prop => $role) {
            $user = User::factory()->create(['email' => $role . '@acme.test', 'email_verified_at' => now()]);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'Asia/Kolkata']);
            Member::create([
                'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => 'active',
            ]);
            $this->{$prop} = $user;
        }
    }

    public function test_every_crm_menu_is_on_until_the_admin_says_otherwise(): void
    {
        $policy = $this->actingAs($this->staffUser)->getJson('/api/v1/crm/notification-topics')->assertOk();

        $this->assertSame(count(NotificationTopics::crm()), count($policy->json('data.topics')));
        $this->assertTrue($policy->json('data.values.payments.email'));
        // Anybody may read it; only the Admin may change it.
        $this->assertFalse($policy->json('data.can_edit'));

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/notification-topics')
            ->assertOk()->assertJsonPath('data.can_edit', true);
    }

    public function test_the_admin_silences_a_menu_for_everybody(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/notification-topics', [
            'topics' => ['payments' => ['email' => false]],
        ])->assertOk()->assertJsonPath('data.values.payments.email', false);

        foreach ([$this->adminUser, $this->subUser, $this->staffUser] as $who) {
            $via = (new CrmNotification('crm_payment', 'Money landed.'))->via($who->fresh());
            $this->assertNotContains('mail', $via);
            // The record is still kept.
            $this->assertContains('database', $via);
        }

        // Another menu is untouched.
        $this->assertContains('mail', (new CrmNotification('crm_expense', 'Bill.'))->via($this->staffUser->fresh()));
    }

    public function test_only_the_admin_may_decide(): void
    {
        foreach ([$this->subUser, $this->staffUser] as $who) {
            $this->actingAs($who)->putJson('/api/v1/crm/notification-topics', [
                'topics' => ['payments' => ['email' => false]],
            ])->assertForbidden();
        }

        $this->assertTrue($this->org->fresh()->topicAllows('payments', 'email'));
    }

    public function test_an_employee_cannot_overrule_the_company_about_its_own_mail(): void
    {
        // The employee tries to silence payments for themselves...
        $this->actingAs($this->staffUser)->putJson('/api/v1/me/notification-topics', [
            'topics' => ['payments' => ['email' => false]],
        ])->assertOk();

        // ...and the company's answer - on - still stands.
        $this->assertContains('mail', (new CrmNotification('crm_payment', 'Money.'))->via($this->staffUser->fresh()));
    }

    public function test_a_persons_own_switch_for_all_email_still_wins(): void
    {
        $this->actingAs($this->staffUser)->putJson('/api/v1/me/settings', [
            'notification_preferences' => ['email' => false],
        ])->assertOk();

        $this->assertNotContains('mail', (new CrmNotification('crm_payment', 'Money.'))->via($this->staffUser->fresh()));
    }
}
