<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\BillDueNotification;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class Phase6Test extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, PlanSeeder::class]);
        $this->user = User::factory()->create();
        $this->user->settings()->create([]);
    }

    // --- Habits -------------------------------------------------------------

    public function test_habit_log_and_streak(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/habits', [
            'name' => 'Morning yoga',
            'frequency' => 'daily',
        ]);
        $create->assertCreated();
        $uuid = $create->json('data.uuid');

        $habit = Habit::where('uuid', $uuid)->first();
        // Backfill yesterday and the day before.
        $habit->logs()->create(['logged_on' => now()->subDays(2)->toDateString(), 'count' => 1]);
        $habit->logs()->create(['logged_on' => now()->subDay()->toDateString(), 'count' => 1]);

        // Log today via API → streak of 3.
        $log = $this->actingAs($this->user)->postJson("/api/v1/habits/{$uuid}/log");
        $log->assertOk()
            ->assertJsonPath('data.done_today', true)
            ->assertJsonPath('data.streak', 3);

        // Un-log today (count 0) → streak of 2 (yesterday chain intact).
        $this->actingAs($this->user)
            ->postJson("/api/v1/habits/{$uuid}/log", ['count' => 0])
            ->assertOk()
            ->assertJsonPath('data.done_today', false)
            ->assertJsonPath('data.streak', 2);
    }

    public function test_habit_privacy(): void
    {
        $other = User::factory()->create();
        $habit = Habit::create(['user_id' => $other->id, 'name' => 'Private habit']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/habits/{$habit->uuid}/log")
            ->assertForbidden();
    }

    // --- Goals --------------------------------------------------------------

    public function test_goal_with_milestones_and_auto_completion(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/goals', [
            'title' => 'Learn guitar',
            'type' => 'personal',
            'milestones' => [
                ['title' => 'Buy a guitar'],
                ['title' => 'Learn 3 chords'],
            ],
        ]);
        $create->assertCreated()->assertJsonPath('data.progress', 0);
        $uuid = $create->json('data.uuid');
        $milestones = $create->json('data.milestones');

        // Toggle first milestone → 50%.
        $this->actingAs($this->user)
            ->postJson("/api/v1/goals/{$uuid}/milestones/{$milestones[0]['id']}/toggle")
            ->assertOk()
            ->assertJsonPath('data.progress', 50)
            ->assertJsonPath('data.status', 'active');

        // Toggle second → 100% and auto-completed.
        $this->actingAs($this->user)
            ->postJson("/api/v1/goals/{$uuid}/milestones/{$milestones[1]['id']}/toggle")
            ->assertOk()
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.status', 'completed');
    }

    // --- Bills --------------------------------------------------------------

    public function test_recurring_bill_mark_paid_spawns_next(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/bills', [
            'name' => 'Electricity',
            'amount' => 1500,
            'due_on' => now()->addDays(5)->toDateString(),
            'repeat_frequency' => 'monthly',
        ]);
        $create->assertCreated();
        $uuid = $create->json('data.uuid');

        $paid = $this->actingAs($this->user)->postJson("/api/v1/bills/{$uuid}/pay");
        $paid->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertEquals(
            now()->addDays(5)->addMonthNoOverflow()->toDateString(),
            $paid->json('next.due_on'),
        );
        $this->assertEquals(2, Bill::count());

        // Double-pay refused.
        $this->actingAs($this->user)->postJson("/api/v1/bills/{$uuid}/pay")->assertConflict();
    }

    public function test_bill_reminder_command_notifies_inside_window(): void
    {
        Notification::fake();

        // Inside window (due in 2 days, remind 3 before) → notify.
        Bill::create([
            'user_id' => $this->user->id, 'name' => 'Rent',
            'due_on' => now()->addDays(2)->toDateString(), 'remind_days_before' => 3,
        ]);
        // Outside window (due in 30 days) → no notification.
        Bill::create([
            'user_id' => $this->user->id, 'name' => 'Insurance',
            'due_on' => now()->addDays(30)->toDateString(), 'remind_days_before' => 3,
        ]);

        $this->artisan('mypa:send-bill-reminders')->assertSuccessful();

        Notification::assertSentTo($this->user, BillDueNotification::class, 1);
    }

    // --- Subscription architecture ------------------------------------------

    public function test_public_plans_endpoint(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains('free'));
        $this->assertTrue($slugs->contains('family'));
        $this->assertFalse($slugs->contains('enterprise'), 'private plans stay hidden');
    }

    public function test_my_subscription_defaults_to_free_with_usage(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.plan.slug', 'free')
            ->assertJsonPath('data.status', 'free')
            ->assertJsonStructure(['data' => ['usage' => ['tasks', 'storage', 'groups']]]);
    }

    public function test_free_plan_task_limit_blocks_creation(): void
    {
        // Tighten the free plan for the test.
        Plan::where('slug', 'free')->first()->update(['limits' => ['max_tasks' => 2]]);

        $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'One'])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'Two'])->assertCreated();

        $blocked = $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'Three']);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('task limit', $blocked->json('message'));
        $this->assertNotNull($blocked->json('upgrade_plan'), 'upgrade hint present');
    }

    public function test_paid_plan_lifts_task_limit(): void
    {
        Plan::where('slug', 'free')->first()->update(['limits' => ['max_tasks' => 1]]);

        $personal = Plan::where('slug', 'personal')->first();
        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => $personal->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        // Personal has unlimited tasks.
        $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'One'])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/tasks', ['title' => 'Two'])->assertCreated();

        $this->actingAs($this->user)
            ->getJson('/api/v1/subscription')
            ->assertJsonPath('data.plan.slug', 'personal');
    }

    public function test_expired_subscription_falls_back_to_free(): void
    {
        Plan::where('slug', 'free')->first()->update(['limits' => ['max_tasks' => 1]]);
        Subscription::create([
            'user_id' => $this->user->id,
            'plan_id' => Plan::where('slug', 'personal')->first()->id,
            'status' => 'active',
            'started_at' => now()->subYear(),
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/subscription')
            ->assertJsonPath('data.plan.slug', 'free');
    }

    public function test_group_limit_enforced(): void
    {
        Plan::where('slug', 'free')->first()->update(['limits' => ['max_groups' => 1]]);

        $this->actingAs($this->user)->postJson('/api/v1/groups', ['name' => 'First'])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/groups', ['name' => 'Second'])->assertStatus(422);
    }

    public function test_admin_can_assign_plan(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::where('slug', 'super_admin')->first()->id);

        $this->actingAs($admin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/plan", ['plan_slug' => 'family'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->getJson('/api/v1/subscription')
            ->assertJsonPath('data.plan.slug', 'family');

        // Regular user cannot assign plans.
        $this->actingAs($this->user)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/plan", ['plan_slug' => 'business'])
            ->assertForbidden();
    }

    // --- Goal visibility ------------------------------------------------------

    public function test_goal_privacy(): void
    {
        $other = User::factory()->create();
        $goal = Goal::create(['user_id' => $other->id, 'title' => 'Secret goal']);

        $this->actingAs($this->user)->getJson('/api/v1/goals')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user)
            ->putJson("/api/v1/goals/{$goal->uuid}", ['title' => 'Hijack'])
            ->assertForbidden();
    }
}
