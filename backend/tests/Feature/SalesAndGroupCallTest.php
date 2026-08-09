<?php

namespace Tests\Feature;

use App\Events\CallSignal;
use App\Models\Group;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Current batch: salesperson assignment + workspace, manual email verify,
 * push subscriptions, mesh group calls.
 */
class SalesAndGroupCallTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;
    protected User $bob;
    protected User $carol;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->carol = User::factory()->create(['name' => 'Carol']);
        foreach ([$this->alice, $this->bob, $this->carol] as $u) {
            $appIds->generateFor($u);
            $u->settings()->create([]);
        }
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
    }

    /**
     * Direct calls honour "who can call me", whose default is 'connections' —
     * so a one-to-one call test has to establish that the two people actually
     * know each other. Group calls are exempt: joining the group is consent.
     */
    private function connect(User $a, User $b): void
    {
        \App\Models\Connection::create([
            'requester_id' => $a->id,
            'addressee_id' => $b->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    private function makeSalesperson(): User
    {
        $sales = User::factory()->create(['name' => 'Seller']);
        $sales->roles()->attach(Role::where('slug', 'salesperson')->first()->id);

        return $sales;
    }

    // --- Salesperson assignment ----------------------------------------------

    public function test_admin_assigns_salesperson_and_they_see_the_user(): void
    {
        $sales = $this->makeSalesperson();

        // Cannot assign a non-salesperson account.
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->alice->uuid}/salesperson", [
            'salesperson_uuid' => $this->bob->uuid,
        ])->assertStatus(422);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->alice->uuid}/salesperson", [
            'salesperson_uuid' => $sales->uuid,
        ])->assertOk();
        $this->assertEquals($sales->id, $this->alice->fresh()->salesperson_id);

        // Salesperson sees the assigned user with plan, and can open the summary.
        $rows = $this->actingAs($sales)->getJson('/api/v1/admin/sales/my-users')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('Alice', $rows[0]['name']);
        $this->assertArrayHasKey('plan', $rows[0]);

        $this->actingAs($sales)->getJson("/api/v1/admin/sales/users/{$this->alice->uuid}/summary")->assertOk();

        // But not a user who is not theirs.
        $this->actingAs($sales)->getJson("/api/v1/admin/sales/users/{$this->bob->uuid}/summary")->assertForbidden();

        // Regular users cannot reach the workspace at all.
        $this->actingAs($this->alice)->getJson('/api/v1/admin/sales/my-users')->assertForbidden();

        // Unassign.
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->alice->uuid}/salesperson", [
            'salesperson_uuid' => null,
        ])->assertOk();
        $this->assertNull($this->alice->fresh()->salesperson_id);
    }

    public function test_salespeople_listing_for_dropdown(): void
    {
        $this->makeSalesperson();
        $rows = $this->actingAs($this->admin)->getJson('/api/v1/admin/salespeople')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertEquals('Seller', $rows[0]['name']);
    }

    // --- Manual email verification -------------------------------------------

    public function test_admin_can_manually_verify_email(): void
    {
        $this->alice->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->alice->uuid}/verify-email")
            ->assertOk();

        $this->assertNotNull($this->alice->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.email_verified_manually']);

        // Regular users cannot.
        $this->actingAs($this->bob)->postJson("/api/v1/admin/users/{$this->alice->uuid}/verify-email")
            ->assertForbidden();
    }

    // --- Push subscriptions ----------------------------------------------------

    public function test_push_subscribe_and_unsubscribe(): void
    {
        $endpoint = 'https://push.example.com/send/abc123';

        $this->actingAs($this->alice)->postJson('/api/v1/push/subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'pubkey', 'auth' => 'authtoken'],
        ])->assertOk();
        $this->assertEquals(1, $this->alice->pushSubscriptions()->count());

        // Re-subscribing the same endpoint updates, not duplicates.
        $this->actingAs($this->alice)->postJson('/api/v1/push/subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'pubkey2', 'auth' => 'authtoken2'],
        ])->assertOk();
        $this->assertEquals(1, $this->alice->pushSubscriptions()->count());

        $this->actingAs($this->alice)->postJson('/api/v1/push/unsubscribe', [
            'endpoint' => $endpoint,
        ])->assertOk();
        $this->assertEquals(0, $this->alice->pushSubscriptions()->count());
    }

    // --- Mesh group calls ------------------------------------------------------

    private function groupConversation(): string
    {
        $group = Group::create(['owner_id' => $this->alice->id, 'name' => 'Team', 'type' => 'team']);
        $group->members()->attach([
            $this->alice->id => ['role' => 'owner'],
            $this->bob->id => ['role' => 'member'],
            $this->carol->id => ['role' => 'member'],
        ]);

        return $this->actingAs($this->alice)
            ->getJson("/api/v1/groups/{$group->uuid}/conversation")
            ->json('data.uuid');
    }

    public function test_call_history_knows_a_direct_call_from_a_group_one(): void
    {
        Event::fake([CallSignal::class]);
        $this->connect($this->alice, $this->bob);

        $direct = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->json('data.uuid');
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$direct}/calls", ['type' => 'audio'])
            ->assertCreated();

        $group = $this->groupConversation();
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$group}/calls", ['type' => 'audio'])
            ->assertCreated();

        /*
         * The history loaded its conversations by column list, and the list
         * left out the very fields this is decided by. Every call came back a
         * group call, the direct one included — so anything offering to ring
         * somebody back from the log had nobody to ring.
         */
        $rows = collect($this->actingAs($this->alice)->getJson('/api/v1/calls/history')->json('data'));
        $this->assertCount(2, $rows);

        $one = $rows->firstWhere('conversation_uuid', $direct);
        $this->assertFalse($one['is_group']);
        $this->assertNull($one['group_name']);
        $this->assertEquals($this->bob->uuid, $one['other_user']['uuid']);

        $many = $rows->firstWhere('conversation_uuid', $group);
        $this->assertTrue($many['is_group']);
        $this->assertEquals('Team', $many['group_name']);
    }

    public function test_group_call_rings_everyone_and_supports_mesh_join(): void
    {
        Event::fake([CallSignal::class]);
        $conversation = $this->groupConversation();

        $start = $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation}/calls", [
            'type' => 'audio',
        ]);
        $start->assertCreated()
            ->assertJsonPath('data.is_group', true)
            ->assertJsonPath('data.group_name', 'Team');
        $uuid = $start->json('data.uuid');

        // Both other members were rung.
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'ring' && $e->toUserUuid === $this->bob->uuid);
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'ring' && $e->toUserUuid === $this->carol->uuid);

        // Bob joins: gets the caller in joined_peers.
        $bobJoin = $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept']);
        $bobJoin->assertOk()->assertJsonPath('data.status', 'ongoing');
        $this->assertEquals([$this->alice->uuid], array_column($bobJoin->json('data.joined_peers'), 'uuid'));

        // Carol joins late (call already ongoing) and sees both peers.
        $carolJoin = $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept']);
        $carolJoin->assertOk();
        $this->assertCount(2, $carolJoin->json('data.joined_peers'));

        // Targeted signalling: carol offers to bob specifically.
        $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/signal", [
            'signal' => 'offer',
            'payload' => ['sdp' => 'fake'],
            'to_uuid' => $this->bob->uuid,
        ])->assertOk();
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'offer' && $e->toUserUuid === $this->bob->uuid);

        // Alice leaves; two remain, so the call keeps going.
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/end")->assertOk();
        $this->assertEquals('ongoing', $bobJoin->json('data.status') === 'ongoing' ? \App\Models\Call::where('uuid', $uuid)->first()->status : 'x');
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'peer-left');

        // Bob leaves; only carol remains, so the call ends.
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/end")->assertOk();
        $this->assertEquals('ended', \App\Models\Call::where('uuid', $uuid)->first()->status);
    }

    public function test_group_call_decline_does_not_end_the_call(): void
    {
        Event::fake([CallSignal::class]);
        $conversation = $this->groupConversation();

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->json('data.uuid');

        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'decline'])->assertOk();

        // Still ringing for carol.
        $this->assertEquals('ringing', \App\Models\Call::where('uuid', $uuid)->first()->status);
        $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();
        $this->assertEquals('ongoing', \App\Models\Call::where('uuid', $uuid)->first()->status);
    }

    /**
     * Closing a tab never calls /end, so without presence the person stays
     * "joined" forever and everyone else keeps a frozen tile for them.
     */
    public function test_a_silent_participant_is_dropped_and_the_call_ends(): void
    {
        Event::fake([CallSignal::class]);
        $this->connect($this->alice, $this->bob);
        $conversation = \App\Models\Conversation::directBetween($this->alice, $this->bob);

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->json('data.uuid');
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();

        // Both are in and beating.
        $beat = $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/heartbeat")->assertOk();
        $this->assertEquals('ongoing', $beat->json('data.status'));
        $this->assertCount(2, $beat->json('data.participants'));

        // Bob's browser vanishes: no /end, just silence.
        $call = \App\Models\Call::where('uuid', $uuid)->first();
        $stale = now()->subSeconds(\App\Models\Call::PRESENCE_TIMEOUT_SECONDS + 10);
        \Illuminate\Support\Facades\DB::table('call_participants')
            ->where('call_id', $call->id)->where('user_id', $this->bob->id)
            ->update(['last_seen_at' => $stale]);
        \Illuminate\Support\Facades\DB::table('call_participants')
            ->where('call_id', $call->id)->where('user_id', $this->alice->id)
            ->update(['last_seen_at' => now()]);
        $call->update(['answered_at' => $stale]);

        $this->artisan('mypa:reap-meetings')->assertSuccessful();

        // Bob is out, Alice was told, and a call of one is no call at all.
        $this->assertEquals('left', \Illuminate\Support\Facades\DB::table('call_participants')
            ->where('call_id', $call->id)->where('user_id', $this->bob->id)->value('status'));
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'peer-left');
        $this->assertEquals('ended', $call->fresh()->status);
    }

    public function test_an_ongoing_call_can_be_rejoined_and_reports_who_is_in_it(): void
    {
        Event::fake([CallSignal::class]);
        $conversation = $this->groupConversation();

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->json('data.uuid');
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();

        $listed = fn ($user) => collect($this->actingAs($user)->getJson('/api/v1/calls/history')->json('data'))
            ->firstWhere('uuid', $uuid);

        $row = $listed($this->carol);
        $this->assertTrue($row['is_active'], 'A live call has to be recognisable as one.');
        $this->assertEquals(2, $row['joined_count']);
        $this->assertEqualsCanonicalizing(['Alice', 'Bob'], $row['joined_names']);

        // Carol walks in late — the same accept a ringing callee uses.
        $join = $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();
        $this->assertCount(2, $join->json('data.joined_peers'), 'She needs both peers to offer to.');
        $this->assertEquals(3, $listed($this->alice)['joined_count']);

        // A finished call is no longer joinable.
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/end")->assertOk();
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/end")->assertOk();
        $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/end")->assertOk();
        $this->assertFalse($listed($this->carol)['is_active']);
    }

    public function test_direct_calls_still_work_one_to_one(): void
    {
        Event::fake([CallSignal::class]);
        $this->connect($this->alice, $this->bob);
        $conversation = \App\Models\Conversation::directBetween($this->alice, $this->bob);

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->json('data.uuid');

        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])
            ->assertOk()->assertJsonPath('data.status', 'ongoing');

        // Untargeted signal falls back to "the other side".
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/signal", [
            'signal' => 'offer', 'payload' => ['sdp' => 'x'],
        ])->assertOk();

        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/end")->assertOk();
        $this->assertEquals('ended', \App\Models\Call::where('uuid', $uuid)->first()->status);

        // The finished call leaves a record in the chat.
        $log = \App\Models\Message::where('conversation_id', $conversation->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('call', $log->body);
    }

    public function test_invite_someone_into_a_live_call(): void
    {
        Event::fake([CallSignal::class]);
        $this->carol->forceFill(['username' => 'carol1'])->save();
        $this->connect($this->alice, $this->bob);
        $this->connect($this->alice, $this->carol);
        $conversation = \App\Models\Conversation::directBetween($this->alice, $this->bob);

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->json('data.uuid');
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();

        // Alice pulls Carol in by username; Carol starts ringing.
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/invite", ['identifier' => 'carol1'])->assertOk();
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'ring' && $e->toUserUuid === $this->carol->uuid);

        // Carol accepts the ongoing call and gets both existing peers.
        $join = $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();
        $this->assertCount(2, $join->json('data.joined_peers'));

        // Alice leaves; Bob and Carol keep talking (even though it began 1:1).
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/end")->assertOk();
        $this->assertEquals('ongoing', \App\Models\Call::where('uuid', $uuid)->first()->status);

        // Outsiders cannot invite.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson("/api/v1/calls/{$uuid}/invite", ['identifier' => 'carol1'])->assertForbidden();
    }

    public function test_conversation_members_listing(): void
    {
        $conversation = $this->groupConversation();

        $members = $this->actingAs($this->alice)
            ->getJson("/api/v1/conversations/{$conversation}/members")
            ->assertOk()
            ->json('data');
        $this->assertCount(3, $members);
        $this->assertTrue(collect($members)->firstWhere('name', 'Alice')['is_me']);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/v1/conversations/{$conversation}/members")->assertForbidden();
    }
}
