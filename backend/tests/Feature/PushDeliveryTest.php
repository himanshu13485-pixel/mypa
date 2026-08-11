<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Connection;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\IncomingCallNotification;
use App\Services\AppIdService;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Why a call rings on one Android phone and not another.
 *
 * Two causes, neither of which looks like a fault from either end: a push sent
 * at normal urgency is held by Android until the device's next maintenance
 * window, so a phone in a hand rings and the same phone asleep in a pocket does
 * not; and a subscription the browser quietly rotated goes on being posted to
 * long after nobody is reading it.
 */
class PushDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }

        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /** A real call, since a Call belongs to a conversation. */
    private function call(string $type = 'video'): Call
    {
        $conversation = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->assertCreated()->json('data.uuid');

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => $type])
            ->assertCreated()->json('data.uuid');

        return Call::where('uuid', $uuid)->firstOrFail();
    }

    private function subscription(User $user, string $endpoint): PushSubscription
    {
        return $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => str_repeat('a', 20),
            'auth_token' => str_repeat('b', 16),
            'content_encoding' => 'aes128gcm',
        ]);
    }

    // --- Urgency and lifetime ---------------------------------------------

    public function test_a_ring_is_urgent_and_short_lived(): void
    {
        $options = (new IncomingCallNotification($this->call(), 'Alice', false, null))->pushOptions();

        // Normal urgency is the whole bug: Android holds it until the device
        // wakes, so a pocketed phone never rings while one in a hand does.
        $this->assertSame('high', $options['urgency']);

        // And a ring nobody can still answer should not arrive at all — the
        // library's own default would keep trying for four weeks.
        $this->assertGreaterThan(0, $options['TTL']);
        $this->assertLessThanOrEqual(60, $options['TTL']);
    }

    public function test_re_ringing_the_same_call_collapses_rather_than_stacking(): void
    {
        $call = $this->call('audio');

        $first = (new IncomingCallNotification($call, 'A', false, null))->pushOptions();
        $second = (new IncomingCallNotification($call, 'A', false, null))->pushOptions();
        $this->assertSame($first['topic'], $second['topic']);

        $other = $this->call('audio');
        $this->assertNotSame(
            $first['topic'],
            (new IncomingCallNotification($other, 'A', false, null))->pushOptions()['topic'],
        );
    }

    // --- The browser moving a subscription ---------------------------------

    public function test_a_rotated_subscription_follows_the_device(): void
    {
        // Chrome rotates on its own — after an update, under storage pressure,
        // with age. Without this the row keeps the dead endpoint and the phone
        // silently stops being told anything at all.
        $sub = $this->subscription($this->bob, 'https://push.example/old');

        $this->postJson('/api/v1/push/rotate', [
            'old_endpoint' => 'https://push.example/old',
            'endpoint' => 'https://push.example/new',
            'keys' => ['p256dh' => 'new-p256dh', 'auth' => 'new-auth'],
        ])->assertOk();

        $sub->refresh();
        $this->assertSame('https://push.example/new', $sub->endpoint);
        $this->assertSame(hash('sha256', 'https://push.example/new'), $sub->endpoint_hash);
        $this->assertSame('new-p256dh', $sub->public_key);
        $this->assertSame($this->bob->id, $sub->user_id, 'it stays the same person');
    }

    public function test_rotating_never_creates_a_subscription(): void
    {
        // The route is unauthenticated, so it must only move something that
        // already exists — otherwise a stranger could register a device
        // against nobody, or against somebody else.
        $this->postJson('/api/v1/push/rotate', [
            'old_endpoint' => 'https://push.example/never-existed',
            'endpoint' => 'https://push.example/mine',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_an_unknown_endpoint_answers_like_a_known_one(): void
    {
        // Otherwise the route tells anyone who asks which endpoints are live.
        $this->subscription($this->bob, 'https://push.example/real');

        $known = $this->postJson('/api/v1/push/rotate', [
            'old_endpoint' => 'https://push.example/real',
            'endpoint' => 'https://push.example/real-2',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ]);
        $unknown = $this->postJson('/api/v1/push/rotate', [
            'old_endpoint' => 'https://push.example/invented',
            'endpoint' => 'https://push.example/invented-2',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ]);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_one_device_rotating_leaves_the_others_alone(): void
    {
        $phone = $this->subscription($this->bob, 'https://push.example/phone');
        $laptop = $this->subscription($this->bob, 'https://push.example/laptop');

        $this->postJson('/api/v1/push/rotate', [
            'old_endpoint' => 'https://push.example/phone',
            'endpoint' => 'https://push.example/phone-new',
            'keys' => ['p256dh' => 'k', 'auth' => 'a'],
        ])->assertOk();

        $this->assertSame('https://push.example/phone-new', $phone->fresh()->endpoint);
        $this->assertSame('https://push.example/laptop', $laptop->fresh()->endpoint);
    }
}
