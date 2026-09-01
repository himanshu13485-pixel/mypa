<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingHour;
use App\Models\BookingPage;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A host who runs their meetings on Google Meet.
 *
 * The thing that must not happen is a booking taken against a Google Meet
 * page that quietly hands the guest a Netvork room instead — they would sit
 * in an empty one while the host waits in the other.
 */
class BookingMeetingProviderTest extends TestCase
{
    use RefreshDatabase;

    private function host(): User
    {
        $host = User::factory()->create();
        $host->profile()->create(['timezone' => 'UTC']);

        return $host;
    }

    private function page(User $host, array $overrides = []): BookingPage
    {
        $page = BookingPage::create(array_merge([
            'user_id' => $host->id,
            'slug' => 'host-' . $host->id,
            'duration_minutes' => 30,
            'min_notice_minutes' => 0,
            'is_active' => true,
        ], $overrides));

        foreach (range(0, 6) as $weekday) {
            BookingHour::create([
                'booking_page_id' => $page->id,
                'weekday' => $weekday,
                'start_time' => '00:00',
                'end_time' => '23:59',
            ]);
        }

        return $page->fresh('hours');
    }

    private function soon(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDay()->setTime(10, 0);
    }

    public function test_netvork_is_still_the_default_and_still_makes_a_room(): void
    {
        Mail::fake();
        $page = $this->page($this->host());

        $this->assertSame('netvork', $page->meeting_provider);

        $booking = app(BookingService::class)->book($page, $this->soon(), [
            'name' => 'Guest', 'email' => 'guest@example.com',
        ]);

        $this->assertNotNull($booking->meeting_id);
        $this->assertNull($booking->meeting_url);
        $this->assertNotEmpty($booking->meeting->passcode);
    }

    public function test_a_google_meet_page_books_into_the_hosts_own_room(): void
    {
        Mail::fake();
        $url = 'https://meet.google.com/abc-defg-hij';
        $page = $this->page($this->host(), [
            'meeting_provider' => 'google_meet',
            'external_meeting_url' => $url,
        ]);

        $booking = app(BookingService::class)->book($page, $this->soon(), [
            'name' => 'Guest', 'email' => 'guest@example.com',
        ]);

        // No Netvork room stood up beside it, and no second door for the guest.
        $this->assertNull($booking->meeting_id);
        $this->assertSame($url, $booking->meeting_url);

        // The calendar entry the host looks at points at the same place.
        $this->assertSame($url, $booking->event->meeting_link);
    }

    public function test_the_guest_is_told_the_external_link_and_no_passcode(): void
    {
        Mail::fake();
        $url = 'https://meet.google.com/abc-defg-hij';
        $page = $this->page($this->host(), [
            'meeting_provider' => 'google_meet',
            'external_meeting_url' => $url,
        ]);

        $booking = app(BookingService::class)->book($page, $this->soon(), [
            'name' => 'Guest', 'email' => 'guest@example.com',
        ]);

        $body = $this->get('/api/v1/bookings/' . $booking->manage_token)
            ->assertOk()->json('data.meeting');

        $this->assertSame('google_meet', $body['provider']);
        $this->assertSame($url, $body['join_url']);
        $this->assertNull($body['passcode']);
        $this->assertNull($body['code']);
    }

    public function test_a_booking_keeps_its_room_when_the_host_switches_provider(): void
    {
        Mail::fake();
        $url = 'https://meet.google.com/abc-defg-hij';
        $page = $this->page($this->host(), [
            'meeting_provider' => 'google_meet',
            'external_meeting_url' => $url,
        ]);

        $booking = app(BookingService::class)->book($page, $this->soon(), [
            'name' => 'Guest', 'email' => 'guest@example.com',
        ]);

        // The host changes their mind next week. Meetings already in somebody
        // else's diary must not move to a different room behind their back.
        $page->update(['meeting_provider' => 'netvork']);

        $this->assertSame($url, Booking::find($booking->id)->meeting_url);
    }

    public function test_google_meet_without_a_link_is_refused(): void
    {
        $host = $this->host();
        $this->page($host);

        $this->actingAs($host)
            ->putJson('/api/v1/booking-page', ['meeting_provider' => 'google_meet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('external_meeting_url');
    }

    public function test_a_link_that_is_not_google_meet_is_refused(): void
    {
        $host = $this->host();
        $this->page($host);

        foreach (['https://evil.example.com/meet', 'http://meet.google.com/abc', 'not a url'] as $bad) {
            $this->actingAs($host)
                ->putJson('/api/v1/booking-page', [
                    'meeting_provider' => 'google_meet',
                    'external_meeting_url' => $bad,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('external_meeting_url');
        }
    }

    public function test_a_good_link_is_saved_and_read_back(): void
    {
        $host = $this->host();
        $this->page($host);
        $url = 'https://meet.google.com/abc-defg-hij';

        $this->actingAs($host)
            ->putJson('/api/v1/booking-page', [
                'meeting_provider' => 'google_meet',
                'external_meeting_url' => $url,
            ])
            ->assertOk()
            ->assertJsonPath('data.meeting_provider', 'google_meet')
            ->assertJsonPath('data.external_meeting_url', $url);

        $this->actingAs($host)->getJson('/api/v1/booking-page')
            ->assertOk()
            ->assertJsonPath('data.external_meeting_url', $url);
    }
}
