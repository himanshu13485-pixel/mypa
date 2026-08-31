<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\Event;
use App\Models\User;
use App\Services\BookingAvailability;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which moments are actually offerable.
 *
 * The rules are simple one at a time and interact in ways that are not, which
 * is where a booking system earns its keep or embarrasses you: offering a
 * half-hour meeting fifteen minutes before you finish, offering a time you are
 * already busy, or moving somebody's morning by an hour when the clocks change.
 */
class BookingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;

    protected BookingAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->host = User::factory()->create(['name' => 'Ayan']);
        $this->host->settings()->create([]);
        $this->host->profile()->create(['timezone' => 'Asia/Kolkata']);

        $this->availability = app(BookingAvailability::class);
    }

    /** A page open 09:00-17:00 on weekdays, in the host's own timezone. */
    private function page(array $overrides = [], array $hours = []): BookingPage
    {
        $page = BookingPage::create(array_merge([
            'user_id' => $this->host->id,
            'slug' => 'ayan',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'min_notice_minutes' => 0,
            'max_days_ahead' => 30,
            'is_active' => true,
        ], $overrides));

        foreach ($hours ?: [[1, '09:00', '17:00'], [2, '09:00', '17:00'], [3, '09:00', '17:00']] as [$day, $from, $to]) {
            $page->hours()->create(['weekday' => $day, 'start_time' => $from, 'end_time' => $to]);
        }

        return $page->fresh('hours');
    }

    /** Slots on one host-local day, as "H:i" strings in the host's timezone. */
    private function localSlots(BookingPage $page, string $date): array
    {
        $tz = $page->timezone();
        $day = CarbonImmutable::parse($date, $tz);

        return array_map(
            fn ($slot) => $slot->setTimezone($tz)->format('H:i'),
            $this->availability->slots($page, $day->startOfDay(), $day->endOfDay()),
        );
    }

    public function test_a_working_day_becomes_evenly_spaced_slots(): void
    {
        // A Tuesday, well inside the horizon.
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));
        $slots = $this->localSlots($this->page(), '2026-09-02');

        $this->assertSame('09:00', $slots[0]);
        $this->assertSame('09:30', $slots[1]);
        // 16:30 starts the last half hour that ends exactly at 17:00.
        $this->assertSame('16:30', end($slots));
        $this->assertCount(16, $slots);
    }

    public function test_a_meeting_must_finish_inside_the_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));

        // 45-minute slots from 09:00 land at 15:45, then 16:30 — which would
        // finish at 17:15, a quarter of an hour into time the host said they
        // did not have.
        $slots = $this->localSlots($this->page(['duration_minutes' => 45]), '2026-09-02');

        $this->assertSame('15:45', end($slots));
        $this->assertNotContains('16:30', $slots);
    }

    public function test_a_day_with_no_hours_offers_nothing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));

        // Saturday. The page only opens Monday to Wednesday.
        $this->assertSame([], $this->localSlots($this->page(), '2026-09-05'));
    }

    public function test_a_split_day_leaves_the_middle_alone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));

        // Mornings and afternoons, with lunch cut out — the shape a single row
        // per weekday could not express.
        $slots = $this->localSlots($this->page([], [[3, '09:00', '12:00'], [3, '14:00', '17:00']]), '2026-09-02');

        $this->assertContains('11:30', $slots);
        $this->assertNotContains('12:00', $slots);
        $this->assertNotContains('13:30', $slots);
        $this->assertContains('14:00', $slots);
    }

    public function test_notice_hides_what_is_too_soon(): void
    {
        // Mid-morning on the Wednesday, with two hours of notice required.
        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:05', 'Asia/Kolkata'));

        $slots = $this->localSlots($this->page(['min_notice_minutes' => 120]), '2026-09-02');

        $this->assertNotContains('10:00', $slots, 'less than two hours away');
        $this->assertContains('11:30', $slots);
    }

    public function test_the_horizon_closes_the_far_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));
        $page = $this->page(['max_days_ahead' => 3]);

        $this->assertNotSame([], $this->localSlots($page, '2026-09-02'));
        // A Wednesday nine days out, inside the weekly hours but past the
        // horizon — so the rule being tested is the horizon and not the hours.
        $this->assertSame([], $this->localSlots($page, '2026-09-09'));
    }

    public function test_an_inactive_page_offers_nothing_at_all(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));

        $this->assertSame([], $this->localSlots($this->page(['is_active' => false]), '2026-09-02'));
    }

    // ---- Things already in the diary ---------------------------------------

    private function existingBooking(BookingPage $page, string $localStart, int $minutes = 30): Booking
    {
        $start = CarbonImmutable::parse($localStart, $page->timezone());

        return Booking::create([
            'booking_page_id' => $page->id,
            'host_id' => $this->host->id,
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'guest_timezone' => 'UTC',
            'starts_at' => $start->utc(),
            'ends_at' => $start->addMinutes($minutes)->utc(),
            'manage_token' => Booking::newManageToken(),
            'status' => 'confirmed',
        ]);
    }

    public function test_a_taken_slot_is_not_offered_again(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));
        $page = $this->page();
        $this->existingBooking($page, '2026-09-02 11:00');

        $slots = $this->localSlots($page, '2026-09-02');

        $this->assertNotContains('11:00', $slots);
        // Touching end-to-start is not a clash: 11:30 is free the moment the
        // eleven o'clock finishes.
        $this->assertContains('11:30', $slots);
        $this->assertContains('10:30', $slots);
    }

    public function test_a_cancelled_booking_gives_its_slot_back(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));
        $page = $this->page();
        $this->existingBooking($page, '2026-09-02 11:00')->update(['status' => 'cancelled']);

        $this->assertContains('11:00', $this->localSlots($page, '2026-09-02'));
    }

    public function test_the_buffer_keeps_a_gap_either_side(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));
        $page = $this->page(['buffer_minutes' => 15]);
        $this->existingBooking($page, '2026-09-02 11:00');

        $slots = $this->localSlots($page, '2026-09-02');

        // 11:00-11:30 becomes 10:45-11:45 once padded, so neither the slot
        // ending at 11:00 nor the one starting at 11:30 survives.
        $this->assertNotContains('11:30', $slots);
        $this->assertNotContains('10:30', $slots);
    }

    public function test_a_calendar_entry_blocks_the_time_it_covers(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));
        $page = $this->page();

        Event::create([
            'user_id' => $this->host->id,
            'title' => 'Dentist',
            'starts_at' => CarbonImmutable::parse('2026-09-02 14:00', 'Asia/Kolkata')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-09-02 15:00', 'Asia/Kolkata')->utc(),
            'all_day' => false,
        ]);

        $slots = $this->localSlots($page, '2026-09-02');

        $this->assertNotContains('14:00', $slots);
        $this->assertNotContains('14:30', $slots);
        $this->assertContains('15:00', $slots);
    }

    public function test_an_all_day_entry_does_not_empty_the_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));
        $page = $this->page();

        // A birthday means "this is happening today", not "I am unavailable
        // for every minute of it".
        Event::create([
            'user_id' => $this->host->id,
            'title' => 'A birthday',
            'starts_at' => CarbonImmutable::parse('2026-09-02 00:00', 'Asia/Kolkata')->utc(),
            'all_day' => true,
        ]);

        $this->assertNotSame([], $this->localSlots($page, '2026-09-02'));
    }

    public function test_hours_are_wall_clock_in_the_hosts_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-01 00:00', 'Asia/Kolkata'));

        // 09:00 in Kolkata is 03:30 UTC. Computing in UTC and converting at
        // the end is how somebody's morning quietly moves.
        $slot = $this->availability->slots(
            $this->page(),
            CarbonImmutable::parse('2026-09-02 00:00', 'Asia/Kolkata')->startOfDay(),
            CarbonImmutable::parse('2026-09-02 23:59', 'Asia/Kolkata'),
        )[0];

        $this->assertSame('03:30', $slot->utc()->format('H:i'));
    }
}
