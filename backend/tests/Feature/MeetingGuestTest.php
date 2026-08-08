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
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join")
            ->assertOk();

        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/heartbeat")
            ->assertOk();

        // And the room can see them.
        $names = $this->meeting->participants()->pluck('name');
        $this->assertTrue($names->contains('Prashant'), 'the guest should be in the room');
    }

    public function test_a_guest_is_not_asked_for_the_passcode_a_second_time(): void
    {
        // They typed it to be given a pass, and the pass is only good for this
        // meeting. Asking again on the way into the room made one join take
        // two entries of the same code.
        [$token] = $this->joinAsGuest();

        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join")
            ->assertOk();
    }

    public function test_a_signed_in_member_never_needs_the_password(): void
    {
        // The password is what stands in for an account. Someone who has one
        // has already proved who they are, so the link is enough.
        $member = User::factory()->create();

        $this->actingAs($member)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/join")
            ->assertOk();
    }

    public function test_a_wrong_passcode_is_refused(): void
    {
        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant',
            'passcode' => 'nope0000',
        ])->assertForbidden();
    }

    public function test_no_password_means_no_guests(): void
    {
        // The password is the whole switch: without one there is nothing to
        // check a stranger against, and the code alone would let in anyone who
        // saw the link.
        $this->meeting->update(['passcode' => null]);

        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant',
            'passcode' => 'open1234',
        ])->assertForbidden();
    }

    public function test_the_join_page_can_ask_whether_a_password_would_help(): void
    {
        $this->getJson("/api/v1/meetings/{$this->meeting->code}/guest")
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.allows_guests', true);

        $this->meeting->update(['passcode' => null]);
        $this->getJson("/api/v1/meetings/{$this->meeting->code}/guest")
            ->assertOk()
            ->assertJsonPath('data.allows_guests', false);

        $this->getJson('/api/v1/meetings/nope-nope-nop/guest')
            ->assertOk()
            ->assertJsonPath('data.exists', false);
    }

    public function test_the_host_can_set_and_clear_the_password_mid_meeting(): void
    {
        // The instant "New meeting" button makes no password, so the only place
        // this can be reached is from inside the room.
        $this->actingAs($this->host)
            ->putJson("/api/v1/meetings/{$this->meeting->code}/passcode", ['passcode' => null])
            ->assertOk()
            ->assertJsonPath('data.allows_guests', false);

        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant', 'passcode' => 'open1234',
        ])->assertForbidden();

        $this->actingAs($this->host)
            ->putJson("/api/v1/meetings/{$this->meeting->code}/passcode", ['passcode' => 'later999'])
            ->assertOk()
            ->assertJsonPath('data.allows_guests', true);

        $this->postJson("/api/v1/meetings/{$this->meeting->code}/guest", [
            'name' => 'Prashant', 'passcode' => 'later999',
        ])->assertCreated();
    }

    public function test_only_a_moderator_can_change_the_password(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->putJson("/api/v1/meetings/{$this->meeting->code}/passcode", ['passcode' => 'mine1234'])
            ->assertForbidden();

        $this->assertSame('open1234', $this->meeting->fresh()->passcode);
    }

    public function test_the_pass_stops_working_after_thirty_minutes(): void
    {
        [$token] = $this->joinAsGuest();
        $this->withHeaders($this->asGuest($token))
            ->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join")
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

    /**
     * Signalling is the whole meeting: without a channel a guest sits in a room
     * where no offer or answer ever arrives.
     *
     * Worth a test of its own because the failure was invisible. The resolver
     * is listed before auth:sanctum in withBroadcasting(), and route:list
     * agreed — but Laravel sorts by $middlewarePriority before running, and
     * Authenticate outranks a middleware with no priority of its own, so it
     * ran first and answered "Unauthenticated." to every guest.
     */
    public function test_a_guest_can_authorise_their_own_realtime_channel(): void
    {
        [$token, $uuid] = $this->joinAsGuest();

        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-user.{$uuid}",
        ], $this->asGuest($token))->assertOk();
    }

    public function test_a_guest_pass_opens_nobody_elses_channel(): void
    {
        [$token] = $this->joinAsGuest();

        // The suite broadcasts to null, and that driver authorises everything
        // without ever consulting the channel rule — so the rule has to be put
        // in front of a real one to be tested at all. Reverb only needs its
        // credentials to sign a success; a refusal is thrown before that.
        config(['broadcasting.default' => 'reverb']);

        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-user.{$this->host->uuid}",
        ], $this->asGuest($token))->assertForbidden();
    }

    /**
     * Its own test on purpose: a guest resolved by one request stays on the
     * guard for the rest of the test, so asking this straight after a guest
     * request would be answered by that guest rather than by nobody.
     */
    public function test_no_pass_authorises_no_channel_at_all(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-user.{$this->host->uuid}",
        ])->assertUnauthorized();
    }

    /**
     * The waiting room has to work for the people it was built for.
     *
     * Guests are user rows behind a global scope, and admit() looked them up
     * through User at large — so it could never find one. The host clicked
     * Admit, got a 404 the client threw away, and the same knock came back on
     * the next heartbeat, for ever.
     */
    public function test_the_host_can_admit_a_guest_from_the_waiting_room(): void
    {
        $this->meeting->update(['requires_approval' => true]);
        // The host is in the room — that is where knocks are seen and answered.
        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/join")
            ->assertOk();

        [$token, $uuid] = $this->joinAsGuest();

        // Knocking puts them in the waiting room, not the meeting.
        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertStatus(202)
            ->assertJsonPath('data.waiting', true);

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.waiting.0.uuid', $uuid);

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/admit", [
                'user_uuid' => $uuid,
                'allow' => true,
            ])->assertOk();

        // And now the same guest walks in rather than knocking again.
        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertOk()
            ->assertJsonMissingPath('data.waiting');

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.waiting', []);
    }

    public function test_the_host_can_turn_a_guest_away(): void
    {
        $this->meeting->update(['requires_approval' => true]);
        [$token, $uuid] = $this->joinAsGuest();

        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertStatus(202);

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/admit", [
                'user_uuid' => $uuid,
                'allow' => false,
            ])->assertOk();

        // Turned away is not the same as forgotten: they do not get back in by
        // asking again.
        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertStatus(202);
    }

    /**
     * A guest needs the ICE servers as much as anybody: they are what a peer
     * connection is built from.
     *
     * This one hid behind the waiting room. The config is only fetched when
     * there is somebody to connect to, so a guest joining an empty room was
     * fine and a guest let in by the host — the moment a peer exists — got
     * "Unauthenticated." from a members-only route and dropped out of a
     * meeting they had already been admitted to.
     */
    public function test_a_guest_can_read_the_ice_configuration(): void
    {
        [$token] = $this->joinAsGuest();

        $this->getJson('/api/v1/calls/config', $this->asGuest($token))
            ->assertOk()
            ->assertJsonStructure(['data' => ['iceServers']]);
    }

    public function test_the_ice_configuration_still_needs_somebody(): void
    {
        $this->getJson('/api/v1/calls/config')->assertUnauthorized();
    }

    /**
     * A guest can say something. The panel was always there for them; the
     * route behind it was not, so every message failed on a 404.
     */
    public function test_a_guest_can_send_a_chat_message(): void
    {
        [$token] = $this->joinAsGuest();
        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertOk();

        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/chat", [
            'message' => 'Hello from someone with no account.',
        ], $this->asGuest($token))->assertOk();
    }

    /** Chatting is for people in the room, not for anyone holding a pass. */
    public function test_a_guest_who_has_not_joined_cannot_chat(): void
    {
        [$token] = $this->joinAsGuest();

        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/chat", [
            'message' => 'Anybody there?',
        ], $this->asGuest($token))->assertForbidden();
    }

    /**
     * The rest of the panel stays shut. Sharing a file, and everything a host
     * does, has no guest route at all — not a 403 to argue with, nothing.
     */
    public function test_the_endpoints_a_guest_never_gets_do_not_exist(): void
    {
        [$token] = $this->joinAsGuest();
        $code = $this->meeting->code;

        $this->postJson("/api/v1/guest/meetings/{$code}/chat-file", [], $this->asGuest($token))->assertNotFound();
        $this->postJson("/api/v1/guest/meetings/{$code}/end", [], $this->asGuest($token))->assertNotFound();
        $this->postJson("/api/v1/guest/meetings/{$code}/admit", [], $this->asGuest($token))->assertNotFound();
        $this->postJson("/api/v1/guest/meetings/{$code}/host-action", [], $this->asGuest($token))->assertNotFound();
        $this->putJson("/api/v1/guest/meetings/{$code}/passcode", [], $this->asGuest($token))->assertNotFound();
    }

    /**
     * The host leaving must not hand the meeting to a guest.
     *
     * It did, and the damage went far past that room: a guest is hidden from
     * ordinary user queries, so the meeting was owned by somebody the host
     * relation could not resolve, and every later read died on it — the whole
     * meetings list, for anybody who could see that meeting. The host could
     * not delete it either, because deleting asks whether you own it.
     */
    public function test_the_host_leaving_does_not_hand_the_meeting_to_a_guest(): void
    {
        [$token] = $this->joinAsGuest();
        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertOk();

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/join")->assertOk();
        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/leave")->assertOk();

        // Ownership stays with the host: there is no member left to take it.
        $this->assertSame($this->host->id, $this->meeting->fresh()->host_id);

        // And the list still loads, which is what actually broke.
        $this->actingAs($this->host)->getJson('/api/v1/meetings')->assertOk();
    }

    /** A member in the room does inherit it — guests are skipped, not everyone. */
    public function test_the_meeting_passes_to_a_member_over_a_guest(): void
    {
        $member = User::factory()->create();
        $member->profile()->create(['timezone' => 'UTC']);

        [$token] = $this->joinAsGuest();
        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertOk();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$this->meeting->code}/join")->assertOk();
        $this->actingAs($member)->postJson("/api/v1/meetings/{$this->meeting->code}/join")->assertOk();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$this->meeting->code}/leave")->assertOk();

        $this->assertSame($member->id, $this->meeting->fresh()->host_id);
    }

    /** Nor by hand: the host cannot pass the meeting to a guest either. */
    public function test_the_host_cannot_transfer_the_meeting_to_a_guest(): void
    {
        [$token, $uuid] = $this->joinAsGuest();
        $this->postJson("/api/v1/guest/meetings/{$this->meeting->code}/join", [], $this->asGuest($token))
            ->assertOk();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$this->meeting->code}/join")->assertOk();

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/host-action", [
                'action' => 'transfer_host',
                'user_uuid' => $uuid,
            ])->assertStatus(422);

        $this->assertSame($this->host->id, $this->meeting->fresh()->host_id);
    }

    /** A uuid that is not waiting here is not admitted by pointing at it. */
    public function test_admitting_somebody_who_is_not_in_this_meeting_fails(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$this->meeting->code}/admit", [
                'user_uuid' => $stranger->uuid,
                'allow' => true,
            ])->assertNotFound();
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
