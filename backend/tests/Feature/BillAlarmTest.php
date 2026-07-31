<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillAlarmTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_day_alarm_rings_once_at_minutes_before_due_time(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();

        // Due in 10 minutes, alarm 15 minutes before -> we are already inside the window.
        $bill = Bill::create([
            'user_id' => $user->id,
            'name' => 'Electricity',
            'currency' => 'INR',
            'due_on' => now()->toDateString(),
            'due_time' => now()->addMinutes(10)->format('H:i'),
            'remind_minutes_before' => 15,
            'status' => 'unpaid',
            'remind_days_before' => 3,
        ]);

        $this->artisan('mypa:send-bill-alarms')->assertSuccessful();
        $this->assertEquals(1, $user->notifications()->count());
        $this->assertStringContainsString('Bill alarm', $user->notifications()->first()->data['message']);

        // Second run: no duplicate ring.
        $this->artisan('mypa:send-bill-alarms')->assertSuccessful();
        $this->assertEquals(1, $user->notifications()->count());

        // A bill whose alarm time is still in the future stays silent.
        Bill::create([
            'user_id' => $user->id,
            'name' => 'Rent',
            'currency' => 'INR',
            'due_on' => now()->addDay()->toDateString(),
            'due_time' => '09:00',
            'remind_minutes_before' => 30,
            'status' => 'unpaid',
            'remind_days_before' => 3,
        ]);
        $this->artisan('mypa:send-bill-alarms')->assertSuccessful();
        $this->assertEquals(1, $user->notifications()->count());
    }

    public function test_bill_accepts_due_time_and_alarm_via_api(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();

        $res = $this->actingAs($user)->postJson('/api/v1/bills', [
            'name' => 'Internet',
            'due_on' => now()->addDays(2)->toDateString(),
            'due_time' => '18:30',
            'remind_minutes_before' => 30,
        ])->assertCreated();

        $this->assertEquals('18:30', $res->json('data.due_time'));
        $this->assertEquals(30, $res->json('data.remind_minutes_before'));
    }
}
