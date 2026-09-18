<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Database\Seeders\RolePermissionSeeder as Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hour somebody sets on a task is the hour they get back.
 *
 * A due time crosses three boundaries — the browser's clock, the wire, and
 * the row it is written to — and losing the zone at any one of them shifts
 * the answer by five and a half hours in this office. A task set for a two
 * o'clock meeting read "6:30 AM" on the list and in the form, which is
 * exactly that shift.
 *
 * These assert the instant, never the wall-clock in storage: the app keeps
 * its rows in its own timezone, and a test that pinned down which zone the
 * database holds would fail the day that changed without anything being
 * wrong for anybody.
 */
class TaskDueTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    /** Noon in Delhi on the 19th, the meeting the task is about. */
    private const NOON_IST = '2026-09-19T12:00:00+05:30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Roles::class);

        $this->me = User::factory()->create(['email' => 'me@netvork.test', 'email_verified_at' => now()]);
        $this->me->settings()->create([]);
        $this->me->profile()->create(['timezone' => 'Asia/Kolkata']);
    }

    private function create(string $due): Task
    {
        $uuid = $this->actingAs($this->me)->postJson('/api/v1/tasks', [
            'title' => 'Attend lead meet',
            'due_at' => $due,
        ])->assertCreated()->json('data.uuid');

        return Task::where('uuid', $uuid)->firstOrFail();
    }

    public function test_an_instant_from_the_browser_survives_the_round_trip(): void
    {
        // What the app sends: the picker's wall-clock, turned into an
        // instant by the browser that knows its own zone.
        $task = $this->create('2026-09-19T06:30:00.000Z');

        $this->assertTrue(
            $task->due_at->equalTo(Carbon::parse(self::NOON_IST)),
            'Stored ' . $task->due_at->toIso8601String() . ', expected ' . self::NOON_IST,
        );
    }

    public function test_and_reads_back_as_the_same_moment(): void
    {
        $task = $this->create('2026-09-19T06:30:00.000Z');

        $due = $this->actingAs($this->me)
            ->getJson('/api/v1/tasks/' . $task->uuid)
            ->assertOk()->json('data.due_at');

        /*
         * The zone travels with it. Without one a browser reads the figure
         * as its own local time, which is the whole of this bug: the same
         * moment written as "06:30" and read in Delhi is half past six in
         * the morning rather than noon.
         */
        $this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:?\d{2})$/', $due);
        $this->assertTrue(Carbon::parse($due)->equalTo(Carbon::parse(self::NOON_IST)));
    }

    public function test_a_wall_clock_time_is_read_in_the_persons_own_zone(): void
    {
        // Anything that sends what was typed, rather than an instant, is
        // still read against the timezone on the person's profile.
        $task = $this->create('2026-09-19 12:00:00');

        $this->assertTrue($task->due_at->equalTo(Carbon::parse(self::NOON_IST)));
    }

    public function test_a_reminder_fires_against_the_same_clock(): void
    {
        $uuid = $this->actingAs($this->me)->postJson('/api/v1/tasks', [
            'title' => 'Attend lead meet',
            'due_at' => '2026-09-19T06:30:00.000Z',
            'reminders' => [['remind_at' => '2026-09-19T06:00:00.000Z']],
        ])->assertCreated()->json('data.uuid');

        $reminder = Task::where('uuid', $uuid)->firstOrFail()->reminders()->firstOrFail();

        // Half past eleven in Delhi: half an hour before the meeting.
        $this->assertTrue(
            $reminder->remind_at->equalTo(Carbon::parse('2026-09-19T11:30:00+05:30')),
            'Stored ' . $reminder->remind_at->toIso8601String(),
        );
    }

    public function test_an_overdue_task_is_one_whose_moment_has_passed(): void
    {
        // The comparison the app makes everywhere else, against a task
        // written through the API: an hour from now is not overdue.
        $task = $this->create(now()->addHour()->toIso8601String());

        $this->assertFalse($task->due_at->isPast());
    }
}
