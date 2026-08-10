<?php

namespace Tests\Feature;

use App\Console\Commands\ReapStaleMeetings;
use App\Models\Meeting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How big a meeting may get, and how long it may run.
 *
 * Both come from the HOST's plan and apply to everyone in the room — a guest
 * has no plan to consult, and a room that resized itself depending on who
 * walked in next would be impossible to explain to either of them.
 */
class MeetingPlanLimitTest extends TestCase
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

    /** Put the host on a plan with these meeting limits. */
    private function hostPlan(?int $people, ?int $minutes): void
    {
        $plan = Plan::create([
            'slug' => 'test-plan', 'name' => 'Test', 'monthly_price' => 0, 'annual_price' => 0,
            'limits' => ['max_meeting_participants' => $people, 'max_meeting_minutes' => $minutes],
            'features' => [],
        ]);

        Subscription::create([
            'user_id' => $this->host->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now()->subDay(),
        ]);
    }

    private function meeting(array $attrs = []): Meeting
    {
        return Meeting::create(array_merge([
            'code' => Meeting::generateCode(),
            'host_id' => $this->host->id,
            'type' => 'video',
            'requires_approval' => false,
            'status' => 'active',
            'started_at' => now(),
        ], $attrs));
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    // --- How many people ---------------------------------------------------

    public function test_the_room_fills_up_and_then_turns_people_away(): void
    {
        $this->hostPlan(people: 2, minutes: null);
        $meeting = $this->meeting();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->actingAs($this->member())->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $this->actingAs($this->member())
            ->postJson("/api/v1/meetings/{$meeting->code}/join")
            ->assertStatus(409)
            ->assertJsonPath('message', "This meeting is full — {$this->host->name}'s plan allows 2 people at once.");
    }

    public function test_somebody_already_inside_can_always_get_back_in(): void
    {
        // A refresh, a tunnel, a laptop lid. Refusing here would make a full
        // meeting impossible to rejoin rather than merely hard to enter.
        $this->hostPlan(people: 2, minutes: null);
        $meeting = $this->meeting();
        $alice = $this->member();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->actingAs($alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $this->actingAs($alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
    }

    public function test_the_host_is_never_locked_out_of_their_own_meeting(): void
    {
        // Being one over is a smaller wrong than the person paying for the
        // room standing outside it.
        $this->hostPlan(people: 1, minutes: null);
        $meeting = $this->meeting();

        $this->actingAs($this->member())->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
    }

    public function test_a_guest_is_turned_away_before_being_given_a_pass(): void
    {
        // Otherwise they type a name and a password, are handed credentials,
        // and only then learn there was never any space.
        $this->hostPlan(people: 1, minutes: null);
        $meeting = $this->meeting(['passcode' => 'open1234']);
        $this->actingAs($this->member())->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $this->postJson("/api/v1/meetings/{$meeting->code}/guest", [
            'name' => 'Prashant', 'passcode' => 'open1234',
        ])->assertStatus(409);
    }

    public function test_no_limit_means_no_limit(): void
    {
        $this->hostPlan(people: null, minutes: null);
        $meeting = $this->meeting();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->member())->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        }
    }

    // --- How long ----------------------------------------------------------

    public function test_a_meeting_ends_when_it_runs_out_of_time(): void
    {
        // The sequence that actually happens: you are in the room, and the
        // clock runs out while you are sitting in it. Starting from a meeting
        // that had already expired ended it on the way in instead, so the
        // heartbeat never saw the case this is about.
        $this->hostPlan(people: null, minutes: 40);
        $meeting = $this->meeting();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $this->travel(41)->minutes();

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.ended_reason', 'time_limit');

        $this->assertSame('ended', $meeting->fresh()->status);
    }

    public function test_arriving_late_to_the_news_still_learns_why(): void
    {
        // Only one poll catches the expiry first-hand. Everybody else — a
        // client a beat behind, a tab reconnecting afterwards — must not be
        // told the host ended a meeting the host never touched.
        $this->hostPlan(people: null, minutes: 40);
        $meeting = $this->meeting();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $this->travel(41)->minutes();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/heartbeat");

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.ended_reason', 'time_limit');
    }

    public function test_a_meeting_the_host_ended_does_not_blame_the_clock(): void
    {
        $this->hostPlan(people: null, minutes: 40);
        $meeting = $this->meeting();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/end")->assertOk();

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.ended_reason', null);
    }

    public function test_it_is_left_alone_before_the_time_is_up(): void
    {
        $this->hostPlan(people: null, minutes: 40);
        $meeting = $this->meeting(['started_at' => now()->subMinutes(39)]);
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join");

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_a_finished_meeting_cannot_be_walked_back_into(): void
    {
        $this->hostPlan(people: null, minutes: 40);
        $meeting = $this->meeting(['started_at' => now()->subMinutes(41)]);

        $this->actingAs($this->member())
            ->postJson("/api/v1/meetings/{$meeting->code}/join")
            ->assertStatus(410);

        $this->assertSame('ended', $meeting->fresh()->status);
    }

    public function test_the_reaper_catches_a_room_nobody_is_polling(): void
    {
        // Everybody closed their laptop. No heartbeat will ever arrive to
        // notice the meeting is over.
        $this->hostPlan(people: null, minutes: 40);
        $meeting = $this->meeting(['started_at' => now()->subMinutes(41)]);

        $this->artisan(ReapStaleMeetings::class)->assertSuccessful();

        $this->assertSame('ended', $meeting->fresh()->status);
    }

    public function test_the_clock_runs_from_the_first_arrival_not_from_creation(): void
    {
        // A link made in the morning and used at night still gets its full
        // length — started_at is when somebody first walked in.
        $this->hostPlan(people: null, minutes: 40);
        $meeting = $this->meeting(['status' => 'scheduled', 'started_at' => null]);
        $meeting->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->assertSame('active', $meeting->fresh()->status);
    }

    public function test_an_untimed_plan_never_expires_a_meeting(): void
    {
        $this->hostPlan(people: null, minutes: null);
        $meeting = $this->meeting(['started_at' => now()->subDays(1)]);
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting->code}/join");

        $this->actingAs($this->host)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.expires_at', null);
    }

    public function test_the_room_is_told_its_limits_so_it_can_warn_people(): void
    {
        $this->hostPlan(people: 4, minutes: 40);
        $meeting = $this->meeting();

        $this->actingAs($this->host)
            ->getJson("/api/v1/meetings/{$meeting->code}")
            ->assertOk()
            ->assertJsonPath('data.participant_limit', 4)
            ->assertJsonPath('data.minutes_limit', 40)
            ->assertJsonStructure(['data' => ['expires_at']]);
    }

    public function test_it_is_the_hosts_plan_that_counts_not_the_joiners(): void
    {
        // The meeting belongs to whoever opened it. A member on a bigger plan
        // does not get a bigger room by walking into a smaller one.
        $this->hostPlan(people: 1, minutes: null);
        $meeting = $this->meeting();

        $generous = Plan::create([
            'slug' => 'generous', 'name' => 'Generous', 'monthly_price' => 999, 'annual_price' => 9999,
            'limits' => ['max_meeting_participants' => null, 'max_meeting_minutes' => null],
            'features' => [],
        ]);
        $rich = $this->member();
        Subscription::create([
            'user_id' => $rich->id, 'plan_id' => $generous->id,
            'status' => 'active', 'started_at' => now()->subDay(),
        ]);

        $this->actingAs($this->member())->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $this->actingAs($rich)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertStatus(409);
    }
}
