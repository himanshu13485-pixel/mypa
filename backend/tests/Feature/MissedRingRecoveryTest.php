<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Connection;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ring that never arrived.
 *
 * A ring is one fire-and-forget websocket event, and Reverb does not replay.
 * If the callee's socket was down at that instant — a slept laptop, a tab open
 * since yesterday, a wifi blip — the event is gone and nothing ever asked
 * again, so the popup never appeared. Their phone rang regardless, because
 * push is a separate path that Google queues and retries. Hence the report:
 * their phone rings and the desktop sits there.
 *
 * This is the asking-again. Everything below is about it answering only when
 * there is genuinely a call waiting to be picked up.
 */
class MissedRingRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }

        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /** Alice rings Bob, and hands back the call. */
    private function aliceRingsBob(): Call
    {
        $conversation = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->assertCreated()->json('data.uuid');

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'video'])
            ->assertCreated()->json('data.uuid');

        return Call::where('uuid', $uuid)->firstOrFail();
    }

    public function test_the_callee_can_ask_for_a_ring_the_socket_missed(): void
    {
        $call = $this->aliceRingsBob();

        $data = $this->actingAs($this->bob)->getJson('/api/v1/calls/incoming')->assertOk()->json('data');

        // Deliberately the same shape the websocket delivers, down to the
        // 'signal' key, so the client hands it to the very same handler.
        $this->assertSame($call->uuid, $data['call_uuid']);
        $this->assertSame('ring', $data['signal']);
        $this->assertSame('video', $data['call_type']);
        $this->assertSame($this->alice->uuid, $data['from_uuid']);
        $this->assertSame('Alice', $data['from_name']);
        $this->assertArrayHasKey('conversation_uuid', $data);
    }

    public function test_nothing_is_ringing_is_not_an_error(): void
    {
        $this->assertNull(
            $this->actingAs($this->bob)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_the_caller_is_not_told_they_are_being_called(): void
    {
        $this->aliceRingsBob();

        // Alice joined her own call the moment she made it.
        $this->assertNull(
            $this->actingAs($this->alice)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_a_ring_older_than_the_push_lives_is_not_resurrected(): void
    {
        $this->aliceRingsBob();

        /*
         * A 'ringing' call is never reaped — only 'ongoing' ones are, since a
         * ring has no heartbeat yet — so one whose caller closed their tab
         * sits in the table indefinitely. Without the freshness bound this
         * would cheerfully pop up a call from last Tuesday.
         */
        $this->travel(Call::PRESENCE_TIMEOUT_SECONDS + 5)->seconds();

        $this->assertNull(
            $this->actingAs($this->bob)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_a_ring_just_inside_the_window_still_counts(): void
    {
        $this->aliceRingsBob();
        $this->travel(Call::PRESENCE_TIMEOUT_SECONDS - 5)->seconds();

        $this->assertNotNull(
            $this->actingAs($this->bob)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_answering_stops_it_being_offered_again(): void
    {
        $call = $this->aliceRingsBob();

        $this->actingAs($this->bob)
            ->postJson("/api/v1/calls/{$call->uuid}/respond", ['action' => 'accept'])
            ->assertOk();

        // Otherwise a tab regaining focus mid-call would pop the ring back up
        // over the call it is already in.
        $this->assertNull(
            $this->actingAs($this->bob)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_declining_stops_it_being_offered_again(): void
    {
        $call = $this->aliceRingsBob();

        $this->actingAs($this->bob)
            ->postJson("/api/v1/calls/{$call->uuid}/respond", ['action' => 'decline'])
            ->assertOk();

        $this->assertNull(
            $this->actingAs($this->bob)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_a_call_the_caller_gave_up_on_is_not_offered(): void
    {
        $call = $this->aliceRingsBob();

        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$call->uuid}/end")->assertOk();
        $this->assertSame('missed', $call->fresh()->status);

        $this->assertNull(
            $this->actingAs($this->bob)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_somebody_uninvolved_is_told_nothing(): void
    {
        $this->aliceRingsBob();

        $stranger = User::factory()->create();
        $stranger->settings()->create([]);

        $this->assertNull(
            $this->actingAs($stranger)->getJson('/api/v1/calls/incoming')->assertOk()->json('data'),
        );
    }

    public function test_it_needs_a_session(): void
    {
        $this->getJson('/api/v1/calls/incoming')->assertUnauthorized();
    }
}
