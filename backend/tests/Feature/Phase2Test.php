<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessDueReminders;
use App\Jobs\SendTaskReminder;
use App\Models\Event;
use App\Models\Task;
use App\Models\TaskReminder;
use App\Models\User;
use App\Notifications\TaskReminderNotification;
use App\Services\AppIdService;
use App\Services\RecurringTaskService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class Phase2Test extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create();
        $this->user->settings()->create([]);
    }

    // --- Reminder engine ----------------------------------------------------

    public function test_due_reminders_are_dispatched_by_scheduler_command(): void
    {
        Queue::fake();

        $task = Task::create(['user_id' => $this->user->id, 'title' => 'Due', 'due_at' => now()->addHour()]);
        $task->reminders()->create([
            'user_id' => $this->user->id,
            'remind_at' => now()->subMinute(),
        ]);
        // Not-yet-due reminder must not dispatch.
        $task->reminders()->create([
            'user_id' => $this->user->id,
            'remind_at' => now()->addHour(),
        ]);

        $this->artisan('mypa:process-reminders')->assertSuccessful();

        Queue::assertPushed(SendTaskReminder::class, 1);
    }

    public function test_reminder_job_notifies_user_and_marks_sent(): void
    {
        Notification::fake();

        $task = Task::create(['user_id' => $this->user->id, 'title' => 'Notify me', 'due_at' => now()->addHour()]);
        $reminder = $task->reminders()->create([
            'user_id' => $this->user->id,
            'remind_at' => now()->subMinute(),
        ]);

        (new SendTaskReminder($reminder->id))->handle();

        Notification::assertSentTo($this->user, TaskReminderNotification::class);
        $this->assertNotNull($reminder->fresh()->sent_at);
    }

    public function test_reminder_for_completed_task_is_not_sent(): void
    {
        Notification::fake();

        $task = Task::create(['user_id' => $this->user->id, 'title' => 'Done', 'status' => 'completed']);
        $reminder = $task->reminders()->create([
            'user_id' => $this->user->id,
            'remind_at' => now()->subMinute(),
        ]);

        (new SendTaskReminder($reminder->id))->handle();

        Notification::assertNothingSent();
        $this->assertNotNull($reminder->fresh()->acknowledged_at);
    }

    public function test_user_can_snooze_and_acknowledge_reminder(): void
    {
        $task = Task::create(['user_id' => $this->user->id, 'title' => 'Snooze me']);
        $reminder = $task->reminders()->create([
            'user_id' => $this->user->id,
            'remind_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/reminders/{$reminder->id}/snooze", ['minutes' => 30])
            ->assertOk();
        $this->assertNotNull($reminder->fresh()->snoozed_until);

        $this->actingAs($this->user)
            ->postJson("/api/v1/reminders/{$reminder->id}/acknowledge")
            ->assertOk();
        $this->assertNotNull($reminder->fresh()->acknowledged_at);
    }

    public function test_user_cannot_touch_another_users_reminder(): void
    {
        $other = User::factory()->create();
        $task = Task::create(['user_id' => $other->id, 'title' => 'Not yours']);
        $reminder = $task->reminders()->create(['user_id' => $other->id, 'remind_at' => now()]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/reminders/{$reminder->id}/snooze", ['minutes' => 10])
            ->assertForbidden();
    }

    // --- Recurring tasks ----------------------------------------------------

    public function test_completing_recurring_task_spawns_next_occurrence(): void
    {
        $task = Task::create([
            'user_id' => $this->user->id,
            'title' => 'Pay rent',
            'due_at' => now()->startOfDay(),
            'repeat_config' => ['frequency' => 'monthly', 'interval' => 1],
        ]);
        $task->checklists()->create(['title' => 'Transfer money', 'is_done' => true]);
        $task->reminders()->create([
            'user_id' => $this->user->id,
            'remind_at' => now()->subDay(),
            'offset_minutes' => 1440,
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/tasks/{$task->uuid}/status", ['status' => 'completed'])
            ->assertOk();

        $next = Task::where('title', 'Pay rent')->where('status', 'not_started')->first();
        $this->assertNotNull($next, 'Next occurrence should exist');
        $this->assertEquals(
            now()->startOfDay()->addMonthsNoOverflow(1)->toDateTimeString(),
            $next->due_at->toDateTimeString(),
        );
        // Checklist copied but reset; reminder recreated relative to new due date.
        $this->assertFalse($next->checklists()->first()->is_done);
        $this->assertEquals(
            $next->due_at->copy()->subMinutes(1440)->toDateTimeString(),
            $next->reminders()->first()->remind_at->toDateTimeString(),
        );
        // The finished occurrence leaves the series.
        $this->assertNull($task->fresh()->repeat_config);
    }

    public function test_recurrence_stops_after_until_date(): void
    {
        $task = Task::create([
            'user_id' => $this->user->id,
            'title' => 'Short series',
            'due_at' => now(),
            'repeat_config' => ['frequency' => 'daily', 'until' => now()->toDateString()],
        ]);

        $next = app(RecurringTaskService::class)->generateNext($task);

        $this->assertNull($next);
    }

    // --- Notifications API --------------------------------------------------

    public function test_notification_endpoints(): void
    {
        $task = Task::create(['user_id' => $this->user->id, 'title' => 'Ping', 'due_at' => now()]);
        $reminder = $task->reminders()->create(['user_id' => $this->user->id, 'remind_at' => now()]);
        $this->user->notify(new TaskReminderNotification($reminder));

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $list = $this->actingAs($this->user)->getJson('/api/v1/notifications')->assertOk();
        $id = $list->json('data.0.id');

        $this->actingAs($this->user)->postJson("/api/v1/notifications/{$id}/read")->assertOk();

        $this->actingAs($this->user)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.count', 0);
    }

    // --- Subtasks -----------------------------------------------------------

    public function test_subtask_creation_and_nesting_limit(): void
    {
        $parent = Task::create(['user_id' => $this->user->id, 'title' => 'Parent']);

        $response = $this->actingAs($this->user)->postJson('/api/v1/tasks', [
            'title' => 'Child',
            'parent_uuid' => $parent->uuid,
        ]);
        $response->assertCreated();

        $childUuid = $response->json('data.uuid');

        // Subtasks are hidden from the top-level list…
        $this->actingAs($this->user)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // …but returned when listing by parent.
        $this->actingAs($this->user)
            ->getJson("/api/v1/tasks?parent={$parent->uuid}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // No grandchildren.
        $this->actingAs($this->user)->postJson('/api/v1/tasks', [
            'title' => 'Grandchild',
            'parent_uuid' => $childUuid,
        ])->assertStatus(422);
    }

    // --- Events & calendar --------------------------------------------------

    public function test_event_crud_and_participants(): void
    {
        $other = User::factory()->create();
        app(AppIdService::class)->generateFor($other);

        $response = $this->actingAs($this->user)->postJson('/api/v1/events', [
            'title' => 'Team meeting',
            'type' => 'meeting',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDay()->addHour()->toDateTimeString(),
            'participants' => [$other->appId->app_id],
        ]);
        $response->assertCreated()->assertJsonPath('data.title', 'Team meeting');
        $uuid = $response->json('data.uuid');

        // Participant sees it and can respond.
        $this->actingAs($other)
            ->getJson("/api/v1/events/{$uuid}")
            ->assertOk();
        $this->actingAs($other)
            ->postJson("/api/v1/events/{$uuid}/respond", ['status' => 'accepted'])
            ->assertOk();

        // Stranger cannot see or edit it.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/v1/events/{$uuid}")->assertForbidden();
        $this->actingAs($other)->putJson("/api/v1/events/{$uuid}", ['title' => 'Hijack'])->assertForbidden();

        // Owner can update and delete.
        $this->actingAs($this->user)->putJson("/api/v1/events/{$uuid}", ['title' => 'Renamed'])->assertOk();
        $this->actingAs($this->user)->deleteJson("/api/v1/events/{$uuid}")->assertOk();
    }

    public function test_calendar_feed_combines_tasks_and_events(): void
    {
        Task::create(['user_id' => $this->user->id, 'title' => 'Due task', 'due_at' => now()->addDay()]);
        Event::create([
            'user_id' => $this->user->id,
            'title' => 'Party',
            'starts_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/calendar/feed?date_from=' . now()->toDateString() . '&date_to=' . now()->addDays(2)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data.events')
            ->assertJsonCount(1, 'data.tasks');
    }

    public function test_ics_export_returns_calendar_file(): void
    {
        Event::create(['user_id' => $this->user->id, 'title' => 'ICS event', 'starts_at' => now()->addDay()]);

        $response = $this->actingAs($this->user)->get('/api/v1/calendar/export.ics');

        $response->assertOk();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $response->getContent());
        $this->assertStringContainsString('ICS event', $response->getContent());
    }
}
