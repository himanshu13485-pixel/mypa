<?php

namespace Tests\Feature;

use App\Events\MeetingSignal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;
    protected User $alice;
    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->host = User::factory()->create(['name' => 'Host']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
    }

    public function test_full_meeting_lifecycle(): void
    {
        Event::fake([MeetingSignal::class]);

        // Host creates a meeting and gets a shareable code.
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', [
            'title' => 'Site review', 'requires_approval' => false,
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $meeting['code']);

        // Anyone signed in with the code can look it up and join.
        $this->actingAs($this->alice)->getJson("/api/v1/meetings/{$meeting['code']}")->assertOk();
        $join = $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertEquals('active', $join->json('data.status'));
        $this->assertCount(0, $join->json('data.joined_peers'));

        // Host joins; sees Alice as an existing peer; Alice is notified.
        $join = $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertCount(1, $join->json('data.joined_peers'));
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'join' && $e->toUserUuid === $this->alice->uuid);

        // Targeted signalling relay.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/signal", [
            'signal' => 'offer',
            'payload' => ['sdp' => 'x', 'type' => 'offer'],
            'to_uuid' => $this->alice->uuid,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'offer' && $e->toUserUuid === $this->alice->uuid);

        // Non-participants cannot signal.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/signal", [
            'signal' => 'offer', 'payload' => ['sdp' => 'x'], 'to_uuid' => $this->alice->uuid,
        ])->assertForbidden();

        // Bob joins late (meeting already active) and meshes with both.
        $join = $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertCount(2, $join->json('data.joined_peers'));

        // Only the host can end for everyone.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/end")->assertForbidden();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/end")->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'end');

        // An ended meeting cannot be re-joined.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertStatus(410);
    }

    public function test_display_name_per_meeting(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');

        // Join with a custom name; a later joiner sees it in joined_peers.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join", [
            'display_name' => 'Boss',
        ])->assertOk();
        $join = $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertEquals('Boss', $join->json('data.joined_peers.0.name'));

        // Rename mid-meeting broadcasts to the others.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/name", [
            'display_name' => 'Alice (Site A)',
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'rename' && $e->payload['name'] === 'Alice (Site A)');

        // Outsiders cannot rename.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/name", [
            'display_name' => 'Intruder',
        ])->assertForbidden();
    }

    public function test_reactions_broadcast_to_the_room(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/react", [
            'emoji' => 'thumbsup',
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'react'
            && $e->payload['emoji'] === 'thumbsup' && $e->toUserUuid === $this->host->uuid);

        // Unknown emoji rejected; outsiders rejected.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/react", [
            'emoji' => 'rocketship',
        ])->assertStatus(422);
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/react", [
            'emoji' => 'thumbsup',
        ])->assertForbidden();
    }

    public function test_screen_sessions_are_separate_from_meetings(): void
    {
        $screen = $this->actingAs($this->host)->postJson('/api/v1/meetings', [
            'is_screen' => true, 'title' => 'Screen share',
        ])->assertCreated()->json('data');
        $this->assertTrue($screen['is_screen']);
        $this->actingAs($this->host)->postJson('/api/v1/meetings', ['title' => 'Normal'])->assertCreated();

        // Meetings list excludes screen sessions and vice versa.
        $meetings = $this->actingAs($this->host)->getJson('/api/v1/meetings')->json('data');
        $screens = $this->actingAs($this->host)->getJson('/api/v1/meetings?screen=1')->json('data');
        $this->assertCount(1, $meetings);
        $this->assertEquals('Normal', $meetings[0]['title']);
        $this->assertCount(1, $screens);
        $this->assertEquals('Screen share', $screens[0]['title']);
    }

    public function test_meeting_ends_itself_when_everyone_leaves(): void
    {
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/leave")->assertOk();
        $this->assertEquals('active', \App\Models\Meeting::where('code', $meeting['code'])->first()->status);

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/leave")->assertOk();
        $this->assertEquals('ended', \App\Models\Meeting::where('code', $meeting['code'])->first()->status);
    }

    public function test_waiting_room_host_approval_flow(): void
    {
        Event::fake([MeetingSignal::class]);
        // Default meeting requires approval.
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', [])->json('data');
        $this->assertTrue($meeting['requires_approval']);

        // Host joins directly; Alice knocks and waits.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $wait = $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertStatus(202);
        $this->assertTrue($wait->json('data.waiting'));
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'knock' && $e->toUserUuid === $this->host->uuid);

        // Only the host can admit.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/admit", [
            'user_uuid' => $this->alice->uuid, 'allow' => true,
        ])->assertForbidden();

        // Host admits Alice -> she is notified and can now join for real.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/admit", [
            'user_uuid' => $this->alice->uuid, 'allow' => true,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'admitted' && $e->toUserUuid === $this->alice->uuid);
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        // Bob knocks and is denied.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertStatus(202);
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/admit", [
            'user_uuid' => $this->bob->uuid, 'allow' => false,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'denied' && $e->toUserUuid === $this->bob->uuid);

        // Host bypasses the waiting room -> Bob still waits? No: open access now.
        $this->actingAs($this->host)->putJson("/api/v1/meetings/{$meeting['code']}/approval", [
            'requires_approval' => false,
        ])->assertOk();
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
    }

    public function test_meeting_chat_everyone_and_private(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        foreach ([$this->host, $this->alice, $this->bob] as $u) {
            $this->actingAs($u)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        }

        // To everyone: both others receive it.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'Welcome all',
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat'
            && $e->payload['message'] === 'Welcome all' && ! $e->payload['private'] && $e->toUserUuid === $this->alice->uuid);
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat' && $e->toUserUuid === $this->bob->uuid);

        // Private: only Alice gets it, flagged private.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'just for you', 'to_uuid' => $this->alice->uuid,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat'
            && $e->payload['message'] === 'just for you' && $e->payload['private'] && $e->toUserUuid === $this->alice->uuid);
        Event::assertNotDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat'
            && $e->payload['message'] === 'just for you' && $e->toUserUuid === $this->bob->uuid);

        // Non-participants cannot chat.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'hi',
        ])->assertForbidden();
    }

    public function test_meetings_index_lists_hosted_and_attended(): void
    {
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['title' => 'A', 'requires_approval' => false])->json('data');
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->assertCount(1, $this->actingAs($this->host)->getJson('/api/v1/meetings')->json('data'));
        $this->assertCount(1, $this->actingAs($this->alice)->getJson('/api/v1/meetings')->json('data'));
        $this->assertCount(0, $this->actingAs($this->bob)->getJson('/api/v1/meetings')->json('data'));
    }
}
