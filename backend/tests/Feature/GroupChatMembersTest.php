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
 * The Members dialog in a group chat, and what it can do.
 *
 * It used to be a list of names and nothing else, which made two things
 * impossible at once. An owner could promote somebody to admin and the badge
 * never appeared, because roles were not in the response — the screen was not
 * hiding them, it had never been told. And nobody could be removed from where
 * everybody actually was, only from the Groups screen they would have to go
 * and find.
 *
 * Both are the same list, so both are fixed in it. Removing from the chat
 * removes from the group, because the chat is the group's: anything else
 * would put the person back the next time somebody opened the room.
 */
class GroupChatMembersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private User $member;
    private Group $group;
    private Conversation $conversation;

    private function person(string $name, string $username): User
    {
        $user = User::factory()->create([
            'name' => $name, 'username' => $username, 'email' => $username . '@netvork.test',
            'email_verified_at' => now(),
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

        $this->owner = $this->person('GrapOut CRM', 'grapoutcrm');
        $this->admin = $this->person('Himanshu Sachdeva', 'himanshu');
        $this->member = $this->person('Harsh', 'harsh');

        $this->group = Group::create([
            'owner_id' => $this->owner->id, 'name' => 'GrapOut Test', 'type' => 'team',
        ]);
        $this->group->members()->attach($this->owner->id, ['role' => 'owner']);
        $this->group->members()->attach($this->admin->id, ['role' => 'admin']);
        $this->group->members()->attach($this->member->id, ['role' => 'member']);

        $this->conversation = $this->openRoom();
    }

    /** Whoever opens the chat brings its membership up to date. */
    private function openRoom(): Conversation
    {
        $uuid = $this->actingAs($this->owner)
            ->getJson("/api/v1/groups/{$this->group->uuid}/conversation")
            ->assertOk()
            ->json('data.uuid');

        return Conversation::where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, array<string, mixed>> members by name */
    private function membersSeenBy(User $who): array
    {
        $rows = $this->actingAs($who)
            ->getJson("/api/v1/conversations/{$this->conversation->uuid}/members")
            ->assertOk()
            ->json('data');

        return collect($rows)->keyBy('name')->all();
    }

    public function test_the_dialog_says_who_runs_the_group(): void
    {
        $seen = $this->membersSeenBy($this->member);

        $this->assertSame('owner', $seen['GrapOut CRM']['role']);
        // The second admin — appointed by the owner — reads as one here too.
        $this->assertSame('admin', $seen['Himanshu Sachdeva']['role']);
        $this->assertSame('member', $seen['Harsh']['role']);
    }

    public function test_a_direct_chat_has_no_roles_and_nobody_to_remove(): void
    {
        $direct = Conversation::directBetween($this->owner, $this->member);

        $rows = $this->actingAs($this->owner)
            ->getJson("/api/v1/conversations/{$direct->uuid}/members")
            ->assertOk()
            ->json('data');

        foreach ($rows as $row) {
            $this->assertNull($row['role']);
            $this->assertFalse($row['can_remove']);
        }
    }

    public function test_an_admin_may_remove_a_member_and_the_owner_may_not_be_removed(): void
    {
        $seen = $this->membersSeenBy($this->admin);

        $this->assertTrue($seen['Harsh']['can_remove']);
        // Not even by an admin, and not by the owner either: a group without
        // an owner is a group nobody can delete.
        $this->assertFalse($seen['GrapOut CRM']['can_remove']);
        $this->assertFalse($this->membersSeenBy($this->owner)['GrapOut CRM']['can_remove']);

        $this->actingAs($this->admin)->deleteJson(
            "/api/v1/conversations/{$this->conversation->uuid}/members/{$this->owner->uuid}",
        )->assertStatus(422);
    }

    public function test_removing_somebody_from_the_chat_removes_them_from_the_group(): void
    {
        $this->actingAs($this->admin)->deleteJson(
            "/api/v1/conversations/{$this->conversation->uuid}/members/{$this->member->uuid}",
        )->assertOk();

        // Out of Family & Teams, not merely out of the room.
        $this->assertFalse($this->group->members()->where('users.id', $this->member->id)->exists());
        $this->assertFalse($this->conversation->hasMember($this->member));

        // And out for good: opening the room again must not put them back,
        // which is what syncWithoutDetaching used to do.
        $this->openRoom();
        $this->assertFalse($this->conversation->fresh()->hasMember($this->member));

        $this->actingAs($this->member)
            ->getJson("/api/v1/conversations/{$this->conversation->uuid}/members")
            ->assertForbidden();
    }

    public function test_the_room_is_told_who_left_and_who_did_it(): void
    {
        $this->actingAs($this->admin)->deleteJson(
            "/api/v1/conversations/{$this->conversation->uuid}/members/{$this->member->uuid}",
        )->assertOk();

        $said = $this->actingAs($this->owner)
            ->getJson("/api/v1/conversations/{$this->conversation->uuid}/messages")
            ->assertOk()
            ->json('data.0.body');

        $this->assertStringContainsString('Himanshu Sachdeva', $said);
        $this->assertStringContainsString('Harsh', $said);
    }

    public function test_a_plain_member_may_leave_but_may_not_show_anybody_else_out(): void
    {
        $seen = $this->membersSeenBy($this->member);
        $this->assertTrue($seen['Harsh']['can_remove']);
        $this->assertFalse($seen['Himanshu Sachdeva']['can_remove']);

        $this->actingAs($this->member)->deleteJson(
            "/api/v1/conversations/{$this->conversation->uuid}/members/{$this->admin->uuid}",
        )->assertForbidden();

        $this->actingAs($this->member)->deleteJson(
            "/api/v1/conversations/{$this->conversation->uuid}/members/{$this->member->uuid}",
        )->assertOk();

        $this->assertFalse($this->group->members()->where('users.id', $this->member->id)->exists());
    }

    public function test_a_member_added_to_the_group_is_in_its_chat_without_waiting(): void
    {
        $newcomer = $this->person('Karishma Yadav', 'karishma');

        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/members", [
            'app_id' => $newcomer->username,
        ])->assertCreated();

        // Before, the room only caught up when somebody happened to open it,
        // so a new member saw nothing and was not counted.
        $this->assertTrue($this->conversation->fresh()->hasMember($newcomer));
    }

    public function test_removing_from_the_group_screen_empties_the_chat_too(): void
    {
        $this->actingAs($this->owner)->deleteJson(
            "/api/v1/groups/{$this->group->uuid}/members/{$this->member->uuid}",
        )->assertOk();

        $this->assertFalse($this->conversation->fresh()->hasMember($this->member));
    }

    public function test_a_conversation_with_no_group_behind_it_has_nobody_to_remove(): void
    {
        $direct = Conversation::directBetween($this->owner, $this->member);

        $this->actingAs($this->owner)->deleteJson(
            "/api/v1/conversations/{$direct->uuid}/members/{$this->member->uuid}",
        )->assertStatus(422);
    }
}
