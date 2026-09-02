<?php

namespace Tests\Feature;

use App\Events\PresenceChanged;
use App\Models\Connection;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Here, stepped away, or gone.
 *
 * The old answer was a boolean read off "did a request arrive in the last two
 * minutes", which cannot be right in either direction: a chat screen polls
 * every twenty seconds all night, so nobody was ever away, and somebody who
 * had just signed in stayed grey until the watcher's page next refreshed.
 *
 * So the browser says which of the three it is, the server believes that only
 * while it is fresh, and a change goes out over the socket rather than waiting
 * to be asked for.
 */
class PresenceTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $friend;

    private function person(string $username): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username . '@netvork.test',
            'email_verified_at' => now(),
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->me = $this->person('watcher');
        $this->friend = $this->person('subject');

        Connection::create([
            'requester_id' => $this->me->id,
            'addressee_id' => $this->friend->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /** What the connections list says about the other person right now. */
    private function seenState(): ?string
    {
        return $this->actingAs($this->me)
            ->getJson('/api/v1/connections?status=accepted')
            ->assertOk()
            ->json('data.0.user.presence');
    }

    private function beat(User $who, string $state): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($who)->postJson('/api/v1/presence', ['state' => $state]);
    }

    public function test_the_three_states_reach_the_person_watching(): void
    {
        $this->beat($this->friend, 'online')->assertOk();
        $this->assertSame('online', $this->seenState());

        $this->beat($this->friend, 'away')->assertOk();
        $this->assertSame('away', $this->seenState());

        $this->beat($this->friend, 'offline')->assertOk();
        $this->assertSame('offline', $this->seenState());
    }

    public function test_a_tab_left_open_all_night_is_not_treated_as_somebody_being_there(): void
    {
        /*
         * The bug this exists for. An open app keeps talking to the server on
         * its own — polls, badges, heartbeats — so request traffic alone can
         * only ever say "online", and did.
         *
         * An away heartbeat must therefore not stamp last_active_at, or an
         * idle tab keeps voting itself present for ever.
         */
        $this->beat($this->friend, 'away')->assertOk();

        $this->friend->refresh();
        $this->assertNull($this->friend->last_active_at);
        $this->assertSame('away', $this->friend->presenceState());
    }

    public function test_a_browser_that_stops_talking_decays_instead_of_staying_green(): void
    {
        $this->beat($this->friend, 'online')->assertOk();

        // The heartbeat stops — asleep, crashed, killed — and nothing else
        // arrives either. Past the trust window it is the timestamps' turn.
        $this->travel(4)->minutes();
        $this->assertSame('away', $this->friend->fresh()->presenceState());

        $this->travel(20)->minutes();
        $this->assertSame('offline', $this->friend->fresh()->presenceState());
    }

    public function test_a_stale_report_is_never_talked_over_by_request_traffic(): void
    {
        /*
         * Somebody goes idle — reported away — and then shuts the lid. The
         * polling their open tab was doing right up to that second looks
         * exactly like a person, so the fallback would have turned them green
         * again the moment their heartbeat expired. It may move them further
         * away, never nearer.
         */
        $this->beat($this->friend, 'away')->assertOk();
        $this->friend->forceFill(['last_active_at' => now()])->save();

        $this->travel(3)->minutes();

        $this->assertSame('away', $this->friend->fresh()->presenceState());
    }

    public function test_closing_the_tab_says_so_at_once(): void
    {
        $this->beat($this->friend, 'online')->assertOk();
        $this->assertSame('online', $this->seenState());

        $this->actingAs($this->friend)->postJson('/api/v1/presence/leaving')->assertOk();

        $this->assertSame('offline', $this->seenState());
    }

    public function test_hiding_your_status_shows_no_dot_rather_than_a_red_one(): void
    {
        $this->beat($this->friend, 'online')->assertOk();

        $this->friend->settings->update(['privacy' => ['online_status_visibility' => 'nobody']]);

        // Null, not 'offline'. Reporting them as gone would be answering the
        // question they declined to answer.
        $this->assertNull($this->seenState());
    }

    public function test_a_change_is_broadcast_to_the_people_who_can_see_it(): void
    {
        Event::fake([PresenceChanged::class]);

        $this->beat($this->friend, 'online')->assertOk();

        Event::assertDispatched(PresenceChanged::class, function (PresenceChanged $e) {
            return $e->userUuid === $this->friend->uuid
                && $e->state === 'online'
                && in_array($this->me->uuid, $e->audience, true);
        });

        // Beating again says nothing new, so nothing is sent. Presence
        // changing is rare; presence being reported is constant.
        Event::assertDispatchedTimes(PresenceChanged::class, 1);
        $this->beat($this->friend, 'online')->assertOk();
        Event::assertDispatchedTimes(PresenceChanged::class, 1);
    }

    public function test_a_stranger_hears_nothing_about_anybody(): void
    {
        $stranger = $this->person('stranger');

        Event::fake([PresenceChanged::class]);
        $this->beat($this->friend, 'online')->assertOk();

        Event::assertDispatched(PresenceChanged::class, function (PresenceChanged $e) use ($stranger) {
            return ! in_array($stranger->uuid, $e->audience, true);
        });

        $this->assertNull(
            $this->actingAs($stranger)
                ->getJson('/api/v1/connections?status=accepted')
                ->json('data.0'),
        );
    }

    public function test_somebody_with_nobody_watching_them_beats_without_broadcasting(): void
    {
        $alone = $this->person('nobodyknowsme');

        Event::fake([PresenceChanged::class]);

        // No connections and no conversations: there is nobody to tell, and
        // an event with no channels is a request to the broadcaster with an
        // empty channel list rather than a quiet no-op.
        $this->beat($alone, 'online')->assertOk();
        Event::assertNotDispatched(PresenceChanged::class);

        // Hiding your status empties the audience the same way.
        $this->friend->settings->update(['privacy' => ['online_status_visibility' => 'nobody']]);
        $this->beat($this->friend, 'online')->assertOk();
        Event::assertNotDispatched(PresenceChanged::class);
    }

    /** What the chat header is told about the other person. */
    private function chatHeader(): array
    {
        \App\Models\Conversation::directBetween($this->me, $this->friend);

        return $this->actingAs($this->me)
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->json('data.0.other_user');
    }

    public function test_the_chat_header_is_told_when_they_were_last_here(): void
    {
        $this->beat($this->friend, 'online')->assertOk();
        $this->travel(20)->minutes();

        $header = $this->chatHeader();

        // The setting has been consulted on this line since it existed; there
        // was simply never a value beside it for the switch to govern.
        $this->assertTrue($header['last_seen_visible']);
        $this->assertNotNull($header['last_seen_at']);
        $this->assertSame(
            20,
            (int) round(now()->diffInMinutes(\Illuminate\Support\Carbon::parse($header['last_seen_at']), true)),
        );
    }

    public function test_hiding_last_seen_withholds_the_time_and_not_merely_the_flag(): void
    {
        $this->beat($this->friend, 'online')->assertOk();
        $this->friend->settings->update(['privacy' => ['last_seen_visibility' => 'nobody']]);

        $header = $this->chatHeader();

        $this->assertFalse($header['last_seen_visible']);
        $this->assertNull($header['last_seen_at']);
    }

    public function test_a_tab_polling_in_the_background_does_not_keep_saying_just_now(): void
    {
        /*
         * The whole reason last seen could not be shown before.
         *
         * An open app asks this server for things every twenty seconds by
         * itself, and every one of those requests used to count as the person
         * being here — so "last seen" would have read "just now" all night,
         * for hours after they went home.
         *
         * While a heartbeat is arriving, only the heartbeat moves it, and
         * only when it says online.
         */
        $this->beat($this->friend, 'online')->assertOk();
        $this->travel(2)->minutes();
        $this->beat($this->friend, 'away')->assertOk();

        // Their app carries on polling while they are away from the keyboard.
        $this->actingAs($this->friend)->getJson('/api/v1/conversations')->assertOk();
        $this->actingAs($this->friend)->getJson('/api/v1/notifications/count');

        $this->assertSame(
            2,
            (int) round(now()->diffInMinutes($this->friend->fresh()->last_active_at, true)),
        );
    }

    public function test_a_client_that_never_reports_is_still_measured_by_its_requests(): void
    {
        // Nothing has a heartbeat by default — a native app, a script, the
        // app before this existed — and for those the requests are the only
        // evidence there is, which is what they always were.
        $this->actingAs($this->friend)->getJson('/api/v1/conversations')->assertOk();

        $this->assertNotNull($this->friend->fresh()->last_active_at);
        $this->assertSame('online', $this->friend->fresh()->presenceState());
    }

    public function test_only_the_three_words_are_accepted(): void
    {
        $this->beat($this->friend, 'busy')->assertStatus(422);
    }
}
