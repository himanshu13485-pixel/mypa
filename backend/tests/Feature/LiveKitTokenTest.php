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

    public function test_the_threshold_is_read_from_the_plan_and_not_from_who_is_in_the_room(): void
    {
        /*
         * The regression this exists for.
         *
         * The threshold used to be compared against a live headcount, which
         * reads as obviously right and splits rooms in half: the transport is
         * settled per person as they arrive, so with a threshold of four the
         * first four were told "mesh" and the fifth was told "sfu". Four people
         * carried on meshing with each other, the fifth sat alone in an empty
         * room, and nothing anywhere reported an error.
         *
         * So the assertion is not "small rooms use the mesh". It is that the
         * answer does not move while people are walking in.
         */
        config(['livekit.mesh_up_to' => 4]);
        $meeting = $this->meeting();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $first = $this->actingAs($this->alice)
            ->getJson("/api/v1/meetings/{$meeting->code}")->json('data.transport');

        // Well past the threshold, one at a time, exactly as a real room fills.
        foreach (range(1, 5) as $i) {
            $user = User::factory()->create();
            $user->profile()->create(['timezone' => 'UTC']);
            $this->actingAs($user)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

            $this->actingAs($user)
                ->getJson("/api/v1/meetings/{$meeting->code}")
                ->assertJsonPath('data.transport', $first);

            // And the people already inside are still told the same thing, so
            // nobody is left on a transport the room has moved off.
            $this->actingAs($this->alice)
                ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
                ->assertJsonPath('data.transport', $first);
        }
    }

    public function test_a_plan_that_cannot_outgrow_the_mesh_stays_direct(): void
    {
        // No server in the middle means lower latency and no bandwidth bill, so
        // the SFU should earn its keep rather than be used by default. A host
        // whose plan caps the room at or below the threshold can never need it.
        $limit = app(\App\Services\SubscriptionEntitlementService::class)
            ->meetingParticipantLimit($this->host);
        $this->assertNotNull($limit, 'this test needs a host whose plan actually caps the room');

        config(['livekit.mesh_up_to' => $limit]);
        $meeting = $this->meeting();
        $this->actingAs($this->alice)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertJsonPath('data.transport', 'mesh');

        // One below the ceiling and the room can outgrow the mesh, so it starts
        // on the SFU and stays there — rather than moving once it is too late.
        config(['livekit.mesh_up_to' => $limit - 1]);
        $this->actingAs($this->alice)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertJsonPath('data.transport', 'sfu');
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
