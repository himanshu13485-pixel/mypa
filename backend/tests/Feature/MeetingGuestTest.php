<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Joining a meeting with a passcode and no account. */
class MeetingGuestTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;

    protected Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $this->host = User::factory()->create(['name' => 'Himanshu Sachdeva']);
        $this->host->profile()->create(['timezone' => 'UTC']);

        $this->meeting = Meeting::create([
            'code' => 'abc-defg-hij',
            'host_id' => $this->host->id,
            'title' => 'Client review',
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
            'passcode' => 'open1234',
            'guest_access' => true,
        ]);
    }

    /** @return array{0: string, 1: string} raw token and the guest's uuid */
    protected function joinAsGuest(string $name = 'Prashant'): array
    {
        $res = $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => $name,
            'passcode' => 'open1234',
        ])->assertCreated();

        return [$res->json('data.token'), $res->json('data.guest.uuid')];
    }

    protected function asGuest(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_a_guest_can_join_with_the_passcode_and_no_account(): void
    {
        $res = $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant',
            'passcode' => 'open1234',
        ])->assertCreated();

        $res->assertJsonPath('data.minutes', 30);
        $res->assertJsonPath('data.meeting.title', 'Client review');
        $this->assertNotEmpty($res->json('data.token'));
    }

    public function test_the_pass_is_stored_hashed_never_raw(): void
    {
        [$token] = $this->joinAsGuest();

        $guest = User::withoutGlobalScope('withoutMeetingGuests')->whereNotNull('guest_meeting_id')->firstOrFail();
        $this->assertNotEquals($token, $guest->guest_token);
        $this->assertEquals(hash('sha256', $token), $guest->guest_token);
    }

    public function test_a_guest_can_actually_take_part(): void
    {
        [$token] = $this->joinAsGuest();

        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", ['passcode' => 'open1234'])
            ->assertOk();

        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/heartbeat")
            ->assertOk();

        // And the room can see them.
        $names = $this->meeting->participants()->pluck('name');
        $this->assertTrue($names->contains('Prashant'), 'the guest should be in the room');
    }

    public function test_a_wrong_passcode_is_refused(): void
    {
        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant',
            'passcode' => 'nope0000',
        ])->assertForbidden();
    }

    public function test_guest_access_is_off_unless_the_host_turns_it_on(): void
    {
        $this->meeting->update(['guest_access' => false]);

        // Same answer as a meeting that does not exist: guessing codes should
        // not reveal which meetings are real.
        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant',
            'passcode' => 'open1234',
        ])->assertNotFound();
    }

    public function test_a_meeting_with_no_passcode_cannot_be_joined_as_a_guest(): void
    {
        // Otherwise the join code alone would be enough for anyone who saw it.
        $this->meeting->update(['passcode' => null]);

        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant',
            'passcode' => '',
        ])->assertStatus(422);
    }

    public function test_the_pass_stops_working_after_thirty_minutes(): void
    {
        [$token] = $this->joinAsGuest();
        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", ['passcode' => 'open1234'])
            ->assertOk();

        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/heartbeat")
            ->assertOk();

        $this->travel(31)->minutes();

        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/heartbeat")
            ->assertStatus(401)
            ->assertJsonPath('code', 'guest_pass_expired');
    }

    public function test_a_pass_for_one_meeting_does_not_work_on_another(): void
    {
        $other = Meeting::create([
            'code' => 'zzz-yyyy-xxx',
            'host_id' => $this->host->id,
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
            'passcode' => 'open1234',
            'guest_access' => true,
        ]);

        [$token] = $this->joinAsGuest();

        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$other->code}/heartbeat")
            ->assertForbidden();
    }

    public function test_a_guest_cannot_reach_host_powers_or_the_rest_of_the_app(): void
    {
        [$token] = $this->joinAsGuest();
        $headers = $this->asGuest($token);

        // Host powers are simply not routed for guests.
        $this->withHeaders($headers)->postJson("/api/v1/guest/meetings/{$this->meeting->code}/end")->assertNotFound();
        $this->withHeaders($headers)->postJson("/api/v1/guest/meetings/{$this->meeting->code}/admit")->assertNotFound();

        // And the pass is not a session: it opens nothing else in the API.
        $this->withHeaders($headers)->getJson('/api/v1/tasks')->assertUnauthorized();
        $this->withHeaders($headers)->getJson('/api/v1/connections')->assertUnauthorized();
        $this->withHeaders($headers)->getJson('/api/v1/files/browse')->assertUnauthorized();
    }

    public function test_a_made_up_pass_is_refused(): void
    {
        $this->withHeaders($this->asGuest(str_repeat('a', 48)))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/heartbeat")
            ->assertUnauthorized();

        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/heartbeat")
            ->assertUnauthorized();
    }

    public function test_guests_never_show_up_as_people_in_the_app(): void
    {
        $this->joinAsGuest('Prashant');

        // The whole reason guests are hidden behind a global scope: they must
        // not be findable as if they were members.
        $suggest = $this->actingAs($this->host)
            ->getJson('/api/v1/connections/suggest?q=Prashant')->assertOk()->json('data');
        $this->assertCount(0, $suggest);

        // Nor counted among real users anywhere.
        $this->assertSame(1 + 0, User::count(), 'only the host is a real user here');
    }

    public function test_a_locked_or_ended_meeting_turns_guests_away(): void
    {
        $this->meeting->update(['is_locked' => true]);
        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant', 'passcode' => 'open1234',
        ])->assertStatus(423);

        $this->meeting->update(['is_locked' => false, 'status' => 'ended']);
        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant', 'passcode' => 'open1234',
        ])->assertStatus(410);
    }
}
