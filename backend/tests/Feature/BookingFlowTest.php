<?php

namespace Tests\Feature;

use App\Mail\BookingUpdate;
use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\Event;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\SocialNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Booking somebody without having an account.
 *
 * The whole feature turns on that: the person clicking the link is a stranger,
 * so every route they touch is unauthenticated, and the only credential they
 * ever hold is the token in their confirmation email. Which makes two things
 * worth proving over and over — that a stranger can complete the journey, and
 * that they cannot see or reach anything beyond their own booking.
 */
class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;

    protected BookingPage $page;

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

        // A Tuesday morning, so "tomorrow" is always a working day.
        $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Asia/Kolkata'));
    }

    /** 11:00 the next morning, host time, as the API expects it. */
    private function slot(string $local = '2026-09-02 11:00'): string
    {
        return CarbonImmutable::parse($local, 'Asia/Kolkata')->utc()->toIso8601String();
    }

    private function book(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/book/ayan', array_merge([
            'starts_at' => $this->slot(),
            'name' => 'Riya',
            'email' => 'riya@example.com',
            'note' => 'Wanted to talk about the pilot.',
            'timezone' => 'Asia/Kolkata',
        ], $overrides));
    }

    // ---- What a stranger can see -------------------------------------------

    public function test_the_page_shows_what_a_stranger_needs_and_no_more(): void
    {
        $data = $this->getJson('/api/v1/book/ayan')->assertOk()->json('data');

        $this->assertSame('Ayan', $data['host_name']);
        $this->assertSame('Intro call', $data['title']);
        $this->assertSame(30, $data['duration_minutes']);

        // Nothing about the host beyond their name. A booking link is handed
        // to people who are not trusted with an account.
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('bookings', $data);
    }

    public function test_an_inactive_link_is_simply_not_there(): void
    {
        $this->page->update(['is_active' => false]);

        $this->getJson('/api/v1/book/ayan')->assertNotFound();
        $this->getJson('/api/v1/book/ayan/slots?from=2026-09-02&to=2026-09-03')->assertNotFound();
        $this->book()->assertNotFound();
    }

    public function test_slots_come_back_for_a_range(): void
    {
        $slots = $this->getJson('/api/v1/book/ayan/slots?from=2026-09-02&to=2026-09-03')
            ->assertOk()->json('data.slots');

        $this->assertNotEmpty($slots);
        $this->assertContains($this->slot(), $slots);
    }

    public function test_a_silly_range_is_refused_rather_than_walked(): void
    {
        $this->getJson('/api/v1/book/ayan/slots?from=2026-09-02&to=2030-09-03')->assertStatus(422);
    }

    // ---- Booking -----------------------------------------------------------

    public function test_booking_creates_the_room_the_diary_entry_and_tells_both_sides(): void
    {
        Notification::fake();
        Mail::fake();

        $data = $this->book()->assertCreated()->json('data');

        $booking = Booking::firstOrFail();
        $this->assertSame('Riya', $booking->name);
        $this->assertSame('confirmed', $booking->status);

        // The room. It must carry a password: a meeting without one refuses
        // guests outright, which would lock out the very person who booked it.
        $meeting = Meeting::firstOrFail();
        $this->assertNotNull($meeting->passcode);
        $this->assertSame($meeting->id, $booking->meeting_id);
        $this->assertSame($meeting->code, $data['meeting']['code']);

        // The diary entry, because the calendar page reads events and knows
        // nothing about meetings.
        $event = Event::firstOrFail();
        $this->assertSame($event->id, $booking->event_id);
        $this->assertSame($this->host->id, $event->user_id);
        $this->assertStringContainsString('riya@example.com', (string) $event->description);

        Notification::assertSentTo($this->host, SocialNotification::class,
            function (SocialNotification $note) {
                $this->assertSame('booking_confirmed', $note->kind);
                $this->assertStringContainsString('Riya booked you', $note->message);

                return true;
            });

        Mail::assertSent(BookingUpdate::class, fn ($mail) => $mail->hasTo('riya@example.com'));
    }

    public function test_the_same_slot_cannot_be_taken_twice(): void
    {
        Notification::fake();
        Mail::fake();

        $this->book()->assertCreated();

        // The second person was looking at a list drawn before the first
        // booked. They get told plainly rather than double-booking the host.
        $this->book(['name' => 'Someone else', 'email' => 'else@example.com'])->assertStatus(409);

        $this->assertSame(1, Booking::count());
    }

    public function test_a_time_that_was_never_offered_is_refused(): void
    {
        Notification::fake();
        Mail::fake();

        // 03:00, hand-crafted rather than picked — the host is open 09:00-17:00.
        $this->book(['starts_at' => $this->slot('2026-09-02 03:00')])->assertStatus(409);

        $this->assertSame(0, Booking::count());
    }

    public function test_booking_needs_a_name_and_a_real_email(): void
    {
        $this->book(['email' => 'not-an-email'])->assertStatus(422);
        $this->book(['name' => ''])->assertStatus(422);
    }

    // ---- Managing it afterwards, with no account ---------------------------

    private function bookThen(): Booking
    {
        Notification::fake();
        Mail::fake();
        $this->book()->assertCreated();

        return Booking::firstOrFail();
    }

    public function test_the_token_is_the_whole_credential(): void
    {
        $booking = $this->bookThen();

        $this->getJson("/api/v1/bookings/{$booking->manage_token}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Riya');

        // Anything else is a flat 404 — the same answer a real token for a
        // deleted booking gives, so nothing is learned by trying.
        $this->getJson('/api/v1/bookings/' . str_repeat('z', 64))->assertNotFound();
    }

    public function test_a_guest_can_cancel_and_the_slot_comes_back(): void
    {
        $booking = $this->bookThen();

        $this->postJson("/api/v1/bookings/{$booking->manage_token}/cancel")->assertOk();

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('guest', $booking->cancelled_by);

        // The room is closed and the diary entry gone, but the booking itself
        // is kept: "this was booked and then called off" is worth seeing.
        $this->assertSame('ended', $booking->meeting->status);
        $this->assertSame(0, Event::count());

        Notification::assertSentTo($this->host, SocialNotification::class,
            fn (SocialNotification $n) => $n->kind === 'booking_cancelled');

        // And the time is offerable again.
        $slots = $this->getJson('/api/v1/book/ayan/slots?from=2026-09-02&to=2026-09-03')->json('data.slots');
        $this->assertContains($this->slot(), $slots);
    }

    public function test_cancelling_twice_says_so_rather_than_pretending(): void
    {
        $booking = $this->bookThen();

        $this->postJson("/api/v1/bookings/{$booking->manage_token}/cancel")->assertOk();
        $this->postJson("/api/v1/bookings/{$booking->manage_token}/cancel")->assertStatus(409);
    }

    public function test_a_guest_can_move_a_booking_and_keep_the_same_link(): void
    {
        $booking = $this->bookThen();
        $token = $booking->manage_token;
        $code = $booking->meeting->code;

        $this->postJson("/api/v1/bookings/{$token}/reschedule", [
            'starts_at' => $this->slot('2026-09-02 14:00'),
        ])->assertOk();

        $booking->refresh();
        $this->assertSame('14:00', $booking->starts_at->setTimezone('Asia/Kolkata')->format('H:i'));
        $this->assertSame('confirmed', $booking->status);

        /*
         * The same row, room and token carried across. Cancelling and
         * recreating would invalidate the link already sitting in the guest's
         * inbox and send them a second email contradicting the first.
         */
        $this->assertSame($token, $booking->manage_token);
        $this->assertSame($code, $booking->meeting->code);
        $this->assertSame('14:00', $booking->meeting->scheduled_at->setTimezone('Asia/Kolkata')->format('H:i'));
        $this->assertSame('14:00', $booking->event->starts_at->setTimezone('Asia/Kolkata')->format('H:i'));
    }

    public function test_moving_onto_a_taken_time_is_refused_and_changes_nothing(): void
    {
        $booking = $this->bookThen();

        // Somebody else takes 14:00 first.
        $this->book(['starts_at' => $this->slot('2026-09-02 14:00'), 'name' => 'Other', 'email' => 'o@example.com'])
            ->assertCreated();

        $this->postJson("/api/v1/bookings/{$booking->manage_token}/reschedule", [
            'starts_at' => $this->slot('2026-09-02 14:00'),
        ])->assertStatus(409);

        // The original survives untouched — a failed move must not lose the
        // booking it was moving.
        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('11:00', $booking->starts_at->setTimezone('Asia/Kolkata')->format('H:i'));
    }

    public function test_a_booking_can_be_moved_onto_the_time_it_already_overlaps(): void
    {
        $booking = $this->bookThen();

        // Half an hour later overlaps where it currently sits, so a check that
        // did not stand the booking down would refuse the commonest small move.
        $this->postJson("/api/v1/bookings/{$booking->manage_token}/reschedule", [
            'starts_at' => $this->slot('2026-09-02 11:30'),
        ])->assertOk();

        $this->assertSame('11:30', $booking->fresh()->starts_at->setTimezone('Asia/Kolkata')->format('H:i'));
    }

    // ---- The host's side ---------------------------------------------------

    public function test_a_page_appears_the_first_time_you_look_at_it(): void
    {
        $newcomer = User::factory()->create(['name' => 'Himanshu', 'username' => 'himanshu']);
        $newcomer->settings()->create([]);

        $data = $this->actingAs($newcomer)->getJson('/api/v1/booking-page')->assertOk()->json('data');

        $this->assertSame('himanshu', $data['slug']);
        // Off until somebody has set their hours: an active link with no
        // availability reads as broken rather than as unfinished.
        $this->assertFalse($data['is_active']);
        $this->assertStringEndsWith('/book/himanshu', $data['url']);
    }

    public function test_two_people_with_the_same_name_get_readable_links(): void
    {
        foreach (['ayan', 'ayan'] as $name) {
            $user = User::factory()->create(['name' => 'Ayan', 'username' => $name . '-' . uniqid()]);
            $user->settings()->create([]);
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', BookingPage::slugFor($user));
        }

        // The taken one gets a number, not a random string: a booking link is
        // read aloud and typed.
        $clash = User::factory()->create(['name' => 'Ayan', 'username' => 'ayan']);
        $this->assertSame('ayan-2', BookingPage::slugFor($clash));
    }

    public function test_the_host_sets_hours_and_the_week_is_replaced_wholesale(): void
    {
        $this->actingAs($this->host)->putJson('/api/v1/booking-page', [
            'title' => 'Coffee',
            'duration_minutes' => 15,
            'is_active' => true,
            'hours' => [
                ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '12:00'],
                ['weekday' => 1, 'start_time' => '15:00', 'end_time' => '16:00'],
            ],
        ])->assertOk();

        $page = $this->page->fresh('hours');
        $this->assertSame('Coffee', $page->title);
        $this->assertSame(15, $page->duration_minutes);
        // The five weekdays set up in setUp are gone, not merged with.
        $this->assertCount(2, $page->hours);
    }

    public function test_a_backwards_window_is_refused(): void
    {
        $this->actingAs($this->host)->putJson('/api/v1/booking-page', [
            'hours' => [['weekday' => 1, 'start_time' => '17:00', 'end_time' => '09:00']],
        ])->assertStatus(422);
    }

    public function test_a_slug_somebody_else_has_is_refused(): void
    {
        $other = User::factory()->create();
        $other->settings()->create([]);
        BookingPage::create(['user_id' => $other->id, 'slug' => 'taken']);

        $this->actingAs($this->host)->putJson('/api/v1/booking-page', ['slug' => 'taken'])->assertStatus(422);
        $this->actingAs($this->host)->putJson('/api/v1/booking-page', ['slug' => 'Not A Slug'])->assertStatus(422);
    }

    public function test_the_host_sees_and_can_call_off_a_booking(): void
    {
        $booking = $this->bookThen();

        $rows = $this->actingAs($this->host)->getJson('/api/v1/booking-page/bookings')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('Riya', $rows[0]['name']);

        $this->actingAs($this->host)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/cancel")
            ->assertOk();

        $this->assertSame('host', $booking->fresh()->cancelled_by);
        Mail::assertSent(BookingUpdate::class, fn ($m) => $m->kind === 'cancelled');
    }

    public function test_one_host_cannot_touch_anothers_booking(): void
    {
        $booking = $this->bookThen();

        $stranger = User::factory()->create();
        $stranger->settings()->create([]);

        // Scoped through their own page, so somebody else's booking is a 404
        // rather than a 403 — there is no reason to confirm it exists.
        $this->actingAs($stranger)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/cancel")
            ->assertNotFound();

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_the_booking_endpoints_need_no_session(): void
    {
        // The premise of the whole feature: a stranger completes this journey.
        $this->getJson('/api/v1/book/ayan')->assertOk();
        $this->getJson('/api/v1/book/ayan/slots?from=2026-09-02&to=2026-09-03')->assertOk();

        // While the host's own side is guarded.
        $this->getJson('/api/v1/booking-page')->assertUnauthorized();
    }
    // ---- Bugs found by using it --------------------------------------------

    public function test_a_page_reports_its_defaults_the_very_first_time_it_is_opened(): void
    {
        $newcomer = User::factory()->create(['username' => 'firsttimer']);
        $newcomer->settings()->create([]);

        /*
         * This is the request that CREATES the row, and it was the one that
         * returned nulls for every number — because a column default is
         * applied by the database on insert and never read back, so the model
         * firstOrCreate() hands over has none of them.
         *
         * The one load where these values matter is the first, since that is
         * when somebody sets the page up: the settings screen showed the first
         * option of each dropdown instead of the real default.
         */
        $data = $this->actingAs($newcomer)->getJson('/api/v1/booking-page')->assertOk()->json('data');

        $this->assertSame(30, $data['duration_minutes']);
        $this->assertSame(0, $data['buffer_minutes']);
        $this->assertSame(120, $data['min_notice_minutes']);
        $this->assertSame(30, $data['max_days_ahead']);
    }

    public function test_a_browser_reporting_a_legacy_timezone_name_can_still_book(): void
    {
        Notification::fake();
        Mail::fake();

        /*
         * Browsers report whatever their ICU build calls the zone, and plenty
         * still say Asia/Calcutta rather than Asia/Kolkata. Laravel's plain
         * `timezone` rule accepts only canonical names, so this was a flat 422
         * — a booking page that nobody in India could get past. Registration
         * and the profile had always used all_with_bc; this had not.
         */
        $this->book(['timezone' => 'Asia/Calcutta'])->assertCreated();

        $this->assertSame('Asia/Calcutta', Booking::firstOrFail()->guest_timezone);
    }

    public function test_nonsense_in_the_timezone_field_is_still_refused(): void
    {
        // Loosening the rule must not mean accepting anything at all.
        $this->book(['timezone' => 'Middle/Earth'])->assertStatus(422);
    }

}
