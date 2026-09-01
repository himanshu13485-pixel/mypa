<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who runs a group, and what running it lets them do.
 *
 * Two questions this answers on purpose. Inside the group, an admin can
 * quiet it and can take down what should not have been said — somebody has
 * to be able to, and in a group of two hundred it will not be the author.
 * Outside it, being an admin buys nothing: a group is not a back door to
 * private conversations with people who never agreed to one.
 */
class GroupModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $promoted;
    private User $member;
    private Group $group;
    private Conversation $conversation;

    private function person(string $name, string $username): User
    {
        $user = User::factory()->create([
            'name' => $name, 'username' => $username, 'email' => $username . '@netvork.test',
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->owner = $this->person('Group Owner', 'gowner');
        $this->promoted = $this->person('Bee Admin', 'beeadmin');
        $this->member = $this->person('Plain Member', 'plainmember');

        $this->group = Group::create([
            'owner_id' => $this->owner->id, 'name' => 'Site Team', 'type' => 'team',
        ]);
        $this->group->members()->attach($this->owner->id, ['role' => 'owner']);
        $this->group->members()->attach($this->promoted->id, ['role' => 'member']);
        $this->group->members()->attach($this->member->id, ['role' => 'member']);

        $this->conversation = Conversation::firstOrCreate(
            ['type' => 'group', 'group_id' => $this->group->id],
            ['name' => $this->group->name, 'created_by' => $this->owner->id],
        );
        $this->conversation->members()->syncWithoutDetaching(
            $this->group->members()->pluck('users.id'),
        );
    }

    private function say(User $who, string $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($who)->postJson(
            "/api/v1/conversations/{$this->conversation->uuid}/messages", ['body' => $body],
        );
    }

    public function test_the_owner_hands_the_group_to_somebody_else_to_run(): void
    {
        // A plain member cannot promote anyone, including themselves.
        $this->actingAs($this->promoted)->putJson(
            "/api/v1/groups/{$this->group->uuid}/members/{$this->promoted->uuid}", ['role' => 'admin'],
        )->assertForbidden();

        $this->actingAs($this->owner)->putJson(
            "/api/v1/groups/{$this->group->uuid}/members/{$this->promoted->uuid}", ['role' => 'admin'],
        )->assertOk();

        $this->assertTrue($this->group->fresh()->canManage($this->promoted));

        // And now B runs it: they can take somebody out of the group.
        $this->actingAs($this->promoted)->deleteJson(
            "/api/v1/groups/{$this->group->uuid}/members/{$this->member->uuid}",
        )->assertOk();
    }

    public function test_an_announcement_group_lets_only_its_admins_write(): void
    {
        $this->say($this->member, 'Before the group was quieted')->assertCreated();

        $this->actingAs($this->owner)->putJson("/api/v1/groups/{$this->group->uuid}", [
            'only_admins_post' => true,
        ])->assertOk();

        // Everybody still reads it; only the people running it write.
        $this->say($this->member, 'Can I still talk?')->assertForbidden();
        $this->say($this->owner, 'Only this side now.')->assertCreated();
        $this->actingAs($this->member)
            ->getJson("/api/v1/conversations/{$this->conversation->uuid}/messages")
            ->assertOk();

        // A promoted admin writes too, and the switch goes back.
        $this->actingAs($this->owner)->putJson(
            "/api/v1/groups/{$this->group->uuid}/members/{$this->promoted->uuid}", ['role' => 'admin'],
        )->assertOk();
        $this->say($this->promoted, 'Admin speaking.')->assertCreated();

        $this->actingAs($this->owner)->putJson("/api/v1/groups/{$this->group->uuid}", [
            'only_admins_post' => false,
        ])->assertOk();
        $this->say($this->member, 'Talking again.')->assertCreated();
    }

    public function test_an_admin_can_take_down_a_message_that_is_not_theirs(): void
    {
        $said = $this->say($this->member, 'Something regrettable')->assertCreated()->json('data.uuid');

        // A fellow member cannot; it is not their message and not their group.
        $this->actingAs($this->promoted)->deleteJson(
            "/api/v1/conversations/{$this->conversation->uuid}/messages/{$said}?for=everyone",
        )->assertForbidden();

        // The owner can, because somebody has to be able to.
        $this->actingAs($this->owner)->deleteJson(
            "/api/v1/conversations/{$this->conversation->uuid}/messages/{$said}?for=everyone",
        )->assertOk();
        $this->assertSoftDeleted('messages', ['uuid' => $said]);
    }

    public function test_running_a_group_is_not_a_private_line_to_its_members(): void
    {
        $this->actingAs($this->owner)->putJson(
            "/api/v1/groups/{$this->group->uuid}/members/{$this->promoted->uuid}", ['role' => 'admin'],
        )->assertOk();

        // This member accepts messages from connections only, and B is not
        // one of them. Sharing a group changes nothing about that: the group
        // is where they may speak, and it is the only place.
        $this->member->settings->update(['privacy' => ['who_can_message' => 'connections']]);

        $this->actingAs($this->promoted)->postJson('/api/v1/conversations', [
            'app_id' => $this->member->appId->app_id,
        ])->assertForbidden();

        // Inside the group, both of them speak as they always could.
        $this->say($this->promoted, 'In the group, where we both are.')->assertCreated();
        $this->say($this->member, 'And so can I.')->assertCreated();
    }
}
