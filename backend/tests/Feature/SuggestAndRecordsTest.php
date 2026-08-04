<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestAndRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;
    protected User $bob;
    protected User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice', 'username' => 'alice1']);
        $this->bob = User::factory()->create(['name' => 'Bobby', 'username' => 'bobby1', 'email' => 'bobby@mypa.local']);
        $this->stranger = User::factory()->create(['name' => 'Bobcat', 'username' => 'bobcat1']);
        foreach ([$this->alice, $this->bob, $this->stranger] as $u) {
            $appIds->generateFor($u);
        }
        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
        ]);
    }

    public function test_suggest_ranks_connections_first_but_finds_everyone_reachable(): void
    {
        // "bob" matches Bobby (connected) and Bobcat (not). Both are offered —
        // you can add someone to a group without connecting to them first —
        // but the connection sorts first and is flagged as such.
        $out = $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=bob')->assertOk()->json('data');
        $this->assertCount(2, $out);
        $this->assertEquals('Bobby', $out[0]['name']);
        $this->assertTrue($out[0]['connected']);
        $this->assertEquals('Bobcat', $out[1]['name']);
        $this->assertFalse($out[1]['connected']);

        // Matching by email works; empty query returns nothing.
        $this->assertCount(1, $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=bobby@mypa')->json('data'));
        $this->assertCount(0, $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=')->json('data'));

        // Someone with no connections at all is no longer stuck with an empty
        // dropdown — that was the whole bug. (Bobcat sees Bobby, not himself.)
        $alone = $this->actingAs($this->stranger)->getJson('/api/v1/connections/suggest?q=bob')->json('data');
        $this->assertCount(1, $alone);
        $this->assertEquals('Bobby', $alone[0]['name']);
        $this->assertFalse($alone[0]['connected']);

        // You never see yourself in your own suggestions.
        $mine = $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=alice')->json('data');
        $this->assertCount(0, $mine);
    }

    public function test_suggest_finds_people_by_app_id(): void
    {
        $appId = $this->stranger->appId->app_id;

        $out = $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=' . $appId)->assertOk()->json('data');
        $this->assertCount(1, $out);
        $this->assertEquals('Bobcat', $out[0]['name']);
        $this->assertEquals($appId, $out[0]['app_id']);
    }

    public function test_suggest_respects_privacy_blocks_and_inactive_accounts(): void
    {
        $find = fn (User $viewer, string $q) => collect(
            $this->actingAs($viewer)->getJson("/api/v1/connections/suggest?q={$q}")->json('data')
        )->pluck('name')->all();

        // "Findable by nobody" drops out for strangers...
        $this->stranger->settings()->create(['privacy' => ['who_can_find_me' => 'nobody']]);
        $this->assertEquals(['Bobby'], $find($this->alice, 'bob'));

        // ...and so does "connections only" when you are not connected.
        $this->stranger->settings->update(['privacy' => ['who_can_find_me' => 'connections']]);
        $this->assertEquals(['Bobby'], $find($this->alice, 'bob'));

        // A connection's own setting never hides them from you.
        $this->bob->settings()->create(['privacy' => ['who_can_find_me' => 'connections']]);
        $this->assertContains('Bobby', $find($this->alice, 'bob'));

        // Blocks cut both ways.
        $this->stranger->settings->update(['privacy' => ['who_can_find_me' => 'everyone']]);
        $this->alice->blockedUsers()->attach($this->stranger->id);
        $this->assertEquals(['Bobby'], $find($this->alice, 'bob'));
        $this->alice->blockedUsers()->detach($this->stranger->id);
        $this->stranger->blockedUsers()->attach($this->alice->id);
        $this->assertEquals(['Bobby'], $find($this->alice->fresh(), 'bob'));
        $this->stranger->blockedUsers()->detach($this->alice->id);

        // Suspended accounts are not offered.
        $this->stranger->update(['status' => 'suspended']);
        $this->assertEquals(['Bobby'], $find($this->alice, 'bob'));
    }

    public function test_suggest_does_not_hand_out_a_strangers_email(): void
    {
        $out = collect($this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=bob')->json('data'))
            ->keyBy('name');

        // Your own connection's email is fair game — you already have it.
        $this->assertEquals('bobby@mypa.local', $out['Bobby']['email']);
        // A stranger is identified by username and App ID, not their email.
        $this->assertNull($out['Bobcat']['email']);
        $this->assertEquals('bobcat1', $out['Bobcat']['username']);
        $this->assertNotNull($out['Bobcat']['app_id']);
    }

    public function test_a_group_member_can_be_added_without_being_a_connection(): void
    {
        $group = $this->actingAs($this->alice)->postJson('/api/v1/groups', [
            'name' => 'Site team', 'type' => 'team',
        ])->assertCreated()->json('data');

        // Bobcat is a stranger to Alice; adding by username still works.
        $this->actingAs($this->alice)->postJson("/api/v1/groups/{$group['uuid']}/members", [
            'app_id' => 'bobcat1', 'role' => 'member',
        ])->assertStatus(201);

        $members = $this->actingAs($this->alice)->getJson("/api/v1/groups/{$group['uuid']}")->json('data.members');
        $this->assertContains('Bobcat', collect($members)->pluck('name')->all());
    }

    public function test_admin_records_are_metadata_only(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);

        // A conversation with a message whose content must never leak.
        $conversation = \App\Models\Conversation::directBetween($this->alice, $this->bob);
        $conversation->messages()->create([
            'user_id' => $this->alice->id,
            'type' => 'text',
            'body' => 'TOP-SECRET-CONTENT',
        ]);
        $conversation->update(['last_message_at' => now()]);

        $chats = $this->actingAs($admin)->getJson("/api/v1/admin/users/{$this->alice->uuid}/message-records")->assertOk();
        $this->assertEquals(1, $chats->json('data.0.messages_count'));
        $this->assertStringNotContainsString('TOP-SECRET-CONTENT', $chats->getContent());

        // Call records: metadata visible, nothing else exists to leak.
        $call = $conversation->calls()->create([
            'caller_id' => $this->alice->id,
            'type' => 'audio',
            'status' => 'ended',
            'started_at' => now()->subMinutes(5),
            'answered_at' => now()->subMinutes(5),
            'ended_at' => now()->subMinutes(2),
        ]);
        $call->participants()->attach([
            $this->alice->id => ['status' => 'joined'],
            $this->bob->id => ['status' => 'joined'],
        ]);

        $calls = $this->actingAs($admin)->getJson("/api/v1/admin/users/{$this->alice->uuid}/call-records")->assertOk();
        $this->assertEquals('ended', $calls->json('data.0.status'));
        $this->assertContains('Bobby', $calls->json('data.0.participants'));

        // Regular users cannot read records.
        $this->actingAs($this->bob)->getJson("/api/v1/admin/users/{$this->alice->uuid}/call-records")->assertForbidden();
    }

    public function test_ended_meeting_summary_in_list(): void
    {
        $meeting = $this->actingAs($this->alice)->postJson('/api/v1/meetings', ['title' => 'Retro', 'requires_approval' => false])->json('data');
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/end")->assertOk();

        $row = collect($this->actingAs($this->alice)->getJson('/api/v1/meetings')->json('data'))
            ->firstWhere('code', $meeting['code']);
        $this->assertEquals('ended', $row['status']);
        $this->assertNotNull($row['duration_seconds']);
        $this->assertEqualsCanonicalizing(['Alice', 'Bobby'], $row['participants']);
    }
}
