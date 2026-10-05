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
            'timezone' => 'Asia/Kolkata',
        ], $overrides));
    }

    public function test_a_number_given_is_kept(): void
    {
        $this->book(['phone' => '+91 98765 43210'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '+91 98765 43210');

        $this->assertDatabaseHas('bookings', ['email' => 'riya@example.com', 'phone' => '+91 98765 43210']);
    }

    public function test_the_number_reaches_the_host_where_they_will_look(): void
    {
        $this->book(['phone' => '+91 98765 43210', 'note' => 'About the pilot.'])->assertCreated();

        // The diary entry, which is what a host actually opens two minutes
        // before a meeting - not a list on another screen.
        $description = (string) Event::where('user_id', $this->host->id)->value('description');

        $this->assertStringContainsString('+91 98765 43210', $description);
        // And none of what was already there is lost to make room for it.
        $this->assertStringContainsString('About the pilot.', $description);
        $this->assertStringContainsString('riya@example.com', $description);
    }

    public function test_the_host_sees_it_on_their_own_list_of_bookings(): void
    {
        $this->book(['phone' => '+91 98765 43210'])->assertCreated();

        $this->actingAs($this->host)
            ->getJson('/api/v1/booking-page/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.phone', '+91 98765 43210');
    }

    public function test_booking_without_a_number_still_works(): void
    {
        // The field is optional and has to stay optional: this is the whole
        // reason it is not required.
        $this->book()->assertCreated()->assertJsonPath('data.phone', null);

        $this->assertDatabaseHas('bookings', ['email' => 'riya@example.com', 'phone' => null]);
    }

    public function test_an_absurdly_long_number_is_refused(): void
    {
        $this->book(['phone' => str_repeat('9', 40)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_blank_number_is_treated_as_no_number(): void
    {
        // What an empty field posts, and it must not become an empty string
        // sitting where a number should be.
        $this->book(['phone' => ''])->assertCreated()->assertJsonPath('data.phone', null);
    }
}
