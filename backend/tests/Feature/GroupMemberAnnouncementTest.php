<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who joined, who left, and who was made an admin - said in the group's own
 * chat, not only to the one person it happened to.
 */
class GroupMemberAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $bala;
    protected User $chandra;
    protected Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $appIds = app(\App\Services\AppIdService::class);

        $make = function (string $name) use ($appIds) {
            $u = User::factory()->create(['name' => $name]);
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            $appIds->generateFor($u);

            return $u;
        };

        $this->owner = $make('Asha');
        $this->bala = $make('Bala');
        $this->chandra = $make('Chandra');

        $this->group = Group::create(['owner_id' => $this->owner->id, 'name' => 'GrapOut Payment Update', 'type' => 'team']);
        $this->group->members()->attach($this->owner->id, ['role' => 'owner']);
    }

    private function lines(): array
    {
        $conversation = Conversation::where('group_id', $this->group->id)->first();

        return $conversation ? $conversation->messages()->orderBy('id')->pluck('body')->all() : [];
    }

    public function test_adding_somebody_is_said_in_the_group(): void
    {
        // Nobody has opened the group's chat yet - the line must not depend on that.
        $this->assertNull(Conversation::where('group_id', $this->group->id)->first());

        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/members", [
            'app_id' => $this->bala->appId->app_id,
        ])->assertCreated();

        $this->assertSame(['➕ Asha added Bala to the group.'], $this->lines());

        // And Bala is in the room to read it.
        $this->assertTrue(Conversation::where('group_id', $this->group->id)->firstOrFail()->hasMember($this->bala));
    }

    public function test_a_role_change_and_a_removal_are_said_too(): void
    {
        foreach ([$this->bala, $this->chandra] as $who) {
            $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/members", [
                'app_id' => $who->appId->app_id,
            ])->assertCreated();
        }

        $this->actingAs($this->owner)
            ->putJson("/api/v1/groups/{$this->group->uuid}/members/{$this->bala->uuid}", ['role' => 'admin'])
            ->assertOk();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/groups/{$this->group->uuid}/members/{$this->chandra->uuid}")
            ->assertOk();

        $lines = $this->lines();
        $this->assertContains('⭐ Asha made Bala a group admin.', $lines);
        $this->assertContains('👋 Asha removed Chandra from the group.', $lines);
    }

    public function test_leaving_is_said_in_the_leavers_own_name(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/members", [
            'app_id' => $this->bala->appId->app_id,
        ])->assertCreated();

        $this->actingAs($this->bala)
            ->deleteJson("/api/v1/groups/{$this->group->uuid}/members/{$this->bala->uuid}")
            ->assertOk();

        $this->assertContains('👋 Bala left the group.', $this->lines());
    }
}
