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
            'title' => 'Site review',
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
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', [])->json('data');

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

    public function test_meeting_ends_itself_when_everyone_leaves(): void
    {
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', [])->json('data');
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/leave")->assertOk();
        $this->assertEquals('active', \App\Models\Meeting::where('code', $meeting['code'])->first()->status);

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/leave")->assertOk();
        $this->assertEquals('ended', \App\Models\Meeting::where('code', $meeting['code'])->first()->status);
    }

    public function test_meetings_index_lists_hosted_and_attended(): void
    {
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['title' => 'A'])->json('data');
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->assertCount(1, $this->actingAs($this->host)->getJson('/api/v1/meetings')->json('data'));
        $this->assertCount(1, $this->actingAs($this->alice)->getJson('/api/v1/meetings')->json('data'));
        $this->assertCount(0, $this->actingAs($this->bob)->getJson('/api/v1/meetings')->json('data'));
    }
}
