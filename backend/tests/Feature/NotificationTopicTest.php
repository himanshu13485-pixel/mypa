<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use App\Notifications\SocialNotification;
use App\Support\NotificationTopics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Which menus may write to you, and whose letterhead it arrives on.
 *
 * Two app-wide switches is not a choice anybody wants to make. What people
 * want is to stop the payment e-mails and keep the leave approvals - so
 * every notification is filed under the screen it came from, and each
 * screen has its own pair.
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

    public function test_every_menu_is_on_until_somebody_turns_one_off(): void
    {
        $user = $this->person();

        $listed = $this->actingAs($user)->getJson('/api/v1/me/notification-topics')->assertOk();

        // Every topic the server knows, each on for both channels.
        $this->assertSame(count(NotificationTopics::TOPICS), count($listed->json('data.topics')));
        $this->assertTrue($listed->json('data.values.payments.email'));
        $this->assertTrue($listed->json('data.values.payments.app'));
        $this->assertTrue($listed->json('data.email'));
    }

    public function test_one_menu_can_be_silenced_without_touching_the_rest(): void
    {
        $user = $this->person();

        $this->actingAs($user)->putJson('/api/v1/me/notification-topics', [
            'topics' => ['payments' => ['email' => false]],
        ])->assertOk()
            ->assertJsonPath('data.values.payments.email', false)
            // The bell for payments is untouched, and so is every other menu.
            ->assertJsonPath('data.values.payments.app', true)
            ->assertJsonPath('data.values.expenses.email', true);

        $settings = $user->fresh()->settings;
        $this->assertFalse($settings->topicAllows('payments', 'email'));
        $this->assertTrue($settings->topicAllows('expenses', 'email'));

        // A payment notification now reaches the bell and no inbox.
        $via = (new CrmNotification('crm_payment', 'Money landed.'))->via($user->fresh());
        $this->assertNotContains('mail', $via);
        $this->assertContains('database', $via);

        // An expense one still does both.
        $this->assertContains('mail', (new CrmNotification('crm_expense', 'Bill added.'))->via($user->fresh()));
    }

    public function test_a_menu_nobody_has_filed_is_loud_rather_than_silent(): void
    {
        $user = $this->person();

        // A kind invented after this list was written falls under 'other'…
        $this->assertSame('other', NotificationTopics::of('something_new'));
        $this->assertContains('mail', (new SocialNotification('something_new', 'Hello.'))->via($user));

        // …and 'other' can be switched off like any of them.
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

        // Every menu says "on" and none of them may write: turning e-mail
        // off entirely and finding one menu still writing would be a setting
        // that lied.
        $this->assertFalse($user->fresh()->settings->topicAllows('payments', 'email'));
        $this->assertFalse($user->fresh()->settings->topicAllows('tasks', 'email'));
    }

    public function test_an_unknown_menu_is_ignored_rather_than_stored(): void
    {
        $user = $this->person();

        $this->actingAs($user)->putJson('/api/v1/me/notification-topics', [
            'topics' => ['not_a_menu' => ['email' => false], 'leads' => ['app' => false]],
        ])->assertOk();

        $saved = $user->fresh()->settings->notification_preferences['topics'];
        $this->assertArrayNotHasKey('not_a_menu', $saved);
        $this->assertFalse($saved['leads']['app']);
    }

    public function test_an_employees_mail_leaves_from_their_company(): void
    {
        Mail::fake();
        $user = $this->person();

        // On their own, they hear from the platform.
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

        // The day the company takes them on, the company is the one writing.
        $sender = \App\Services\Crm\CompanyMailer::forStaff($user->fresh());
        $this->assertSame('admin@acme-exports.com', $sender['address']);
        $this->assertSame('Acme Exports', $sender['name']);
    }
}
