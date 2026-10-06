<?php

namespace Tests\Feature;

use App\Models\BookingPage;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A booked meeting belongs to the person whose link it is.
 *
 * The confirmation email carries the room's address and password the moment
 * the booking is made - so whoever booked holds a working door key, days
 * ahead of the time they booked. And because the first person through the
 * door is what marks a meeting started, they could open Monday's two o'clock
 * on Friday and leave the host's own screen saying it had already happened.
 *
 * Two rules, then: the link does not open early, and the room does not begin
 * until its host begins it. Both only apply to meetings a booking made -
 * two colleagues setting something up between themselves have no guest and
 * no host in this sense, and making whoever arrives first wait for a
 * particular person would be a worse answer than the one they have.
 */
class BookedMeetingWaitsForItsHostTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private BookingPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->host = User::factory()->create(['name' => 'Ayan']);
        $this->host->settings()->create([]);
        $this->host->profile()->create(['timezone' => 'Asia/Kolkata']);

        $this->page = BookingPage::create([
            'user_id' => $this->host->id,
            'slug' => 'ayan',
            'title' => 'Intro call',
            'duration_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_days_ahead' => 30,
            'is_active' => true,
        ]);
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $this->page->hours()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00']);
        }

        $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Asia/Kolkata'));
    }

    /** A booking for 11:00 tomorrow, and the room it made. */
    private function booked(): Meeting
    {
        $this->postJson('/api/v1/book/ayan', [
            'starts_at' => CarbonImmutable::parse('2026-09-02 11:00', 'Asia/Kolkata')->utc()->toIso8601String(),
            'name' => 'Riya',
            'email' => 'riya@example.com',
            'phone' => '+919876543210',
            'timezone' => 'Asia/Kolkata',
        ])->assertCreated();

        return Meeting::where('host_id', $this->host->id)->latest('id')->firstOrFail();
    }

    private function knock(Meeting $meeting, string $name = 'Riya')
    {
        return $this->postJson("/api/v1/meetings/{$meeting->code}/guest", [
            'name' => $name,
            'passcode' => $meeting->passcode,
        ]);
    }

    public function test_the_room_belongs_to_whoever_the_link_belongs_to(): void
    {
        $this->assertSame($this->host->id, $this->booked()->host_id);
    }

    public function test_the_link_does_not_open_a_day_early(): void
    {
        $meeting = $this->booked();

        // Still the morning before.
        $this->knock($meeting)
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'This meeting starts at Wed 2 Sep, 11:00am. The link opens 10 minutes before.']);
    }

    public function test_somebody_a_few_minutes_early_is_let_as_far_as_the_door(): void
    {
        $meeting = $this->booked();

        // Turning up at five to is what people do.
        $this->travelTo(CarbonImmutable::parse('2026-09-02 10:55', 'Asia/Kolkata'));

        $this->knock($meeting)->assertSuccessful();
    }

    public function test_the_guest_waits_rather_than_starting_the_meeting(): void
    {
        $meeting = $this->booked();
        $this->travelTo(CarbonImmutable::parse('2026-09-02 11:00', 'Asia/Kolkata'));

        $pass = $this->knock($meeting)->assertSuccessful()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$pass}")
            ->postJson("/api/v1/guest/meetings/{$meeting->code}/join", ['display_name' => 'Riya'])
            ->assertStatus(202)
            ->assertJsonPath('data.waiting', true)
            ->assertJsonPath('data.awaiting_host', true)
            ->assertJsonPath('message', 'Waiting for the host to start the meeting.');

        // And the meeting is untouched: not started, not active.
        $meeting->refresh();
        $this->assertNull($meeting->started_at);
        $this->assertNotSame('active', $meeting->status);
    }

    public function test_the_host_starts_it_and_then_the_guest_is_let_in(): void
    {
        $meeting = $this->booked();
        $this->travelTo(CarbonImmutable::parse('2026-09-02 11:00', 'Asia/Kolkata'));
        $pass = $this->knock($meeting)->assertSuccessful()->json('data.token');

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$meeting->code}/join", [])
            ->assertOk();

        $meeting->refresh();
        $this->assertNotNull($meeting->started_at, 'the host arriving is what starts it');

        // The guest's next attempt - the waiting screen retries on its own -
        // now goes through.
        $this->withHeader('Authorization', "Bearer {$pass}")
            ->postJson("/api/v1/guest/meetings/{$meeting->code}/join", ['display_name' => 'Riya'])
            ->assertOk();
    }

    public function test_somebody_arriving_after_it_started_walks_straight_in(): void
    {
        /*
         * Once started the question is settled for good. A latecomer - or
         * the same person after a dropped connection - must not be put back
         * outside a door that is already open.
         */
        $meeting = $this->booked();
        $this->travelTo(CarbonImmutable::parse('2026-09-02 11:00', 'Asia/Kolkata'));

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join", [])->assertOk();

        $pass = $this->knock($meeting, 'Riya on her phone')->assertSuccessful()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$pass}")
            ->postJson("/api/v1/guest/meetings/{$meeting->code}/join", ['display_name' => 'Riya'])
            ->assertOk();
    }

    public function test_a_meeting_nobody_booked_is_left_alone(): void
    {
        /*
         * The rule is about booking links, and only about them. Two people
         * setting up a room between themselves have always had "whoever
         * arrives first starts it", and should keep it.
         */
        $other = User::factory()->create(['name' => 'Priya']);
        $other->settings()->create([]);
        $other->profile()->create(['timezone' => 'Asia/Kolkata']);

        $meeting = Meeting::create([
            'host_id' => $this->host->id,
            'code' => Meeting::generateCode(),
            'title' => 'Standup',
            'type' => 'video',
            'requires_approval' => false,
            'scheduled_at' => CarbonImmutable::parse('2026-09-02 11:00', 'Asia/Kolkata'),
            'status' => 'scheduled',
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-09-02 11:00', 'Asia/Kolkata'));

        $this->actingAs($other)
            ->postJson("/api/v1/meetings/{$meeting->code}/join", [])
            ->assertOk();

        $this->assertNotNull($meeting->fresh()->started_at);
    }
}
