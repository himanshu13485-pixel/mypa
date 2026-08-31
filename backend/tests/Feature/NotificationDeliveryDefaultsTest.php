<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\SocialNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Being reachable by default, and staying reachable.
 *
 * Three faults, all of which looked like "push just doesn't work" and none of
 * which said so anywhere:
 *
 * A browser holds one push endpoint per site regardless of who is signed in,
 * but subscribe() looked the row up scoped to the current user — so a second
 * account on the same browser hit the global unique index and got a 500.
 *
 * Notifications were stored but never broadcast, so an open tab learned about
 * them on its next poll rather than when they happened.
 *
 * And the defaults were four separate `?? true` expressions rather than one
 * stated intention, which is the kind of default that stops being the default
 * the first time somebody writes the fifth one differently.
 */
class NotificationDeliveryDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $user) {
            $user->settings()->create([]);
        }
    }

    private function subscribeAs(User $user, string $endpoint): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/push/subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('a', 20), 'auth' => str_repeat('b', 16)],
        ]);
    }

    // ---- One browser, two accounts -----------------------------------------

    public function test_a_second_account_on_the_same_browser_takes_over_the_subscription(): void
    {
        $endpoint = 'https://push.example/shared-browser';

        $this->subscribeAs($this->alice, $endpoint)->assertOk();

        // The same browser, now signed in as somebody else. This used to be a
        // 500: the lookup was scoped to Bob, found nothing, and the insert hit
        // the global unique index on endpoint_hash.
        $this->subscribeAs($this->bob, $endpoint)->assertOk();

        // One row, not two, and it belongs to whoever asked most recently — a
        // browser can only receive for one account at a time.
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->bob->id, PushSubscription::first()->user_id);
        $this->assertSame(0, $this->alice->pushSubscriptions()->count());
    }

    public function test_re_registering_the_same_browser_does_not_duplicate_it(): void
    {
        $endpoint = 'https://push.example/same-browser';

        // What ensurePushRegistered() does on every page load.
        $this->subscribeAs($this->alice, $endpoint)->assertOk();
        $this->subscribeAs($this->alice, $endpoint)->assertOk();
        $this->subscribeAs($this->alice, $endpoint)->assertOk();

        $this->assertSame(1, $this->alice->pushSubscriptions()->count());
    }

    public function test_two_different_browsers_are_two_subscriptions(): void
    {
        $this->subscribeAs($this->alice, 'https://push.example/laptop')->assertOk();
        $this->subscribeAs($this->alice, 'https://push.example/phone')->assertOk();

        $this->assertSame(2, $this->alice->pushSubscriptions()->count());
    }

    // ---- Live, not polled --------------------------------------------------

    public function test_every_notification_is_broadcast_as_well_as_stored(): void
    {
        // The bell used to find out on a 30-second timer, which a background
        // tab throttles further — so a phone could buzz while the website sat
        // unchanged.
        $via = (new SocialNotification('task_assigned', 'x'))->via($this->alice);

        $this->assertContains('database', $via);
        $this->assertContains('broadcast', $via);
    }

    public function test_the_broadcast_carries_what_was_stored(): void
    {
        $note = new SocialNotification('expense_added', 'Bob added an expense', ['project_uuid' => 'p-1'], '/projects');

        $this->assertSame(
            $note->toDatabase($this->alice),
            $note->toBroadcast($this->alice)->data,
            'the live nudge and the stored row must never disagree',
        );
    }

    public function test_it_broadcasts_on_the_channel_the_app_actually_authorises(): void
    {
        /*
         * Laravel's default is App.Models.User.{id}, which channels.php has no
         * rule for — nobody could ever subscribe to it, so the broadcast would
         * have gone nowhere at all. user.{uuid} is the one calls and meeting
         * signals already use.
         */
        $this->assertSame('user.' . $this->alice->uuid, $this->alice->receivesBroadcastNotificationsOn());
    }

    // ---- On unless somebody says otherwise ---------------------------------

    public function test_a_brand_new_account_has_everything_switched_on(): void
    {
        // Settings rows are created empty at registration, so "never touched"
        // and "not mentioned" are the same state and both mean on.
        $settings = $this->alice->settings;

        $this->assertNull($settings->notification_preferences);
        $this->assertTrue($settings->notificationValue('email'));
        $this->assertTrue($settings->notificationValue('push'));
    }

    public function test_an_unknown_preference_defaults_to_on(): void
    {
        // A channel added later must reach people who registered before it
        // existed, rather than being silently off for every existing account.
        $this->assertTrue($this->alice->settings->notificationValue('something_added_later'));
    }

    public function test_turning_one_off_leaves_the_others_alone(): void
    {
        $this->alice->settings->update(['notification_preferences' => ['email' => false]]);
        $settings = $this->alice->settings->fresh();

        $this->assertFalse($settings->notificationValue('email'));
        $this->assertTrue($settings->notificationValue('push'), 'push is not mentioned, so it stays on');
    }

    public function test_an_explicit_no_is_still_honoured(): void
    {
        $this->alice->settings->update(['notification_preferences' => ['push' => false]]);
        $this->alice->pushSubscriptions()->create([
            'endpoint' => 'https://push.example/alice',
            'endpoint_hash' => hash('sha256', 'https://push.example/alice'),
            'public_key' => 'key',
            'auth_token' => 'auth',
            'content_encoding' => 'aes128gcm',
        ]);

        // On by default must not mean on regardless.
        $this->assertFalse(SocialNotification::wantsPush($this->alice->fresh()));
    }
}
