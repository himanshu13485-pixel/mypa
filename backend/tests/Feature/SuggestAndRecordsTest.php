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

    public function test_suggest_matches_only_my_connections(): void
    {
        // "bob" matches both Bobby (connected) and Bobcat (not connected) —
        // only the connection is suggested.
        $out = $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=bob')->assertOk()->json('data');
        $this->assertCount(1, $out);
        $this->assertEquals('Bobby', $out[0]['name']);

        // Matching by email works too; empty query returns nothing.
        $this->assertCount(1, $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=bobby@mypa')->json('data'));
        $this->assertCount(0, $this->actingAs($this->alice)->getJson('/api/v1/connections/suggest?q=')->json('data'));

        // The stranger has no connections, so no suggestions at all.
        $this->assertCount(0, $this->actingAs($this->stranger)->getJson('/api/v1/connections/suggest?q=bob')->json('data'));
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
