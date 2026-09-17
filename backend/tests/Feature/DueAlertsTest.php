<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DueNowNotification;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What is due right now, asked for from wherever the person is.
 *
 * Tasks and bills knew their own due dates and said so only on their own
 * screens, so a morning spent in the company CRM passed in silence. One list
 * answers for both, and it takes "not now" for an answer.
 */
class DueAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $somebodyElse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->me = $this->person('me@netvork.test');
        $this->somebodyElse = $this->person('other@netvork.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function task(array $attrs = []): Task
    {
        return Task::create($attrs + [
            'user_id' => $this->me->id,
            'title' => 'Send the renewal quote',
            'status' => 'pending',
            'due_at' => now()->subMinutes(5),
        ]);
    }

    private function bill(array $attrs = []): Bill
    {
        return Bill::create($attrs + [
            'user_id' => $this->me->id,
            'name' => 'Office electricity',
            'amount' => 4200,
            'currency' => 'INR',
            'due_on' => now()->toDateString(),
            'status' => 'unpaid',
        ]);
    }

    /**
     * A device to push to, so wantsPush() has something to find.
     *
     * Somebody with no phone registered is pushed nothing, which is right:
     * the screen alarm is what they get, and this is the one for the device
     * that is not looking at the app.
     */
    private function givesMeAPhone(): void
    {
        PushSubscription::create([
            'user_id' => $this->me->id,
            'endpoint' => 'https://push.example/me',
            'endpoint_hash' => hash('sha256', 'https://push.example/me'),
            'public_key' => 'key',
            'auth_token' => 'auth',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function due(?User $who = null): array
    {
        return $this->actingAs($who ?? $this->me)
            ->getJson('/api/v1/due-alerts')
            ->assertOk()->json('data');
    }

    public function test_it_answers_for_tasks_and_bills_in_one_list(): void
    {
        $this->task();
        $this->bill();

        $due = collect($this->due());

        $this->assertSame(['task', 'bill'], $due->pluck('kind')->all());
        $this->assertSame('Send the renewal quote', $due->firstWhere('kind', 'task')['title']);
        $this->assertSame('Office electricity', $due->firstWhere('kind', 'bill')['title']);
    }

    public function test_nothing_is_due_before_its_time(): void
    {
        $this->task(['due_at' => now()->addHours(3)]);
        $this->bill(['due_on' => now()->addDay()->toDateString()]);

        $this->assertSame([], $this->due());
    }

    public function test_a_bill_with_an_hour_on_it_waits_for_the_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00'));
        $this->bill(['due_on' => '2026-09-30', 'due_time' => '17:00:00']);

        // Nine in the morning is not five in the afternoon.
        $this->assertSame([], $this->due());

        Carbon::setTestNow(Carbon::parse('2026-09-30 17:01:00'));
        $this->assertCount(1, $this->due());
    }

    public function test_what_is_finished_stops_asking(): void
    {
        $this->task(['status' => 'completed']);
        $this->bill(['status' => 'paid']);

        $this->assertSame([], $this->due());
    }

    public function test_remind_me_in_fifteen_minutes(): void
    {
        $task = $this->task();

        $this->actingAs($this->me)->postJson('/api/v1/due-alerts/snooze', [
            'kind' => 'task', 'uuid' => $task->uuid, 'minutes' => 15,
        ])->assertOk();

        $this->assertSame([], $this->due());

        Carbon::setTestNow(now()->addMinutes(16));
        // Still due — a snooze is a pause, not an answer.
        $this->assertCount(1, $this->due());
    }

    public function test_not_today_goes_quiet_until_the_morning(): void
    {
        $bill = $this->bill();

        $this->actingAs($this->me)->postJson('/api/v1/due-alerts/snooze', [
            'kind' => 'bill', 'uuid' => $bill->uuid,
        ])->assertOk();

        $this->assertSame([], $this->due());

        Carbon::setTestNow(now()->addDay()->setTime(9, 1));
        $this->assertCount(1, $this->due());
    }

    public function test_the_phone_is_told_too_when_the_page_is_shut(): void
    {
        Notification::fake();
        $this->givesMeAPhone();
        $this->task();
        $this->bill();

        $this->artisan('mypa:push-due-alerts')->assertSuccessful();

        Notification::assertSentToTimes($this->me, DueNowNotification::class, 2);
        Notification::assertNothingSentTo($this->somebodyElse);
    }

    public function test_it_says_it_once_and_not_every_five_minutes(): void
    {
        Notification::fake();
        $this->givesMeAPhone();
        $this->task();

        $this->artisan('mypa:push-due-alerts')->assertSuccessful();
        $this->artisan('mypa:push-due-alerts')->assertSuccessful();
        $this->artisan('mypa:push-due-alerts')->assertSuccessful();

        // Still on screen, still due - but a phone buzzing every five
        // minutes about it is how people switch notifications off.
        Notification::assertSentToTimes($this->me, DueNowNotification::class, 1);
    }

    public function test_a_snooze_silences_the_phone_and_then_wakes_it(): void
    {
        Notification::fake();
        $this->givesMeAPhone();
        $task = $this->task();
        $this->artisan('mypa:push-due-alerts')->assertSuccessful();

        $this->actingAs($this->me)->postJson('/api/v1/due-alerts/snooze', [
            'kind' => 'task', 'uuid' => $task->uuid, 'minutes' => 15,
        ])->assertOk();

        // Snoozed on the laptop, so the phone stays quiet.
        $this->artisan('mypa:push-due-alerts')->assertSuccessful();
        Notification::assertSentToTimes($this->me, DueNowNotification::class, 1);

        // And speaks again when the quarter of an hour they asked for is up.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->artisan('mypa:push-due-alerts')->assertSuccessful();
        Notification::assertSentToTimes($this->me, DueNowNotification::class, 2);
    }

    public function test_nothing_goes_out_for_what_is_not_due(): void
    {
        Notification::fake();
        $this->givesMeAPhone();
        $this->task(['due_at' => now()->addHours(3)]);
        $this->bill(['status' => 'paid']);

        $this->artisan('mypa:push-due-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_one_person_is_never_told_about_anothers(): void
    {
        $this->task();
        $this->bill();

        $this->assertSame([], $this->due($this->somebodyElse));
    }

    public function test_nor_may_they_silence_it(): void
    {
        $task = $this->task();

        $this->actingAs($this->somebodyElse)->postJson('/api/v1/due-alerts/snooze', [
            'kind' => 'task', 'uuid' => $task->uuid, 'minutes' => 60,
        ])->assertNotFound();

        $this->assertCount(1, $this->due());
    }
}
