<?php

namespace Tests\Feature;

use App\Mail\BookingUpdate;
use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The host moving a booking, which until now only the guest could do.
 *
 * A host could cancel and nothing else - so "could we make it half an hour
 * later?" meant calling off a client's meeting and asking them to book again.
 *
 * It goes through the same service the guest's own move goes through, so the
 * things worth proving are that the host's authority is checked, that the
 * host cannot move somebody onto a time that is not free, that everything
 * hanging off the booking follows it, and that the client is told.
 */
class HostMovesABookingTest extends TestCase
{
    use RefreshDatabase;

    private User $host;
    private BookingPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

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

    private function at(string $local): string
    {
        return CarbonImmutable::parse($local, 'Asia/Kolkata')->utc()->toIso8601String();
    }

    private function booked(string $local = '2026-09-02 11:00'): Booking
    {
        $this->postJson('/api/v1/book/ayan', [
            'starts_at' => $this->at($local),
            'name' => 'Riya',
            'email' => 'riya@example.com',
            'phone' => '+919876543210',
            'timezone' => 'Asia/Kolkata',
        ])->assertCreated();

        return Booking::latest('id')->firstOrFail();
    }

    public function test_the_host_moves_it_and_everything_hanging_off_it_follows(): void
    {
        $booking = $this->booked();

        $this->actingAs($this->host)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/reschedule", [
                'starts_at' => $this->at('2026-09-02 15:30'),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Moved — Riya has been emailed.');

        $booking->refresh();

        // The booking, the room and the diary entry all sit on the host's
        // day; one of them left behind is a meeting that happens twice.
        $this->assertSame('2026-09-02 15:30', $booking->starts_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-09-02 16:00', $booking->ends_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame(
            '2026-09-02 15:30',
            $booking->meeting->fresh()->scheduled_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'),
        );
        $this->assertSame(
            '2026-09-02 15:30',
            $booking->event->fresh()->starts_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'),
        );
    }

    public function test_whoever_booked_is_told(): void
    {
        $booking = $this->booked();

        $this->actingAs($this->host)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/reschedule", [
                'starts_at' => $this->at('2026-09-02 15:30'),
            ])
            ->assertOk();

        // Their only address, and the only way they would ever know.
        Mail::assertSent(BookingUpdate::class, fn (BookingUpdate $mail) => $mail->hasTo('riya@example.com')
            && $mail->kind === 'rescheduled');
    }

    public function test_it_cannot_be_moved_onto_a_time_that_is_not_free(): void
    {
        $booking = $this->booked('2026-09-02 11:00');
        // Somebody else already has three o'clock.
        $this->booked('2026-09-02 15:00');

        $this->actingAs($this->host)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/reschedule", [
                'starts_at' => $this->at('2026-09-02 15:00'),
            ])
            ->assertStatus(409);

        // And nothing moved.
        $this->assertSame(
            '2026-09-02 11:00',
            $booking->fresh()->starts_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'),
        );
    }

    public function test_it_cannot_be_moved_outside_the_hours_the_host_keeps(): void
    {
        $booking = $this->booked();

        // A Sunday, on a page that works Monday to Friday.
        $this->actingAs($this->host)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/reschedule", [
                'starts_at' => $this->at('2026-09-06 11:00'),
            ])
            ->assertStatus(409);
    }

    public function test_a_cancelled_booking_cannot_be_moved(): void
    {
        $booking = $this->booked();
        $this->actingAs($this->host)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/cancel")
            ->assertOk();

        $this->actingAs($this->host)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/reschedule", [
                'starts_at' => $this->at('2026-09-02 15:30'),
            ])
            ->assertStatus(409);
    }

    public function test_somebody_elses_booking_is_not_theirs_to_move(): void
    {
        $booking = $this->booked();

        $stranger = User::factory()->create(['name' => 'Nobody']);
        $stranger->settings()->create([]);
        $stranger->profile()->create(['timezone' => 'Asia/Kolkata']);
        BookingPage::create([
            'user_id' => $stranger->id, 'slug' => 'nobody', 'title' => 'Chat',
            'duration_minutes' => 30, 'min_notice_minutes' => 0, 'max_days_ahead' => 30, 'is_active' => true,
        ]);

        // Their own page has no such booking on it, so it is simply not found.
        $this->actingAs($stranger)
            ->postJson("/api/v1/booking-page/bookings/{$booking->uuid}/reschedule", [
                'starts_at' => $this->at('2026-09-02 15:30'),
            ])
            ->assertNotFound();
    }
}
