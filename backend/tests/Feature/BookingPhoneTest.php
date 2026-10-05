<?php

namespace Tests\Feature;

use App\Models\BookingPage;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A number to ring, on a booking made by a stranger.
 *
 * The form asks for a name and an email, and the email is how the joining
 * link travels - so that stays required. But the host often needs to reach
 * whoever booked in the minutes before a meeting: a call running late, a link
 * that will not open. An email is a poor way to say "I am two minutes away".
 *
 * Optional, deliberately: a booking link's whole value is that it takes
 * seconds, and a required phone number is the field that makes somebody close
 * the tab. So both halves are worth proving - that a number given is kept and
 * carried to the host, and that a booking without one still goes through.
 */
class BookingPhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->host = User::factory()->create(['name' => 'Ayan']);
        $this->host->settings()->create([]);
        $this->host->profile()->create(['timezone' => 'Asia/Kolkata']);

        $page = BookingPage::create([
            'user_id' => $this->host->id,
            'slug' => 'ayan',
            'title' => 'Intro call',
            'duration_minutes' => 30,
            'min_notice_minutes' => 0,
            'max_days_ahead' => 30,
            'is_active' => true,
        ]);
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $page->hours()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00']);
        }

        $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Asia/Kolkata'));
    }

    private function book(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/book/ayan', array_merge([
            'starts_at' => CarbonImmutable::parse('2026-09-02 11:00', 'Asia/Kolkata')->utc()->toIso8601String(),
            'name' => 'Riya',
            'email' => 'riya@example.com',
            'phone' => '+919876543210',
            'timezone' => 'Asia/Kolkata',
        ], $overrides));
    }

    public function test_a_number_given_is_kept(): void
    {
        $this->book(['phone' => '+919876543210'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+919876543210');

        $this->assertDatabaseHas('bookings', ['email' => 'riya@example.com', 'phone' => '+919876543210']);
    }

    public function test_the_number_reaches_the_host_where_they_will_look(): void
    {
        $this->book(['phone' => '+91 98765 43210', 'note' => 'About the pilot.'])->assertCreated();

        // The diary entry, which is what a host actually opens two minutes
        // before a meeting - not a list on another screen.
        $description = (string) Event::where('user_id', $this->host->id)->value('description');

        $this->assertStringContainsString('+919876543210', $description);
        // And none of what was already there is lost to make room for it.
        $this->assertStringContainsString('About the pilot.', $description);
        $this->assertStringContainsString('riya@example.com', $description);
    }

    public function test_the_host_sees_it_on_their_own_list_of_bookings(): void
    {
        $this->book(['phone' => '+919876543210'])->assertCreated();

        $this->actingAs($this->host)
            ->getJson('/api/v1/booking-page/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.phone', '+919876543210');
    }

    public function test_booking_without_a_number_is_refused(): void
    {
        // Required now: the host needs a way to reach whoever booked, and
        // the minutes before a meeting are not the time to discover there
        // isn't one.
        $this->book(['phone' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->book(['phone' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_number_without_a_country_is_refused(): void
    {
        /*
         * The form sends a country code and digits joined together, so a
         * number arriving without one did not come from the form. Refusing
         * it is what keeps the column in one shape - which is the whole
         * point of having asked for the country separately.
         */
        $this->book(['phone' => '9876543210'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_however_somebody_spaced_it(): void
    {
        // Typed by a person rather than sent by the form. The booking is not
        // refused over punctuation, and one shape is still what is stored.
        $this->book(['phone' => '+919876543210'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+919876543210');
    }

    public function test_a_number_that_is_not_a_number_is_refused(): void
    {
        $this->book(['phone' => '+' . str_repeat('9', 40)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->book(['phone' => '+91 ring me'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_country_other_than_india_is_taken_as_given(): void
    {
        // Every dialling code in the world is on the list, and the point of
        // that list is that a number from anywhere survives the journey.
        $this->book(['phone' => '+971501234567'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+971501234567');
    }
}
