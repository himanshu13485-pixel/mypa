<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase5VoiceTest extends TestCase
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

    public function test_remind_me_to_call_tomorrow_at_3pm(): void
    {
        $result = $this->interpret('Remind me to call Rahul tomorrow at 3 PM.');

        $this->assertEquals('create_task', $result['intent']);
        $this->assertStringContainsStringIgnoringCase('call rahul', $result['data']['task']['title']);
        $this->assertEquals(
            now('UTC')->addDay()->setTime(15, 0)->format('Y-m-d H:i'),
            substr($result['data']['task']['due_at'], 0, 16),
        );
        $this->assertNotEmpty($result['data']['task']['reminders']);
    }

    public function test_create_family_task_today(): void
    {
        $result = $this->interpret('Create a family task to buy groceries today.');

        $this->assertEquals('create_task', $result['intent']);
        $this->assertStringContainsStringIgnoringCase('buy groceries', $result['data']['task']['title']);
        $this->assertEquals('Family', $result['data']['task']['category_name'] ?? null);
        $this->assertEquals(now('UTC')->toDateString(), substr($result['data']['task']['due_at'], 0, 10));
    }

    public function test_schedule_meeting_next_monday(): void
    {
        $result = $this->interpret('Schedule a meeting with Amit next Monday at 11 AM.');

        $this->assertEquals('create_task', $result['intent']);
        $expected = now('UTC')->next(\Illuminate\Support\Carbon::MONDAY)->setTime(11, 0);
        $this->assertEquals(
            $expected->format('Y-m-d H:i'),
            substr($result['data']['task']['due_at'], 0, 16),
        );
        $this->assertStringContainsStringIgnoringCase('amit', $result['data']['task']['title']);
    }

    public function test_reminder_with_offset_before_due(): void
    {
        $result = $this->interpret('Remind me to pay the electricity bill tomorrow three days before the due date.');

        $this->assertEquals('create_task', $result['intent']);
        $this->assertEquals(3 * 1440, $result['data']['task']['reminders'][0]['offset_minutes']);
    }

    public function test_monthly_recurring_reminder(): void
    {
        $result = $this->interpret('Create a monthly reminder to pay office rent today.');

        $this->assertEquals('create_task', $result['intent']);
        $this->assertEquals('monthly', $result['data']['task']['repeat_config']['frequency']);
        $this->assertStringContainsStringIgnoringCase('pay office rent', $result['data']['task']['title']);
    }

    public function test_show_pending_important_tasks(): void
    {
        $result = $this->interpret('Show my pending important tasks.');

        $this->assertEquals('query_tasks', $result['intent']);
        $this->assertEquals(1, $result['data']['filters']['important']);
        $this->assertStringContainsString('not_started', $result['data']['filters']['status']);
    }

    public function test_mark_task_as_completed_finds_task(): void
    {
        Task::create(['user_id' => $this->user->id, 'title' => 'Doctor appointment']);

        $result = $this->interpret('Mark the doctor appointment task as completed.');

        $this->assertEquals('complete_task', $result['intent']);
        $this->assertEquals('Doctor appointment', $result['data']['task']['title']);
    }

    public function test_complete_unknown_task_returns_null(): void
    {
        $result = $this->interpret('Mark the quantum flux task as completed.');

        $this->assertEquals('complete_task', $result['intent']);
        $this->assertNull($result['data']['task']);
    }

    // --- Hindi ---------------------------------------------------------------

    public function test_hindi_reminder_tomorrow_evening(): void
    {
        $result = $this->interpret('मुझे कल शाम 5 बजे दवाई लेना याद दिलाओ', 'hi');

        $this->assertEquals('create_task', $result['intent']);
        $this->assertEquals(
            now('UTC')->addDay()->setTime(17, 0)->format('Y-m-d H:i'),
            substr($result['data']['task']['due_at'], 0, 16),
        );
        $this->assertStringContainsString('दवाई', $result['data']['task']['title']);
        $this->assertStringContainsString('जाँच', $result['speech']);
    }

    public function test_hindi_query_pending_tasks(): void
    {
        $result = $this->interpret('मेरे बाकी टास्क दिखाओ', 'hi');

        $this->assertEquals('query_tasks', $result['intent']);
        $this->assertStringContainsString('not_started', $result['data']['filters']['status']);
    }

    public function test_hindi_daily_repeat(): void
    {
        $result = $this->interpret('रोज़ सुबह योगा करना याद दिलाओ', 'hi');

        $this->assertEquals('create_task', $result['intent']);
        $this->assertEquals('daily', $result['data']['task']['repeat_config']['frequency']);
    }

    // --- Transcribe stub -----------------------------------------------------

    public function test_transcribe_returns_501_without_provider(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/voice/transcribe', [])
            ->assertStatus(501);
    }
}
