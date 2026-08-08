<?php

namespace Tests\Feature;

use App\Console\Commands\ReapStaleMeetings;
use App\Models\Meeting;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What happens to a meeting nobody started.
 *
 * The status column defaults to 'scheduled', so every room said "Scheduled"
 * from the moment it existed — including the ones created by a single press of
 * the instant button and then abandoned. Nothing had been scheduled, nothing
 * cleaned them up, and there was no way to delete one.
 */
class MeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $this->host = User::factory()->create(['name' => 'Himanshu Sachdeva']);
        $this->host->profile()->create(['timezone' => 'UTC']);
    }

    private function make(array $attrs = []): Meeting
    {
        return Meeting::create(array_merge([
            'code' => Meeting::generateCode(),
            'host_id' => $this->host->id,
            'type' => 'video',
        ], $attrs));
    }

    public function test_a_meeting_with_no_time_is_not_called_scheduled(): void
    {
        // The instant button sends nothing but the type. Calling the result
        // "Scheduled" claims a time was set for it; none was.
        $meeting = $this->make();

        $this->actingAs($this->host)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started');
    }

    public function test_a_meeting_with_a_time_really_is_scheduled(): void
    {
        $meeting = $this->make(['scheduled_at' => now()->addDay()]);

        $this->actingAs($this->host)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertJsonPath('data.status', 'scheduled');
    }

    public function test_one_that_ran_and_finished_is_unaffected(): void
    {
        $meeting = $this->make(['status' => 'ended', 'started_at' => now()->subHour(), 'ended_at' => now()]);

        $this->actingAs($this->host)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertJsonPath('data.status', 'ended');
    }

    public function test_the_host_can_delete_one(): void
    {
        $meeting = $this->make();

        $this->actingAs($this->host)
            ->deleteJson("/api/v1/meetings/{$meeting->code}")
            ->assertOk();

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_nobody_else_can_delete_it(): void
    {
        $meeting = $this->make();
        $someone = User::factory()->create();

        $this->actingAs($someone)
            ->deleteJson("/api/v1/meetings/{$meeting->code}")
            ->assertForbidden();

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    public function test_a_running_meeting_is_not_deleted_out_from_under_the_people_in_it(): void
    {
        $meeting = $this->make(['status' => 'active', 'started_at' => now()]);

        $this->actingAs($this->host)
            ->deleteJson("/api/v1/meetings/{$meeting->code}")
            ->assertStatus(409);

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    public function test_an_abandoned_meeting_clears_itself_out_after_a_day(): void
    {
        // Made by the instant button, backed out of, never opened again.
        $meeting = $this->make();
        $meeting->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->artisan(ReapStaleMeetings::class)->assertSuccessful();

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);
    }

    public function test_a_fresh_one_is_left_alone(): void
    {
        // Somebody may be in the lobby right now.
        $meeting = $this->make();

        $this->artisan(ReapStaleMeetings::class)->assertSuccessful();

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }

    public function test_anything_deliberate_is_never_swept_up(): void
    {
        $old = now()->subDays(30);

        $titled = $this->make(['title' => 'Weekly leadership sync']);
        $timed = $this->make(['scheduled_at' => now()->addMonth()]);
        $withPassword = $this->make(['passcode' => 'open1234']);
        foreach ([$titled, $timed, $withPassword] as $m) {
            $m->forceFill(['created_at' => $old])->saveQuietly();
        }

        // Named, timed, or shared with a password — each is someone taking the
        // trouble to set it up, and a link sent out for later must still work.
        $this->artisan(ReapStaleMeetings::class)->assertSuccessful();

        $this->assertDatabaseHas('meetings', ['id' => $titled->id]);
        $this->assertDatabaseHas('meetings', ['id' => $timed->id]);
        $this->assertDatabaseHas('meetings', ['id' => $withPassword->id]);
    }

    public function test_one_that_somebody_joined_is_never_swept_up(): void
    {
        // Untitled and untimed, but it was used — that is a record of a
        // meeting that happened, not litter.
        $meeting = $this->make(['status' => 'ended', 'started_at' => now()->subDays(3), 'ended_at' => now()->subDays(3)]);
        $meeting->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();
        $meeting->participants()->attach($this->host->id, ['status' => 'left']);

        $this->artisan(ReapStaleMeetings::class)->assertSuccessful();

        $this->assertDatabaseHas('meetings', ['id' => $meeting->id]);
    }
}
