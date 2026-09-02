<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somebody else's profile, as you are allowed to see it.
 *
 * What it shows narrows with the relationship: a stranger gets what a search
 * result already gives away, a connection also gets the way to reach them.
 */
class PersonProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $them;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->them = $this->person(['name' => 'Priyanshu', 'email' => 'p@netvork.test', 'mobile' => '+911234567890']);
    }

    private function person(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->profile()->create(['timezone' => 'UTC', 'status_text' => 'On leave until the 12th', 'bio' => 'Ships things']);
        $user->settings()->create([]);

        return $user;
    }

    private function connect(): void
    {
        Connection::create([
            'requester_id' => $this->me->id,
            'addressee_id' => $this->them->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    public function test_a_stranger_sees_the_public_half(): void
    {
        $body = $this->actingAs($this->me)
            ->getJson("/api/v1/people/{$this->them->uuid}")
            ->assertOk()->json('data');

        $this->assertSame('Priyanshu', $body['name']);
        $this->assertSame('On leave until the 12th', $body['status']);
        $this->assertFalse($body['is_connected']);

        // Contact details are shared on purpose, so they follow the connection.
        $this->assertNull($body['email']);
        $this->assertNull($body['mobile']);
    }

    public function test_a_connection_also_gets_the_way_to_reach_them(): void
    {
        $this->connect();

        $body = $this->actingAs($this->me)
            ->getJson("/api/v1/people/{$this->them->uuid}")
            ->assertOk()->json('data');

        $this->assertTrue($body['is_connected']);
        $this->assertSame('p@netvork.test', $body['email']);
        $this->assertSame('+911234567890', $body['mobile']);
    }

    public function test_my_own_profile_answers_as_mine(): void
    {
        $body = $this->actingAs($this->me)
            ->getJson("/api/v1/people/{$this->me->uuid}")
            ->assertOk()->json('data');

        $this->assertTrue($body['is_me']);
        $this->assertFalse($body['is_connected']);
        $this->assertSame($this->me->email, $body['email']);
    }

    public function test_a_pending_request_is_reported_with_its_direction(): void
    {
        Connection::create([
            'requester_id' => $this->me->id,
            'addressee_id' => $this->them->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->me)->getJson("/api/v1/people/{$this->them->uuid}")
            ->assertOk()->assertJsonPath('data.request_status', 'sent');

        $this->actingAs($this->them)->getJson("/api/v1/people/{$this->me->uuid}")
            ->assertOk()->assertJsonPath('data.request_status', 'received');
    }

    public function test_somebody_who_hides_from_search_is_not_lookupable_by_a_stranger(): void
    {
        $this->them->settings->update(['privacy' => ['who_can_find_me' => 'connections']]);

        $this->actingAs($this->me)->getJson("/api/v1/people/{$this->them->uuid}")->assertNotFound();

        // But a connection is not a stranger.
        $this->connect();
        $this->actingAs($this->me)->getJson("/api/v1/people/{$this->them->uuid}")->assertOk();
    }

    /** Blocking is mutual silence, whichever side pressed the button. */
    public function test_a_block_hides_the_profile_both_ways(): void
    {
        $this->me->blockedUsers()->attach($this->them->id);

        $this->actingAs($this->me)->getJson("/api/v1/people/{$this->them->uuid}")->assertNotFound();
        $this->actingAs($this->them)->getJson("/api/v1/people/{$this->me->uuid}")->assertNotFound();
    }

    public function test_a_suspended_account_has_no_profile(): void
    {
        $this->them->update(['status' => 'suspended']);

        $this->actingAs($this->me)->getJson("/api/v1/people/{$this->them->uuid}")->assertNotFound();
    }

    public function test_a_status_can_be_set_and_cleared(): void
    {
        $this->actingAs($this->me)->putJson('/api/v1/me/profile', ['status' => '  Heads down on the audit  '])
            ->assertOk();
        // Trimmed on the way in: leading spaces are a typo, not a statement.
        $this->assertSame('Heads down on the audit', $this->me->fresh()->profile->status_text);

        $this->actingAs($this->me)->putJson('/api/v1/me/profile', ['status' => null])->assertOk();
        $this->assertNull($this->me->fresh()->profile->status_text);
    }

    public function test_a_status_longer_than_the_limit_is_refused(): void
    {
        $this->actingAs($this->me)
            ->putJson('/api/v1/me/profile', ['status' => str_repeat('a', 141)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }
}
