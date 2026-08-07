<?php

namespace Tests\Feature;

use App\Events\CallSignal;
use App\Events\MeetingSignal;
use App\Models\Group;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Whose name is on a signal.
 *
 * A client names a new peer from the `from_name` field of the signal that
 * introduced them. CallSignal used to fill that field with the name of
 * whoever *started* the call, no matter who had actually sent the signal, so
 * in a call of three everybody's tile ended up labelled with one person's
 * name. These tests read the broadcast payload rather than the event object,
 * because the payload is what the browser sees.
 */
class SignalSenderNameTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected User $carol;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->carol = User::factory()->create(['name' => 'Carol']);

        foreach ([$this->alice, $this->bob, $this->carol] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }
    }

    /** Every signal that went out, as the browser would receive it. */
    private function payloads(string $event): array
    {
        $out = [];
        foreach (Event::dispatched($event) as [$e]) {
            $out[] = $e->broadcastWith();
        }

        return $out;
    }

    private function groupConversation(): string
    {
        $group = Group::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'owner_id' => $this->alice->id,
            'name' => 'Team',
            'type' => 'team',
        ]);
        $group->members()->attach([
            $this->alice->id => ['role' => 'owner'],
            $this->bob->id => ['role' => 'member'],
            $this->carol->id => ['role' => 'member'],
        ]);

        return $this->actingAs($this->alice)
            ->getJson("/api/v1/groups/{$group->uuid}/conversation")
            ->json('data.uuid');
    }

    public function test_a_call_signal_carries_the_name_of_whoever_sent_it(): void
    {
        $conversation = $this->groupConversation();
        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'video'])
            ->json('data.uuid');

        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();
        $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();

        Event::fake([CallSignal::class]);

        // Carol offers to Bob. Neither of them started the call.
        $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/signal", [
            'signal' => 'offer',
            'payload' => ['sdp' => 'fake'],
            'to_uuid' => $this->bob->uuid,
        ])->assertOk();

        $offer = collect($this->payloads(CallSignal::class))
            ->firstWhere('signal', 'offer');

        $this->assertNotNull($offer, 'the offer should have been broadcast');
        $this->assertSame($this->carol->uuid, $offer['from_uuid']);
        $this->assertSame(
            'Carol',
            $offer['from_name'],
            'the signal must be named for its sender, not for whoever started the call',
        );
    }

    public function test_every_participant_keeps_their_own_name_across_a_mesh(): void
    {
        $conversation = $this->groupConversation();
        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'video'])
            ->json('data.uuid');
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();
        $this->actingAs($this->carol)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'accept'])->assertOk();

        Event::fake([CallSignal::class]);

        $senders = [
            [$this->alice, $this->bob, 'Alice'],
            [$this->bob, $this->carol, 'Bob'],
            [$this->carol, $this->alice, 'Carol'],
        ];
        foreach ($senders as [$from, $to]) {
            $this->actingAs($from)->postJson("/api/v1/calls/{$uuid}/signal", [
                'signal' => 'ice',
                'payload' => ['candidate' => 'fake'],
                'to_uuid' => $to->uuid,
            ])->assertOk();
        }

        $byUuid = collect($this->payloads(CallSignal::class))
            ->where('signal', 'ice')
            ->keyBy('from_uuid');

        foreach ($senders as [$from, , $name]) {
            $this->assertSame($name, $byUuid[$from->uuid]['from_name'] ?? null);
        }

        // The real symptom: three signals, three different names.
        $this->assertCount(3, $byUuid->pluck('from_name')->unique());
    }

    public function test_the_person_who_declines_is_named_not_the_caller(): void
    {
        \App\Models\Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        // `app_id` lives on a related row, not on the users table — reading it
        // off the model gives null, which the endpoint answers with a 404 that
        // then surfaces two calls later as an unrelated-looking failure.
        $conversation = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->assertCreated()
            ->json('data.uuid');
        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->assertCreated()
            ->json('data.uuid');

        Event::fake([CallSignal::class]);
        $this->actingAs($this->bob)->postJson("/api/v1/calls/{$uuid}/respond", ['action' => 'decline'])->assertOk();

        $decline = collect($this->payloads(CallSignal::class))->firstWhere('signal', 'decline');
        $this->assertSame('Bob', $decline['from_name'] ?? null);
    }

    public function test_a_meeting_signal_carries_the_name_of_whoever_sent_it(): void
    {
        $meeting = Meeting::create([
            'code' => 'aaa-bbbb-ccc',
            'host_id' => $this->alice->id,
            'title' => 'Standup',
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
        ]);

        foreach ([$this->alice, $this->bob, $this->carol] as $user) {
            $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        }

        Event::fake([MeetingSignal::class]);

        $this->actingAs($this->carol)->postJson("/api/v1/meetings/{$meeting->code}/signal", [
            'signal' => 'offer',
            'payload' => ['sdp' => 'fake'],
            'to_uuid' => $this->bob->uuid,
        ])->assertOk();

        $offer = collect($this->payloads(MeetingSignal::class))->firstWhere('signal', 'offer');
        $this->assertSame($this->carol->uuid, $offer['from_uuid'] ?? null);
        $this->assertSame('Carol', $offer['from_name'] ?? null);
    }

    public function test_a_meeting_roster_names_everyone_separately(): void
    {
        $meeting = Meeting::create([
            'code' => 'ddd-eeee-fff',
            'host_id' => $this->alice->id,
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        // Bob joins under a display name; that must not leak onto anyone else.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting->code}/join", [
            'display_name' => 'Bobby on the train',
        ])->assertOk();

        $carol = $this->actingAs($this->carol)
            ->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $names = collect($carol->json('data.joined_peers'))->pluck('name', 'uuid');
        $this->assertSame('Alice', $names[$this->alice->uuid] ?? null);
        $this->assertSame('Bobby on the train', $names[$this->bob->uuid] ?? null);

        $roster = $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->json('data.participants');
        $this->assertCount(3, collect($roster)->pluck('name')->unique());
    }

    public function test_an_admin_ending_a_live_meeting_is_named_as_themselves(): void
    {
        $admin = User::factory()->create(['name' => 'Admin Person']);
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $admin->settings()->create([]);

        $meeting = Meeting::create([
            'code' => 'ggg-hhhh-iii',
            'host_id' => $this->alice->id,
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
        ]);
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        Event::fake([MeetingSignal::class]);
        $this->actingAs($admin)->deleteJson("/api/v1/admin/live-meetings/{$meeting->code}")->assertOk();

        $end = collect($this->payloads(MeetingSignal::class))->firstWhere('signal', 'end');
        $this->assertSame('Admin Person', $end['from_name'] ?? null);
    }
}
