<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Services\LiveKitTokenService;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Join tokens for the SFU.
 *
 * The token is the whole authorisation — LiveKit never calls back to ask
 * whether the bearer is still welcome — so everything the server decides has
 * to be settled before it is signed, and nothing may hand one to somebody the
 * room has not already admitted.
 */
class LiveKitTokenTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;

    protected User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $this->host = User::factory()->create(['name' => 'Himanshu Sachdeva']);
        $this->host->profile()->create(['timezone' => 'UTC']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->alice->profile()->create(['timezone' => 'UTC']);

        config([
            'livekit.enabled' => true,
            'livekit.url' => 'wss://sfu.netvork.app',
            'livekit.api_key' => 'APItest',
            'livekit.api_secret' => 'secret-secret-secret-secret',
            'livekit.mesh_up_to' => null,
        ]);
    }

    private function meeting(): Meeting
    {
        return Meeting::create([
            'code' => Meeting::generateCode(),
            'host_id' => $this->host->id,
            'type' => 'video',
            'requires_approval' => false,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }

    /** Decode without verifying — the test is about the claims, not the crypto. */
    private function claims(string $jwt): array
    {
        [, $payload] = explode('.', $jwt);

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    public function test_somebody_in_the_room_gets_a_token_for_it(): void
    {
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $res = $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")
            ->assertOk()
            ->assertJsonPath('data.url', 'wss://sfu.netvork.app')
            ->assertJsonPath('data.room', "meeting-{$meeting->code}");

        $claims = $this->claims($res->json('data.token'));
        $this->assertSame('APItest', $claims['iss']);
        $this->assertSame($this->alice->uuid, $claims['sub'], 'identity is the uuid the rest of the room already uses');
        $this->assertSame("meeting-{$meeting->code}", $claims['video']['room']);
        $this->assertTrue($claims['video']['roomJoin']);
        $this->assertTrue($claims['video']['canPublish']);
    }

    public function test_somebody_who_has_not_joined_gets_nothing(): void
    {
        // The token would route around every check join() makes — the
        // passcode, the lock, the waiting room, the plan limit, the removal.
        $meeting = $this->meeting();

        $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")
            ->assertForbidden();
    }

    public function test_somebody_still_in_the_waiting_room_gets_nothing(): void
    {
        $meeting = $this->meeting();
        $meeting->update(['requires_approval' => true]);

        $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/join")
            ->assertStatus(202);

        $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")
            ->assertForbidden();
    }

    public function test_only_a_moderator_may_moderate(): void
    {
        // Muting somebody is a server-side act on LiveKit. Without the grant
        // the request is refused, which is what should happen to a participant
        // who asks for it.
        $meeting = $this->meeting();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $hostToken = $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")->json('data.token');
        $aliceToken = $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")->json('data.token');

        $this->assertTrue($this->claims($hostToken)['video']['roomAdmin']);
        $this->assertFalse($this->claims($aliceToken)['video']['roomAdmin']);
    }

    public function test_an_ended_meeting_hands_out_nothing(): void
    {
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $meeting->update(['status' => 'ended']);

        $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")
            ->assertStatus(410);
    }

    public function test_a_server_without_livekit_says_so_rather_than_signing_nothing(): void
    {
        // An unsigned or blank-secret token would be worse than an error: it
        // looks like it worked right up until the connection is refused.
        config(['livekit.enabled' => false]);
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")
            ->assertStatus(503);
    }

    public function test_the_token_expires(): void
    {
        config(['livekit.token_ttl_minutes' => 10]);
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $claims = $this->claims($this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")->json('data.token'));

        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 601, $claims['exp']);
    }

    public function test_the_signature_is_over_the_header_and_the_claims(): void
    {
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $jwt = $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/realtime-token")->json('data.token');

        [$header, $payload, $signature] = explode('.', $jwt);
        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "{$header}.{$payload}", config('livekit.api_secret'), true),
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $signature, 'LiveKit will reject anything else');
        $this->assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr($header, '-_', '+/')), true));
    }

    // --- Which transport a meeting runs on ---------------------------------

    public function test_the_server_decides_the_transport_so_a_room_cannot_split(): void
    {
        // Half a room on the mesh and half on the SFU would simply not see the
        // other half, so this is never left to the client.
        $meeting = $this->meeting();

        $this->actingAs($this->alice)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertJsonPath('data.transport', 'sfu');

        config(['livekit.enabled' => false]);
        $this->actingAs($this->alice)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertJsonPath('data.transport', 'mesh');
    }

    /** Someone new, in the room, with the pivot the controller expects. */
    private function joiner(Meeting $meeting): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        return $user;
    }

    public function test_a_small_room_stays_on_the_mesh(): void
    {
        // No server in the middle means lower latency and no bandwidth bill, so
        // the SFU should earn its keep rather than be used by default.
        config(['livekit.mesh_up_to' => 4]);
        $meeting = $this->meeting();

        $this->joiner($meeting);
        $this->joiner($meeting);

        $this->actingAs($this->alice)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertJsonPath('data.transport', 'mesh');
        $this->assertNull($meeting->fresh()->transport, 'nothing should be written down until it escalates');
    }

    public function test_everybody_moves_together_when_the_room_outgrows_the_mesh(): void
    {
        /*
         * The regression this exists for, and the reason the transport is
         * written down at all.
         *
         * It used to be recomputed from the live headcount on every request,
         * and each person is told which transport to use exactly once, on the
         * way in. So a threshold of four told the first four "mesh" and the
         * fifth "sfu": four people carried on meshing with each other, the
         * fifth sat alone in an empty room, and nothing anywhere reported an
         * error. Both halves were working perfectly. They were just two
         * different meetings.
         *
         * So this does not assert that the fifth person gets the SFU. It
         * asserts that the four already inside are told to move as well.
         */
        // Three, not five: the free plan caps a meeting at four people, and a
        // test that needed a fifth would be testing the plan limit instead.
        config(['livekit.mesh_up_to' => 2]);
        $meeting = $this->meeting();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $inside = [$this->alice, $this->joiner($meeting)];

        // Two in the room, which is what the mesh was said to carry.
        foreach ($inside as $person) {
            $this->actingAs($person)
                ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
                ->assertJsonPath('data.transport', 'mesh');
        }

        $third = $this->joiner($meeting);

        // The new arrival, and — the part that matters — everybody who was
        // already sitting there.
        foreach ([...$inside, $third] as $person) {
            $this->actingAs($person)
                ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
                ->assertJsonPath('data.transport', 'sfu');
        }
    }

    public function test_the_room_is_told_to_move_at_once_rather_than_on_the_next_beat(): void
    {
        /*
         * Fifteen seconds of everybody dialling somebody who has already been
         * told to use the SFU is not a seamless handover, so the arrival that
         * tips the room over carries the instruction to move with it.
         */
        config(['livekit.mesh_up_to' => 2]);
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $second = $this->joiner($meeting);

        \Illuminate\Support\Facades\Event::fake([\App\Events\MeetingSignal::class]);
        $this->joiner($meeting);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\MeetingSignal::class,
            fn ($e) => $e->signalType === 'transport'
                && ($e->payload['transport'] ?? null) === 'sfu'
                && $e->toUserUuid === $this->alice->uuid,
        );
        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\MeetingSignal::class,
            fn ($e) => $e->signalType === 'transport' && $e->toUserUuid === $second->uuid,
        );
    }

    public function test_it_only_moves_one_way(): void
    {
        /*
         * A room hovering at the threshold would otherwise migrate every time
         * anybody came or went, and a migration is disruptive in a way that
         * forwarding four streams is not. The SFU carries a small room
         * perfectly well; going back is not worth a meeting that flickers.
         */
        config(['livekit.mesh_up_to' => 2]);
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->joiner($meeting);
        $third = $this->joiner($meeting);

        $this->assertSame('sfu', $meeting->fresh()->transport);

        $this->actingAs($third)->postJson("/api/v1/meetings/{$meeting->code}/leave")->assertOk();

        $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->assertJsonPath('data.transport', 'sfu');
    }

    public function test_a_server_without_livekit_is_never_recorded_as_having_escalated(): void
    {
        // Otherwise a meeting held while LiveKit was down would be stuck
        // pointing at an SFU that was never there.
        config(['livekit.enabled' => false, 'livekit.mesh_up_to' => 1]);
        $meeting = $this->meeting();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->joiner($meeting);

        $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->assertJsonPath('data.transport', 'mesh');
        $this->assertNull($meeting->fresh()->transport);
    }

    public function test_the_room_name_is_namespaced(): void
    {
        // A LiveKit server shared with anything else must not have its rooms
        // collide with ours.
        $meeting = $this->meeting();
        $this->assertSame(
            "meeting-{$meeting->code}",
            app(LiveKitTokenService::class)->roomFor($meeting),
        );
    }
}
