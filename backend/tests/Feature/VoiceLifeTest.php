<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Voice assistant coverage for the Life modules: habits, goals, bills. */
class VoiceLifeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);
        $this->user = User::factory()->create();
        $this->user->profile()->create(['timezone' => 'UTC']);
    }

    protected function interpret(string $transcript, string $language = 'en'): array
    {
        return $this->actingAs($this->user)
            ->postJson('/api/v1/voice/interpret', ['transcript' => $transcript, 'language' => $language])
            ->assertOk()
            ->json('data');
    }

    public function test_create_daily_habit_with_reminder_time(): void
    {
        $result = $this->interpret('Start a daily habit to do yoga at 7 am');

        $this->assertEquals('create_habit', $result['intent']);
        $this->assertEquals('do yoga', $result['data']['habit']['name']);
        $this->assertEquals('daily', $result['data']['habit']['frequency']);
        $this->assertEquals('07:00', $result['data']['habit']['reminder_time']);
    }

    public function test_create_weekly_habit(): void
    {
        $result = $this->interpret('Add a habit to review finances every week');

        $this->assertEquals('create_habit', $result['intent']);
        $this->assertEquals('weekly', $result['data']['habit']['frequency']);
        $this->assertEquals('review finances', $result['data']['habit']['name']);
    }

    public function test_log_habit_resolves_existing_habit(): void
    {
        $habit = Habit::create(['user_id' => $this->user->id, 'name' => 'Morning Yoga', 'frequency' => 'daily']);

        $result = $this->interpret('Mark the yoga habit as done');

        $this->assertEquals('log_habit', $result['intent']);
        $this->assertEquals($habit->uuid, $result['data']['habit']['uuid']);
    }

    public function test_log_unknown_habit_reports_not_found(): void
    {
        $result = $this->interpret('Mark the swimming habit as done');

        $this->assertEquals('log_habit', $result['intent']);
        $this->assertNull($result['data']['habit']);
        $this->assertEquals('swimming', $result['data']['heard_name']);
    }

    public function test_create_goal_with_target_date(): void
    {
        $result = $this->interpret('Set a goal to read 12 books by next month');

        $this->assertEquals('create_goal', $result['intent']);
        $this->assertEquals('read 12 books', $result['data']['goal']['title']);
        $this->assertEquals(
            now('UTC')->addMonthNoOverflow()->startOfMonth()->toDateString(),
            $result['data']['goal']['target_date'],
        );
    }

    public function test_create_bill_with_amount_and_repeat(): void
    {
        $result = $this->interpret('Add electricity bill of 2000 monthly due tomorrow');

        $this->assertEquals('create_bill', $result['intent']);
        $this->assertEquals('Electricity', $result['data']['bill']['name']);
        $this->assertEquals(2000.0, $result['data']['bill']['amount']);
        $this->assertEquals('monthly', $result['data']['bill']['repeat_frequency']);
        $this->assertEquals(now('UTC')->addDay()->toDateString(), $result['data']['bill']['due_on']);
    }

    public function test_pay_bill_resolves_unpaid_bill(): void
    {
        $bill = \App\Models\Bill::create([
            'user_id' => $this->user->id,
            'name' => 'Electricity',
            'amount' => 2000,
            'due_on' => now()->addDay()->toDateString(),
            'status' => 'unpaid',
        ]);

        $result = $this->interpret('Mark the electricity bill as paid');

        $this->assertEquals('pay_bill', $result['intent']);
        $this->assertEquals($bill->uuid, $result['data']['bill']['uuid']);
        $this->assertEquals(2000.0, $result['data']['bill']['amount']);
    }

    public function test_pay_unknown_bill_reports_not_found(): void
    {
        $result = $this->interpret('I paid the water bill');

        $this->assertEquals('pay_bill', $result['intent']);
        $this->assertNull($result['data']['bill']);
        $this->assertEquals('water', $result['data']['heard_name']);
    }

    public function test_hindi_habit_command(): void
    {
        $result = $this->interpret('रोज़ पानी पीने की आदत बनाओ', 'hi');

        $this->assertEquals('create_habit', $result['intent']);
        $this->assertEquals('daily', $result['data']['habit']['frequency']);
        $this->assertStringContainsString('पानी', $result['data']['habit']['name']);
    }

    public function test_plain_task_creation_is_untouched(): void
    {
        $result = $this->interpret('Add a task to buy groceries tomorrow');

        $this->assertEquals('create_task', $result['intent']);
    }
}
