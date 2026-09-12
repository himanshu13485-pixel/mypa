<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Support\NotificationTopics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A person's own menus, and whose letterhead work mail arrives on.
 *
 * The CRM's menus are the company Admin's to decide (see
 * CompanyNotificationPolicyTest). What is left here is what belongs to the
 * person: their chat, their calendar, their connections - their own account.
 */
class NotificationTopicTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $email = 'sales@acme.test'): User
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    public function test_a_persons_own_list_is_only_their_own_menus(): void
    {
        $user = $this->person();

        $listed = $this->actingAs($user)->getJson('/api/v1/me/notification-topics')->assertOk();

        $keys = collect($listed->json('data.topics'))->pluck('key');
        $this->assertSame(count(NotificationTopics::personal()), $keys->count());
        // Chat is theirs; payments are the company's.
        $this->assertTrue($keys->contains('chat'));
        $this->assertFalse($keys->contains('payments'));
        $this->assertTrue($listed->json('data.values.chat.email'));
    }

    public function test_one_menu_can_be_silenced_without_touching_the_rest(): void
    {
        $user = $this->person();

        $this->actingAs($user)->putJson('/api/v1/me/notification-topics', [
            'topics' => ['connections' => ['email' => false]],
        ])->assertOk()
            ->assertJsonPath('data.values.connections.email', false)
            ->assertJsonPath('data.values.connections.app', true)
            ->assertJsonPath('data.values.calendar.email', true);

        $this->assertNotContains('mail', (new SocialNotification('connection_request', 'Hi.'))->via($user->fresh()));
        $this->assertContains('database', (new SocialNotification('connection_request', 'Hi.'))->via($user->fresh()));
        $this->assertContains('mail', (new SocialNotification('event_invite', 'Hi.'))->via($user->fresh()));
    }

    public function test_a_person_cannot_switch_off_a_company_menu_for_themselves(): void
    {
        $user = $this->person();

        $this->actingAs($user)->putJson('/api/v1/me/notification-topics', [
            'topics' => ['payments' => ['email' => false], 'chat' => ['app' => false]],
        ])->assertOk();

        $saved = $user->fresh()->settings->notification_preferences['topics'];
        $this->assertArrayNotHasKey('payments', $saved);
        $this->assertFalse($saved['chat']['app']);
    }

    public function test_a_menu_nobody_has_filed_is_loud_rather_than_silent(): void
    {
        $user = $this->person();

        $this->assertSame('other', NotificationTopics::of('something_new'));
        $this->assertContains('mail', (new SocialNotification('something_new', 'Hello.'))->via($user));

        $this->actingAs($user)->putJson('/api/v1/me/notification-topics', [
            'topics' => ['other' => ['email' => false]],
        ])->assertOk();

        $this->assertNotContains('mail', (new SocialNotification('something_new', 'Hello.'))->via($user->fresh()));
    }

    public function test_the_app_wide_switch_still_beats_a_menu(): void
    {
        $user = $this->person();

        $this->actingAs($user)->putJson('/api/v1/me/settings', [
            'notification_preferences' => ['email' => false],
        ])->assertOk();

        $this->assertFalse(NotificationTopics::allows($user->fresh(), 'chat', 'email'));
        $this->assertFalse(NotificationTopics::allows($user->fresh(), 'payments', 'email'));
    }

    public function test_an_employees_mail_leaves_from_their_company(): void
    {
        Mail::fake();
        $user = $this->person();

        $this->assertNull(\App\Services\Crm\CompanyMailer::forStaff($user));

        $org = Organization::create([
            'name' => 'Acme Pvt Ltd',
            'code' => 'ACME',
            'settings' => [
                'communication' => [
                    'email_enabled' => true,
                    'from_address' => 'admin@acme-exports.com',
                    'from_name' => 'Acme Exports',
                ],
            ],
        ]);
        Member::create([
            'organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => 'employee', 'status' => 'active',
        ]);

        $sender = \App\Services\Crm\CompanyMailer::forStaff($user->fresh());
        $this->assertSame('admin@acme-exports.com', $sender['address']);
        $this->assertSame('Acme Exports', $sender['name']);
    }
}
