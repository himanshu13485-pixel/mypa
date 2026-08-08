<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\IncomingCallNotification;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A phone that is not looking at the app still has to ring.
 *
 * Every other part of a call travels over the websocket, which only exists
 * while a tab is open — so before this, closing the app meant an incoming call
 * arrived nowhere at all.
 */
class IncomingCallPushTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }

        \App\Models\Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    private function subscribe(User $user): void
    {
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example/' . $user->uuid,
            'endpoint_hash' => hash('sha256', 'https://push.example/' . $user->uuid),
            'public_key' => str_repeat('a', 20),
            'auth_token' => str_repeat('b', 16),
            'content_encoding' => 'aesgcm',
        ]);
    }

    private function directConversation(): string
    {
        return $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->assertCreated()
            ->json('data.uuid');
    }

    public function test_a_call_rings_the_phone_it_is_calling(): void
    {
        $this->subscribe($this->bob);
        $conversation = $this->directConversation();

        Notification::fake();
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'video'])
            ->assertCreated();

        Notification::assertSentTo($this->bob, IncomingCallNotification::class, function ($n) {
            $push = $n->toPush($this->bob);

            return $push['title'] === 'Alice'
                && $push['body'] === 'Incoming video call'
                && $push['requireInteraction'] === true
                && str_starts_with($push['url'], '/calls?join=')
                && collect($push['actions'])->pluck('action')->all() === ['answer', 'decline'];
        });

        // The caller is not rung by their own call.
        Notification::assertNotSentTo($this->alice, IncomingCallNotification::class);
    }

    public function test_nobody_is_rung_who_has_turned_push_off(): void
    {
        $this->subscribe($this->bob);
        $this->bob->settings->update(['notification_preferences' => ['push' => false]]);
        $conversation = $this->directConversation();

        Notification::fake();
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->assertCreated();

        // The notification is still raised; `via` is what declines to deliver
        // it, which is the behaviour worth pinning down.
        Notification::assertSentTo($this->bob, IncomingCallNotification::class, function ($n) {
            return $n->via($this->bob) === [];
        });
    }

    public function test_a_group_call_rings_every_member_and_names_the_group(): void
    {
        $carol = User::factory()->create(['name' => 'Carol']);
        app(AppIdService::class)->generateFor($carol);
        $carol->settings()->create([]);
        $carol->profile()->create(['timezone' => 'UTC']);

        foreach ([$this->bob, $carol] as $user) {
            $this->subscribe($user);
        }

        $group = Group::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $this->alice->id,
            'name' => 'Team',
            'type' => 'team',
        ]);
        $group->members()->attach([
            $this->alice->id => ['role' => 'owner'],
            $this->bob->id => ['role' => 'member'],
            $carol->id => ['role' => 'member'],
        ]);
        $conversation = $this->actingAs($this->alice)
            ->getJson("/api/v1/groups/{$group->uuid}/conversation")->json('data.uuid');

        Notification::fake();
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->assertCreated();

        foreach ([$this->bob, $carol] as $user) {
            Notification::assertSentTo($user, IncomingCallNotification::class, function ($n) use ($user) {
                $push = $n->toPush($user);

                return $push['title'] === 'Team' && $push['body'] === 'Alice started a call';
            });
        }
    }

    public function test_inviting_someone_mid_call_rings_them_too(): void
    {
        $carol = User::factory()->create(['name' => 'Carol']);
        app(AppIdService::class)->generateFor($carol);
        $carol->settings()->create([]);
        $carol->profile()->create(['timezone' => 'UTC']);
        $carol->settings->update(['privacy' => ['who_can_call' => 'everyone']]);
        $this->subscribe($carol);

        $conversation = $this->directConversation();
        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'video'])
            ->json('data.uuid');
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();

        Notification::fake();
        $this->actingAs($this->alice)
            ->postJson("/api/v1/calls/{$uuid}/invite", ['identifier' => $carol->appId->app_id])
            ->assertOk();

        Notification::assertSentTo($carol, IncomingCallNotification::class);
    }

    public function test_the_tag_is_per_call_so_a_second_ring_replaces_the_first(): void
    {
        $this->subscribe($this->bob);
        $conversation = $this->directConversation();

        Notification::fake();
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->assertCreated();

        Notification::assertSentTo($this->bob, IncomingCallNotification::class, function ($n) {
            $push = $n->toPush($this->bob);

            return $push['tag'] === 'call-' . $n->call->uuid && $push['renotify'] === true;
        });
    }
}
