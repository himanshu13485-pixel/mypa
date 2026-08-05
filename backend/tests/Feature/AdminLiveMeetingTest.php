<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Admins can see what is running right now, and stop it. */
class AdminLiveMeetingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $host;

    protected User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
        $this->host = User::factory()->create(['name' => 'Himanshu Sachdeva']);
        $this->guest = User::factory()->create(['name' => 'Priya Verma']);
    }

    /** A meeting with people actually in the room. */
    protected function liveMeeting(array $present = []): Meeting
    {
        $meeting = Meeting::create([
            'code' => 'abc-defg-hij',
            'host_id' => $this->host->id,
            'title' => 'Standup',
            'type' => 'video',
            'status' => 'active',
            'started_at' => now()->subMinutes(12),
        ]);

        foreach ($present ?: [$this->host, $this->guest] as $u) {
            $meeting->participants()->attach($u->id, [
                'status' => 'joined',
                'joined_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return $meeting;
    }

    public function test_admin_sees_live_meetings_with_who_is_in_them(): void
    {
        $this->liveMeeting();

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/live-meetings')->assertOk();

        $res->assertJsonPath('meta.live_meetings', 1);
        $res->assertJsonPath('meta.people_in_meetings', 2);
        $res->assertJsonPath('data.0.code', 'abc-defg-hij');
        $res->assertJsonPath('data.0.participants', 2);
        $res->assertJsonPath('data.0.host.name', 'Himanshu Sachdeva');
        $this->assertGreaterThanOrEqual(11, $res->json('data.0.running_minutes'));
    }

    public function test_a_room_everyone_has_gone_quiet_in_is_not_reported_as_live(): void
    {
        // Still flagged active, but nobody has sent a heartbeat in minutes —
        // browsers closed without leaving. An admin should not be chasing it.
        $meeting = $this->liveMeeting();
        $meeting->participants()->newPivotStatement()
            ->where('meeting_id', $meeting->id)
            ->update(['last_seen_at' => now()->subMinutes(10)]);

        $res = $this->actingAs($this->admin)->getJson('/api/v1/admin/live-meetings')->assertOk();

        $res->assertJsonPath('meta.live_meetings', 0);
        $res->assertJsonCount(0, 'data');
    }

    public function test_admin_can_end_a_meeting_for_everyone(): void
    {
        $meeting = $this->liveMeeting();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/live-meetings/{$meeting->code}", ['reason' => 'Left running overnight'])
            ->assertOk()
            ->assertJsonPath('message', 'Meeting ended for everyone.');

        $meeting->refresh();
        $this->assertEquals('ended', $meeting->status);
        $this->assertNotNull($meeting->ended_at);

        // Nobody is left marked as still in the room.
        $this->assertSame(0, $meeting->participants()->wherePivot('status', 'joined')->count());
    }

    public function test_forcing_a_meeting_to_end_is_written_to_the_audit_log(): void
    {
        $meeting = $this->liveMeeting();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/live-meetings/{$meeting->code}", ['reason' => 'Reported abuse'])
            ->assertOk();

        $log = AuditLog::where('action', 'meeting.force_end')->latest('id')->first();
        $this->assertNotNull($log, 'ending a meeting must leave a trail');
        $this->assertEquals($this->admin->id, $log->actor_id);
        $this->assertEquals($meeting->id, $log->subject_id);
        $this->assertEquals('Reported abuse', $log->details['reason']);
        $this->assertEquals(2, $log->details['participants']);
    }

    public function test_ending_an_already_ended_meeting_is_rejected(): void
    {
        $meeting = $this->liveMeeting();
        $meeting->update(['status' => 'ended', 'ended_at' => now()]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/admin/live-meetings/{$meeting->code}")
            ->assertStatus(409);
    }

    public function test_ordinary_users_cannot_see_or_end_meetings(): void
    {
        $meeting = $this->liveMeeting();

        $this->actingAs($this->host)->getJson('/api/v1/admin/live-meetings')->assertForbidden();
        $this->actingAs($this->host)->deleteJson("/api/v1/admin/live-meetings/{$meeting->code}")->assertForbidden();

        // The host of the meeting is still just a user as far as admin is
        // concerned — it must not have ended.
        $this->assertEquals('active', $meeting->fresh()->status);
    }
}
