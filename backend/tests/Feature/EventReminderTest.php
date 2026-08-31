<?php

namespace Tests\Feature;

use App\Console\Commands\SendEventReminders;
use App\Models\Event;
use App\Models\User;
use App\Notifications\SocialNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The calendar was the last dated thing in the app that never spoke.
 *
 * Tasks, bills, meetings, habits, goals and project entries all had something
 * reading their dates and speaking up. Events had starts_at, an index on it,
 * and no reader — so an appointment booked for Tuesday at nine passed in
 * silence, and the only notification a calendar entry ever produced was the
 * invitation sent days earlier.
 */
class EventReminderTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->guest = User::factory()->create(['name' => 'Guest']);
        foreach ([$this->owner, $this->guest] as $user) {
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }
    }

    private function anEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $this->owner->id,
            'title' => 'Dentist',
            'starts_at' => now()->addMinutes(20),
            'all_day' => false,
        ], $attributes));
    }

    public function test_an_event_reminds_its_owner_and_everyone_invited(): void
    {
        Notification::fake();

        $event = $this->anEvent(['location' => 'Clinic Road']);
        $event->participants()->attach($this->guest->id, ['status' => 'accepted']);

        $this->artisan('mypa:send-event-reminders')->assertSuccessful();

        foreach ([$this->owner, $this->guest] as $person) {
            Notification::assertSentTo($person, SocialNotification::class,
                function (SocialNotification $note) {
                    $this->assertSame('event_reminder', $note->kind);
                    $this->assertStringContainsString('Dentist', $note->message);
                    // The place is half the reason a reminder is useful.
                    $this->assertStringContainsString('Clinic Road', $note->message);

                    return true;
                });
        }
    }

    public function test_a_reminder_is_sent_once_and_not_every_minute(): void
    {
        Notification::fake();

        $this->anEvent();

        $this->artisan('mypa:send-event-reminders')->assertSuccessful();
        $this->artisan('mypa:send-event-reminders')->assertSuccessful();
        $this->artisan('mypa:send-event-reminders')->assertSuccessful();

        // The sweep runs every minute; without reminded_at this would be
        // three notifications, then thirty.
        Notification::assertSentToTimes($this->owner, SocialNotification::class, 1);
    }

    public function test_somebody_who_declined_is_not_reminded(): void
    {
        Notification::fake();

        $event = $this->anEvent();
        $event->participants()->attach($this->guest->id, ['status' => 'declined']);

        $this->artisan('mypa:send-event-reminders')->assertSuccessful();

        // Declining is an answer, and the answer was no.
        Notification::assertNotSentTo($this->guest, SocialNotification::class);
        Notification::assertSentTo($this->owner, SocialNotification::class);
    }

    public function test_an_all_day_entry_does_not_fire_at_midnight(): void
    {
        Notification::fake();

        // An all-day entry starts at midnight, so a lead time would ring this
        // at half past eleven the night before to announce a birthday.
        $this->anEvent(['all_day' => true, 'starts_at' => now()->addMinutes(20)->startOfDay()]);

        $this->artisan('mypa:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_distant_event_waits_its_turn(): void
    {
        Notification::fake();

        $this->anEvent(['starts_at' => now()->addHours(6)]);

        $this->artisan('mypa:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_event_long_finished_is_not_announced(): void
    {
        Notification::fake();

        // A reminder for something that ended an hour ago is not a late
        // reminder, it is a wrong one.
        $this->anEvent(['starts_at' => now()->subHour()]);

        $this->artisan('mypa:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_moving_an_event_re_arms_its_reminder(): void
    {
        Notification::fake();

        $event = $this->anEvent();
        $this->artisan('mypa:send-event-reminders')->assertSuccessful();
        $this->assertNotNull($event->fresh()->reminded_at);

        // Rescheduling is exactly when people rely on being told, so a moved
        // event must not stay silenced by the reminder it already sent.
        $this->actingAs($this->owner)
            ->putJson("/api/v1/events/{$event->uuid}", [
                'title' => 'Dentist',
                'starts_at' => now()->addMinutes(15)->toIso8601String(),
            ])->assertOk();

        $this->assertNull($event->fresh()->reminded_at);

        $this->artisan('mypa:send-event-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->owner, SocialNotification::class, 2);
    }
}
