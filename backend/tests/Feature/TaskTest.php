<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);
        $this->user = User::factory()->create();
    }

    public function test_user_can_create_task_with_checklist_and_tags(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/tasks', [
            'title' => 'Prepare presentation',
            'description' => 'Slides for Monday',
            'priority' => 'high',
            'due_at' => now()->addDays(2)->toDateTimeString(),
            'is_important' => true,
            'checklist' => [
                ['title' => 'Draft outline'],
                ['title' => 'Design slides'],
            ],
            'tags' => ['work', 'urgent'],
            'reminders' => [
                ['offset_minutes' => 60],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Prepare presentation')
            ->assertJsonCount(2, 'data.checklists')
            ->assertJsonCount(1, 'data.reminders');

        $this->assertDatabaseHas('tasks', ['title' => 'Prepare presentation', 'user_id' => $this->user->id]);
        $this->assertDatabaseCount('task_checklists', 2);
    }

    public function test_task_list_supports_filters(): void
    {
        Task::create(['user_id' => $this->user->id, 'title' => 'A', 'status' => 'completed', 'priority' => 'low']);
        Task::create(['user_id' => $this->user->id, 'title' => 'B', 'status' => 'in_progress', 'priority' => 'high', 'is_important' => true]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/tasks?status=in_progress')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'B');

        $this->actingAs($this->user)
            ->getJson('/api/v1/tasks?important=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_user_cannot_see_others_tasks(): void
    {
        $other = User::factory()->create();
        $task = Task::create(['user_id' => $other->id, 'title' => 'Private task']);

        $this->actingAs($this->user)->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user)->getJson("/api/v1/tasks/{$task->uuid}")->assertForbidden();
        $this->actingAs($this->user)->deleteJson("/api/v1/tasks/{$task->uuid}")->assertForbidden();
    }

    public function test_status_update_sets_completion(): void
    {
        $task = Task::create(['user_id' => $this->user->id, 'title' => 'Finish me']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/tasks/{$task->uuid}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 100);

        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $task = Task::create(['user_id' => $this->user->id, 'title' => 'X']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/tasks/{$task->uuid}/status", ['status' => 'nonsense'])
            ->assertUnprocessable();
    }

    public function test_dashboard_summary_returns_counts(): void
    {
        Task::create(['user_id' => $this->user->id, 'title' => 'Due today', 'due_at' => now()->addHour()]);
        Task::create(['user_id' => $this->user->id, 'title' => 'Overdue', 'due_at' => now()->subDay()]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.counts.overdue', 1)
            ->assertJsonStructure(['data' => ['counts', 'today_tasks', 'overdue_tasks', 'recent_tasks']]);
    }

    public function test_user_can_create_custom_category(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/categories', ['name' => 'Side Projects', 'color' => '#ff0000'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Side Projects');
    }

    public function test_system_categories_cannot_be_edited(): void
    {
        $system = \App\Models\Category::whereNull('user_id')->first();

        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$system->uuid}", ['name' => 'Hacked'])
            ->assertForbidden();
    }
}
