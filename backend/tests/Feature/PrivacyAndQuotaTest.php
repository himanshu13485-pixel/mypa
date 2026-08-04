<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AppIdService;
use App\Services\SubscriptionEntitlementService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards for three things that were enforced only at the doorway and then
 * forgotten: blocks, the call-privacy setting, and the storage quota.
 */
class PrivacyAndQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;
    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(PlanSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $u) {
            $appIds->generateFor($u);
            $u->settings()->create([]);
        }

        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    protected function converse(): Conversation
    {
        return Conversation::directBetween($this->alice, $this->bob);
    }

    // --- Blocks -------------------------------------------------------------

    public function test_a_block_stops_messages_in_an_existing_conversation(): void
    {
        $conversation = $this->converse();

        // Both can talk before the block.
        $this->actingAs($this->bob)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'before',
        ])->assertCreated();

        $this->alice->blockedUsers()->attach($this->bob->id);

        // The blocked party is not told a block is the reason.
        $blocked = $this->actingAs($this->bob)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'after',
        ])->assertForbidden();
        $this->assertStringNotContainsString('block', mb_strtolower($blocked->json('message')));

        // The blocker is told plainly, because it is their own setting.
        $mine = $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'also blocked',
        ])->assertForbidden();
        $this->assertStringContainsString('blocked', mb_strtolower($mine->json('message')));

        // Unblocking restores the conversation.
        $this->alice->blockedUsers()->detach($this->bob->id);
        $this->actingAs($this->bob)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'again',
        ])->assertCreated();
    }

    public function test_a_block_stops_calls_too(): void
    {
        $conversation = $this->converse();
        $this->alice->blockedUsers()->attach($this->bob->id);

        $this->actingAs($this->bob)->postJson("/api/v1/conversations/{$conversation->uuid}/calls", [
            'type' => 'audio',
        ])->assertForbidden();

        $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation->uuid}/calls", [
            'type' => 'audio',
        ])->assertForbidden();
    }

    public function test_a_block_does_not_break_a_shared_group_chat(): void
    {
        // Everyone in a group opted into it, so a personal block does not
        // silence the group for them.
        $group = Group::create([
            'owner_id' => $this->alice->id, 'name' => 'Site', 'type' => 'team',
        ]);
        $group->members()->attach([
            $this->alice->id => ['role' => 'owner'],
            $this->bob->id => ['role' => 'member'],
        ]);

        $conversation = $this->actingAs($this->alice)
            ->getJson("/api/v1/groups/{$group->uuid}/conversation")->assertOk()->json('data.uuid');

        $this->alice->blockedUsers()->attach($this->bob->id);

        $this->actingAs($this->bob)->postJson("/api/v1/conversations/{$conversation}/messages", [
            'body' => 'group message',
        ])->assertCreated();
    }

    // --- Call privacy -------------------------------------------------------

    public function test_who_can_call_connections_is_enforced(): void
    {
        $stranger = User::factory()->create(['name' => 'Stranger']);
        app(AppIdService::class)->generateFor($stranger);
        $stranger->settings()->create([]);

        // A conversation can exist without a connection (a group, or one made
        // while the setting was open) — the call check has to stand on its own.
        $conversation = Conversation::directBetween($this->alice, $stranger);

        // Alice's default is 'connections', and they are not connected.
        $this->actingAs($stranger)->postJson("/api/v1/conversations/{$conversation->uuid}/calls", [
            'type' => 'audio',
        ])->assertForbidden();

        // Opening it to everyone lets the call through.
        $this->alice->settings->update(['privacy' => ['who_can_call' => 'everyone']]);
        $this->actingAs($stranger)->postJson("/api/v1/conversations/{$conversation->uuid}/calls", [
            'type' => 'audio',
        ])->assertCreated();
    }

    public function test_who_can_call_is_enforced_when_inviting_into_a_live_call(): void
    {
        $stranger = User::factory()->create(['name' => 'Stranger', 'username' => 'stranger1']);
        app(AppIdService::class)->generateFor($stranger);
        $stranger->settings()->create([]);

        $conversation = $this->converse();
        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->assertCreated()->json('data.uuid');

        // Inviting is calling: the same setting has to apply, or the rule can
        // simply be walked around by starting a call first.
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/invite", [
            'identifier' => 'stranger1',
        ])->assertForbidden();

        Connection::create([
            'requester_id' => $this->alice->id, 'addressee_id' => $stranger->id,
            'status' => 'accepted', 'responded_at' => now(),
        ]);
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/invite", [
            'identifier' => 'stranger1',
        ])->assertOk();
    }

    public function test_who_can_call_nobody_still_refuses(): void
    {
        $conversation = $this->converse();
        $this->bob->settings->update(['privacy' => ['who_can_call' => 'nobody']]);

        $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation->uuid}/calls", [
            'type' => 'audio',
        ])->assertForbidden();
    }

    // --- Last seen ----------------------------------------------------------

    public function test_last_seen_visibility_uses_its_own_setting(): void
    {
        $this->converse();

        $flag = fn () => collect($this->actingAs($this->alice)->getJson('/api/v1/conversations')->json('data'))
            ->first()['other_user']['last_seen_visible'];

        // Connected, defaults are 'connections' -> visible.
        $this->assertTrue($flag());

        // Hiding ONLY last seen must not require touching online status.
        $this->bob->settings->update(['privacy' => ['last_seen_visibility' => 'nobody']]);
        $this->assertFalse($flag());

        // ...and hiding online status alone must not hide last seen.
        $this->bob->settings->update(['privacy' => ['online_status_visibility' => 'nobody']]);
        $this->assertTrue($flag());
    }

    public function test_last_seen_hidden_from_non_connections(): void
    {
        $stranger = User::factory()->create();
        app(AppIdService::class)->generateFor($stranger);
        $stranger->settings()->create([]);
        Conversation::directBetween($this->alice, $stranger);

        // Alice's default is 'connections' and they are not connected.
        $visible = collect($this->actingAs($stranger)->getJson('/api/v1/conversations')->json('data'))
            ->first()['other_user']['last_seen_visible'];
        $this->assertFalse($visible);
    }

    // --- Storage quota ------------------------------------------------------

    public function test_chat_and_meeting_files_count_towards_storage(): void
    {
        Storage::fake('local');
        $entitlements = app(SubscriptionEntitlementService::class);
        $conversation = $this->converse();

        $this->assertEquals(0, $entitlements->usedStorageBytes($this->alice));

        $this->actingAs($this->alice)->post("/api/v1/conversations/{$conversation->uuid}/messages", [
            'attachments' => [UploadedFile::fake()->create('plan.pdf', 200)],
        ])->assertCreated();

        $afterChat = $entitlements->usedStorageBytes($this->alice);
        $this->assertGreaterThan(0, $afterChat, 'Chat attachments must count against storage.');

        // The figure shown on the Files page agrees with the internal total —
        // it used to read straight off the files table and miss all of this.
        $usage = $this->actingAs($this->alice)->getJson('/api/v1/files/usage')->assertOk();
        $this->assertEquals($afterChat, $usage->json('data.used_bytes'));

        // Meeting chat files too.
        $meeting = $this->actingAs($this->alice)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->post("/api/v1/meetings/{$meeting['code']}/chat-file", [
            'file' => UploadedFile::fake()->create('slides.pdf', 150),
        ])->assertOk();

        $this->assertGreaterThan($afterChat, $entitlements->usedStorageBytes($this->alice));
    }

    public function test_chat_attachments_cannot_exceed_the_quota(): void
    {
        Storage::fake('local');
        $conversation = $this->converse();

        // A plan with almost no room left.
        $tiny = Plan::create([
            'slug' => 'tiny', 'name' => 'Tiny', 'monthly_price' => 0, 'annual_price' => 0,
            'limits' => ['storage_bytes' => 50_000], 'features' => [],
            'is_active' => true, 'is_public' => false,
        ]);
        Subscription::create([
            'user_id' => $this->alice->id, 'plan_id' => $tiny->id,
            'status' => 'active', 'started_at' => now(), 'ends_at' => now()->addYear(),
        ]);

        // 200 KB against a 50 KB allowance.
        $this->actingAs($this->alice)->post("/api/v1/conversations/{$conversation->uuid}/messages", [
            'attachments' => [UploadedFile::fake()->create('big.pdf', 200)],
        ])->assertStatus(422);

        // Text still goes through — the limit is on storage, not on talking.
        $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'no attachment here',
        ])->assertCreated();
    }
}
