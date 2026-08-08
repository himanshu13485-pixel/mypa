<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Booking a meeting for Monday used to mean nobody was told when Monday came:
 * nothing in the app read `scheduled_at` at all.
 */
class MeetingReminderTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;

    protected User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->host = User::factory()->create(['name' => 'Alice']);
        $this->guest = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->host, $this->guest] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }
    }

    private function meetingAt(string $when, array $attrs = []): Meeting
    {
        $meeting = Meeting::create(array_merge([
            'code' => 'rem-indr-' . substr(md5($when . json_encode($attrs)), 0, 3),
            'host_id' => $this->host->id,
            'title' => 'Weekly sync',
            'type' => 'video',
            'status' => 'scheduled',
            'scheduled_at' => $when,
        ], $attrs));
        $meeting->participants()->attach([$this->guest->id => ['status' => 'invited']]);

        return $meeting;
    }

    public function test_everyone_invited_is_told_shortly_before_it_starts(): void
    {
        $meeting = $this->meetingAt(now()->addMinutes(8)->toDateTimeString());

        Notification::fake();
        $this->artisan('mypa:send-meeting-reminders')->assertSuccessful();

        foreach ([$this->host, $this->guest] as $person) {
            Notification::assertSentTo($person, SocialNotification::class, function ($n) {
                return $n->kind === 'meeting_soon' && str_contains($n->message, 'Weekly sync');
            });
        }

        $this->assertNotNull($meeting->fresh()->reminded_at);
    }

    public function test_it_is_only_said_once(): void
    {
        $this->meetingAt(now()->addMinutes(5)->toDateTimeString());

        Notification::fake();
        $this->artisan('mypa:send-meeting-reminders')->assertSuccessful();
        $this->artisan('mypa:send-meeting-reminders')->assertSuccessful();

        // The command runs every minute; without the marker this would be sent
        // sixty times before the meeting even began.
        Notification::assertSentToTimes($this->guest, SocialNotification::class, 1);
    }

    public function test_a_meeting_far_off_is_left_alone(): void
    {
        $meeting = $this->meetingAt(now()->addHours(3)->toDateTimeString());

        Notification::fake();
        $this->artisan('mypa:send-meeting-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($meeting->fresh()->reminded_at);
    }

    public function test_a_meeting_that_has_come_and_gone_is_not_announced(): void
    {
        $meeting = $this->meetingAt(now()->subHour()->toDateTimeString());

        Notification::fake();
        $this->artisan('mypa:send-meeting-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($meeting->fresh()->reminded_at);
    }

    public function test_a_meeting_already_running_is_not_announced(): void
    {
        // Someone started it early; a reminder now would be odd.
        $this->meetingAt(now()->addMinutes(4)->toDateTimeString(), ['status' => 'active']);

        Notification::fake();
        $this->artisan('mypa:send-meeting-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_reminder_links_to_the_room(): void
    {
        $meeting = $this->meetingAt(now()->addMinutes(2)->toDateTimeString());

        Notification::fake();
        $this->artisan('mypa:send-meeting-reminders')->assertSuccessful();

        Notification::assertSentTo($this->guest, SocialNotification::class, function ($n) use ($meeting) {
            return $n->actionPath === "/meetings/room/{$meeting->code}";
        });
    }
}
