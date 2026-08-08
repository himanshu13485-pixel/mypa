<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The picture next to a name.
 *
 * An avatar is a short key naming one of the illustrations the client draws,
 * not a file — so the interesting behaviour is that it is validated, that it
 * reaches everyone who is allowed to see the person, and that it never leaks
 * past the profile-photo privacy setting.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }
    }

    private function connect(): void
    {
        \App\Models\Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    public function test_a_person_can_pick_an_avatar_and_take_it_off_again(): void
    {
        $this->actingAs($this->alice)
            ->putJson('/api/v1/me/profile', ['avatar' => 'f3'])
            ->assertOk()
            ->assertJsonPath('data.profile.avatar', 'f3');

        $this->assertSame('f3', $this->alice->fresh()->profile->avatar);

        $this->actingAs($this->alice)
            ->putJson('/api/v1/me/profile', ['avatar' => null])
            ->assertOk()
            ->assertJsonPath('data.profile.avatar', null);
    }

    public function test_only_a_key_the_client_can_draw_is_accepted(): void
    {
        foreach (['zz', 'f0', 'f10', 'm', '../../etc/passwd', '<script>'] as $bogus) {
            $this->actingAs($this->alice)
                ->putJson('/api/v1/me/profile', ['avatar' => $bogus])
                ->assertStatus(422);
        }

        // The column is 8 characters; nothing should ever get near it.
        $this->assertNull($this->alice->fresh()->profile->avatar);
    }

    public function test_picking_an_avatar_leaves_the_rest_of_the_profile_alone(): void
    {
        $this->alice->profile->update(['bio' => 'Runs the shop.', 'country' => 'India']);

        $this->actingAs($this->alice)->putJson('/api/v1/me/profile', ['avatar' => 'm2'])->assertOk();

        $profile = $this->alice->fresh()->profile;
        $this->assertSame('m2', $profile->avatar);
        $this->assertSame('Runs the shop.', $profile->bio);
        $this->assertSame('India', $profile->country);
    }

    public function test_the_people_you_talk_to_can_see_it(): void
    {
        $this->connect();
        $this->bob->profile->update(['avatar' => 'm5']);

        $conversation = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->assertCreated();
        $this->assertSame('m5', $conversation->json('data.other_user.avatar'));

        $suggest = $this->actingAs($this->alice)
            ->getJson('/api/v1/connections/suggest?q=Bob')->assertOk()->json('data');
        $this->assertSame('m5', $suggest[0]['avatar'] ?? null);
    }

    public function test_the_meeting_roster_carries_it_so_a_dark_tile_has_a_face(): void
    {
        $this->alice->profile->update(['avatar' => 'f1']);
        $this->bob->profile->update(['avatar' => 'm4']);

        $meeting = Meeting::create([
            'code' => 'ava-taar-abc',
            'host_id' => $this->alice->id,
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();
        $join = $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting->code}/join")->assertOk();

        $this->assertSame('f1', $join->json('data.joined_peers.0.avatar'));

        $roster = $this->actingAs($this->alice)
            ->postJson("/api/v1/meetings/{$meeting->code}/heartbeat")
            ->json('data.participants');
        $this->assertSame(
            ['f1', 'm4'],
            collect($roster)->sortBy('name')->pluck('avatar')->values()->all(),
        );
    }

    public function test_it_is_hidden_from_people_the_photo_would_be_hidden_from(): void
    {
        // An avatar stands in for the profile photo, so it answers to the same
        // setting — otherwise "hide my photo" would leak a picture anyway.
        $this->bob->profile->update(['avatar' => 'm1']);
        $this->bob->settings->update(['privacy' => ['profile_photo_visibility' => 'nobody']]);

        $found = $this->actingAs($this->alice)
            ->getJson("/api/v1/app-id/search?q={$this->bob->appId->app_id}")
            ->assertOk();

        $this->assertNull($found->json('data.avatar'));
    }
}
