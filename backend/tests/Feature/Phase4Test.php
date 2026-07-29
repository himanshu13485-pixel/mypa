<?php

namespace Tests\Feature;

use App\Events\CallSignal;
use App\Events\MessageSent;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase4Test extends TestCase
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
        $appIds->generateFor($this->alice);
        $appIds->generateFor($this->bob);
        $this->alice->settings()->create([]);
        $this->bob->settings()->create([]);

        // Connected by default (default privacy: connections can message/call).
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

    // --- Conversations ------------------------------------------------------

    public function test_direct_conversation_created_once(): void
    {
        $first = $this->actingAs($this->alice)->postJson('/api/v1/conversations', [
            'app_id' => $this->bob->appId->app_id,
        ]);
        $first->assertCreated();

        $second = $this->actingAs($this->alice)->postJson('/api/v1/conversations', [
            'app_id' => $this->bob->appId->app_id,
        ]);
        $second->assertCreated();

        $this->assertEquals($first->json('data.uuid'), $second->json('data.uuid'));
        $this->assertEquals(1, Conversation::count());
    }

    public function test_messaging_respects_privacy(): void
    {
        // Bob only accepts messages from nobody.
        $this->bob->settings->update(['privacy' => ['who_can_message' => 'nobody']]);

        $this->actingAs($this->alice)->postJson('/api/v1/conversations', [
            'app_id' => $this->bob->appId->app_id,
        ])->assertForbidden();

        // Stranger without connection blocked under default 'connections' privacy.
        $stranger = User::factory()->create();
        app(AppIdService::class)->generateFor($stranger);
        $stranger->settings()->create([]);

        $this->actingAs($stranger)->postJson('/api/v1/conversations', [
            'app_id' => $this->alice->appId->app_id,
        ])->assertForbidden();
    }

    public function test_send_edit_delete_message_flow(): void
    {
        Event::fake([MessageSent::class]);
        $conversation = $this->converse();

        $send = $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => 'Hello Bob!',
        ]);
        $send->assertCreated()->assertJsonPath('data.body', 'Hello Bob!');
        Event::assertDispatched(MessageSent::class);
        $uuid = $send->json('data.uuid');

        // Bob sees it; Alice edits it; Bob cannot edit it.
        $this->actingAs($this->bob)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Hello Bob!');

        $this->actingAs($this->alice)
            ->putJson("/api/v1/conversations/{$conversation->uuid}/messages/{$uuid}", ['body' => 'Hello Bob! (edited)'])
            ->assertOk()
            ->assertJsonPath('data.body', 'Hello Bob! (edited)');

        $this->actingAs($this->bob)
            ->putJson("/api/v1/conversations/{$conversation->uuid}/messages/{$uuid}", ['body' => 'Hijack'])
            ->assertForbidden();

        // Delete for self (Bob) hides it only for Bob.
        $this->actingAs($this->bob)
            ->deleteJson("/api/v1/conversations/{$conversation->uuid}/messages/{$uuid}?for=me")
            ->assertOk();
        $this->actingAs($this->bob)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages")
            ->assertJsonCount(0, 'data');
        $this->actingAs($this->alice)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages")
            ->assertJsonCount(1, 'data');

        // Delete for everyone (Alice) leaves a tombstone.
        $this->actingAs($this->alice)
            ->deleteJson("/api/v1/conversations/{$conversation->uuid}/messages/{$uuid}?for=everyone")
            ->assertOk();
        $this->actingAs($this->alice)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages")
            ->assertJsonPath('data.0.is_deleted', true)
            ->assertJsonPath('data.0.body', null);
    }

    public function test_outsider_cannot_read_or_post(): void
    {
        $conversation = $this->converse();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages")
            ->assertForbidden();
        $this->actingAs($stranger)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages", ['body' => 'hi'])
            ->assertForbidden();
    }

    public function test_reactions_toggle(): void
    {
        $conversation = $this->converse();
        $message = $conversation->messages()->create([
            'user_id' => $this->alice->id, 'body' => 'React to me',
        ]);

        $this->actingAs($this->bob)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages/{$message->uuid}/react", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('data.reactions.0.emoji', '👍');

        // Same emoji again removes it.
        $this->actingAs($this->bob)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/messages/{$message->uuid}/react", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonCount(0, 'data.reactions');
    }

    public function test_voice_message_attachment(): void
    {
        Storage::fake('local');
        $conversation = $this->converse();

        $response = $this->actingAs($this->alice)->post("/api/v1/conversations/{$conversation->uuid}/messages", [
            'type' => 'voice',
            'attachments' => [UploadedFile::fake()->create('voice.webm', 50, 'audio/webm')],
            'duration_seconds' => 12,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'voice')
            ->assertJsonPath('data.attachments.0.duration_seconds', 12);

        $attachmentId = $response->json('data.attachments.0.id');

        // Member can download, outsider cannot.
        $this->actingAs($this->bob)
            ->get("/api/v1/conversations/{$conversation->uuid}/attachments/{$attachmentId}")
            ->assertOk();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)
            ->get("/api/v1/conversations/{$conversation->uuid}/attachments/{$attachmentId}")
            ->assertForbidden();
    }

    public function test_unread_count_and_mark_read(): void
    {
        $conversation = $this->converse();
        $conversation->messages()->create(['user_id' => $this->alice->id, 'body' => 'One']);
        $conversation->messages()->create(['user_id' => $this->alice->id, 'body' => 'Two']);
        $conversation->update(['last_message_at' => now()]);

        $this->actingAs($this->bob)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.unread_count', 2);

        $this->actingAs($this->bob)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/read")
            ->assertOk();

        $this->actingAs($this->bob)
            ->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.unread_count', 0);
    }

    public function test_group_conversation_syncs_members(): void
    {
        $group = Group::create(['owner_id' => $this->alice->id, 'name' => 'Team', 'type' => 'team']);
        $group->members()->attach([
            $this->alice->id => ['role' => 'owner'],
            $this->bob->id => ['role' => 'member'],
        ]);

        $response = $this->actingAs($this->alice)->getJson("/api/v1/groups/{$group->uuid}/conversation");
        $response->assertOk()->assertJsonPath('data.type', 'group');

        $uuid = $response->json('data.uuid');

        $this->actingAs($this->bob)
            ->postJson("/api/v1/conversations/{$uuid}/messages", ['body' => 'Hi team'])
            ->assertCreated();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/v1/groups/{$group->uuid}/conversation")->assertForbidden();
    }

    // --- Calls --------------------------------------------------------------

    public function test_call_lifecycle_accept_and_end(): void
    {
        Event::fake([CallSignal::class]);
        $conversation = $this->converse();

        $start = $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation->uuid}/calls", [
            'type' => 'video',
        ]);
        $start->assertCreated()->assertJsonPath('data.status', 'ringing');
        $uuid = $start->json('data.uuid');
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'ring');

        // Duplicate call refused while ringing.
        $this->actingAs($this->alice)->postJson("/api/v1/conversations/{$conversation->uuid}/calls", [
            'type' => 'audio',
        ])->assertConflict();

        // Bob accepts.
        $this->actingAs($this->bob)
            ->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ongoing');

        // Signalling relays.
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$uuid}/signal", [
            'signal' => 'offer',
            'payload' => ['sdp' => 'fake-offer'],
        ])->assertOk();
        Event::assertDispatched(CallSignal::class, fn ($e) => $e->signalType === 'offer');

        // Alice hangs up.
        $this->actingAs($this->alice)
            ->postJson("/api/v1/calls/{$uuid}/end")
            ->assertOk()
            ->assertJsonPath('data.status', 'ended');

        // History shows the call for both.
        $this->actingAs($this->bob)
            ->getJson('/api/v1/calls/history')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'ended');
    }

    public function test_declined_and_missed_calls(): void
    {
        Event::fake([CallSignal::class]);
        $conversation = $this->converse();

        // Declined
        $call = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->json('data.uuid');
        $this->actingAs($this->bob)
            ->postJson("/api/v1/calls/{$call}/respond", ['action' => 'decline'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        // Missed: caller cancels while ringing
        $call2 = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->json('data.uuid');
        $this->actingAs($this->alice)
            ->postJson("/api/v1/calls/{$call2}/end")
            ->assertOk()
            ->assertJsonPath('data.status', 'missed');
    }

    public function test_call_privacy_and_authorization(): void
    {
        $conversation = $this->converse();

        // Bob blocks calls entirely.
        $this->bob->settings->update(['privacy' => ['who_can_call' => 'nobody']]);
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->assertForbidden();

        // Outsider cannot signal into someone else's call.
        $this->bob->settings->update(['privacy' => ['who_can_call' => 'connections']]);
        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/calls", ['type' => 'audio'])
            ->json('data.uuid');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson("/api/v1/calls/{$uuid}/signal", [
            'signal' => 'ice', 'payload' => ['candidate' => 'x'],
        ])->assertForbidden();
    }

    public function test_ice_config_endpoint(): void
    {
        $this->actingAs($this->alice)
            ->getJson('/api/v1/calls/config')
            ->assertOk()
            ->assertJsonStructure(['data' => ['iceServers']]);
    }
}
