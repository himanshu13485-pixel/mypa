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

    public function test_pay_bill_amount_qualifier_picks_the_right_one(): void
    {
        \App\Models\Bill::create(['user_id' => $this->user->id, 'name' => 'Mobile', 'amount' => 5000, 'due_on' => now()->addDay(), 'status' => 'unpaid']);
        $wanted = \App\Models\Bill::create(['user_id' => $this->user->id, 'name' => 'Mobile', 'amount' => 2000, 'due_on' => now()->addDays(2), 'status' => 'unpaid']);

        $result = $this->interpret('Pay the 2000 mobile bill');

        $this->assertEquals('pay_bill', $result['intent']);
        $this->assertEquals($wanted->uuid, $result['data']['bill']['uuid']);
    }

    public function test_create_bill_already_paid(): void
    {
        $result = $this->interpret('Add car bill of 1000 paid');

        $this->assertEquals('create_bill', $result['intent']);
        $this->assertEquals('Car', $result['data']['bill']['name']);
        $this->assertTrue($result['data']['bill']['mark_paid'] ?? false);
    }

    public function test_create_bill_unpaid_word_does_not_mark_paid(): void
    {
        $result = $this->interpret('Add car bill rs. 1000 for sunday unpaid');

        $this->assertEquals('create_bill', $result['intent']);
        $this->assertEquals('Car', $result['data']['bill']['name']);
        $this->assertArrayNotHasKey('mark_paid', $result['data']['bill']);
    }

    public function test_hindi_habit_command(): void
    {
        $result = $this->interpret('रोज़ पानी पीने की आदत बनाओ', 'hi');

        $this->assertEquals('create_habit', $result['intent']);
        $this->assertEquals('daily', $result['data']['habit']['frequency']);
        $this->assertStringContainsString('पानी', $result['data']['habit']['name']);
    }

    /**
     * The AI fallback has to be able to reach the life intents.
     *
     * They were added to the pattern rules after AiIntentResolver was written
     * and never listed in its allowed intents, nor handled when mapping the
     * model's answer back — so a phrasing the patterns missed fell through to
     * "create a task" even with the model switched on.
     */
    public function test_ai_fallback_can_reach_habits_goals_and_bills(): void
    {
        $cases = [
            [['intent' => 'create_habit', 'name' => 'read at night', 'frequency' => 'weekly'],
                'create_habit', fn ($d) => $this->assertEquals('weekly', $d['habit']['frequency'])],
            [['intent' => 'create_goal', 'title' => 'run a marathon'],
                'create_goal', fn ($d) => $this->assertEquals('run a marathon', $d['goal']['title'])],
            // The bill parser title-cases names, as it does for typed ones.
            [['intent' => 'create_bill', 'name' => 'electricity', 'amount' => 2000],
                'create_bill', fn ($d) => $this->assertEqualsIgnoringCase('electricity', $d['bill']['name'])],
        ];

        foreach ($cases as [$aiAnswer, $expectedIntent, $assert]) {
            $this->mock(\App\Services\Voice\AiIntentResolver::class, function ($mock) use ($aiAnswer) {
                $mock->shouldReceive('resolve')->andReturn($aiAnswer);
            });

            // Deliberately phrased so no pattern rule matches it.
            $result = $this->interpret('would you kindly sort that out for me');

            $this->assertEquals($expectedIntent, $result['intent'], "AI answer {$aiAnswer['intent']} did not come through");
            $assert($result['data']);
        }
    }

    public function test_ai_fallback_still_defaults_to_a_task_when_it_cannot_help(): void
    {
        $this->mock(\App\Services\Voice\AiIntentResolver::class, function ($mock) {
            $mock->shouldReceive('resolve')->andReturn(null);
        });

        $result = $this->interpret('buy milk on the way home');
        $this->assertEquals('create_task', $result['intent']);
    }

    public function test_plain_task_creation_is_untouched(): void
    {
        $result = $this->interpret('Add a task to buy groceries tomorrow');

        $this->assertEquals('create_task', $result['intent']);
    }
}
