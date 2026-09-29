<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use App\Services\GroupLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A password on the group, set by whoever runs it.
 *
 * Different from the chat password in ChatLock, which is one person's own
 * arrangement over their own copy of a chat. This one belongs to the group:
 * its admins set it, and everybody in the group gives it - admins included,
 * because an admin's phone is as easy to pick up as anybody's.
 */
class GroupChatPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $member;
    private User $outsider;
    private Group $group;
    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->member, $this->outsider] = collect(['boss', 'staff', 'nobody'])
            ->map(function (string $name) {
                $user = User::factory()->create(['username' => $name, 'email' => "{$name}@test.test"]);
                $user->settings()->create([]);
                $user->profile()->create(['timezone' => 'UTC']);

                return $user;
            })->all();

        $this->group = Group::create(['owner_id' => $this->owner->id, 'name' => 'PWD', 'type' => 'private']);
        $this->group->members()->attach([
            $this->owner->id => ['role' => 'owner'],
            $this->member->id => ['role' => 'member'],
        ]);

        $this->chat = Conversation::create(['type' => 'group', 'group_id' => $this->group->id, 'name' => 'PWD']);
        $this->chat->members()->attach([$this->owner->id, $this->member->id]);
    }

    private function messages(User $as, ?string $token = null)
    {
        return $this->actingAs($as)
            ->withHeaders($token ? [GroupLock::HEADER => $token] : [])
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages");
    }

    public function test_an_admin_sets_it_and_everybody_including_the_admin_is_asked(): void
    {
        // Open to both of them before there is a password.
        $this->messages($this->owner)->assertOk();
        $this->messages($this->member)->assertOk();

        $set = $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertOk();

        // Shut for the member, and shut for the admin who just set it - a
        // lock its owner can walk past protects nothing on their own desk.
        $this->messages($this->member)->assertStatus(423)->assertJsonPath('scope', 'group');
        $this->messages($this->owner)->assertStatus(423);

        // Except that setting it counts as giving it, for the person sitting
        // there: they are handed a token rather than asked to type it twice.
        $this->messages($this->owner, $set->json('data.unlock_token'))->assertOk();
    }

    public function test_the_password_opens_it_and_a_wrong_one_does_not(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertOk();

        $this->actingAs($this->member)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password/open", [
            'password' => 'guess',
        ])->assertStatus(422);

        $token = $this->actingAs($this->member)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password/open", [
            'password' => '4821',
        ])->assertOk()->json('data.unlock_token');

        $this->messages($this->member, $token)->assertOk();
        // One person's token is theirs: it does not let anybody else in.
        $this->messages($this->owner, $token)->assertStatus(423);
    }

    public function test_only_the_people_who_run_the_group_may_set_or_remove_it(): void
    {
        $this->actingAs($this->member)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertForbidden();

        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertOk();

        $this->actingAs($this->member)
            ->deleteJson("/api/v1/groups/{$this->group->uuid}/chat-password", ['password' => '4821'])
            ->assertForbidden();

        // Changing it needs the old one, or an open session would be the password.
        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '9999', 'password_confirmation' => '9999',
        ])->assertStatus(422);

        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'current_password' => '4821', 'password' => '9999', 'password_confirmation' => '9999',
        ])->assertOk();
    }

    public function test_a_new_password_shuts_every_door_the_old_one_opened(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertOk();

        $token = $this->actingAs($this->member)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password/open", [
            'password' => '4821',
        ])->assertOk()->json('data.unlock_token');
        $this->messages($this->member, $token)->assertOk();

        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'current_password' => '4821', 'password' => '9999', 'password_confirmation' => '9999',
        ])->assertOk();

        // Whoever was inside on the old password is asked again.
        $this->messages($this->member, $token)->assertStatus(423);
    }

    public function test_taking_the_password_off_opens_the_group_again(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertOk();
        $this->messages($this->member)->assertStatus(423);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/groups/{$this->group->uuid}/chat-password", ['password' => 'wrong'])
            ->assertStatus(422);
        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/groups/{$this->group->uuid}/chat-password", ['password' => '4821'])
            ->assertOk();

        $this->messages($this->member)->assertOk();
    }

    public function test_the_lock_is_the_groups_business_and_an_outsider_learns_nothing(): void
    {
        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertOk();

        // Somebody not in the group cannot read the lock, nor try passwords at it.
        $this->actingAs($this->outsider)->getJson("/api/v1/groups/{$this->group->uuid}/chat-password")->assertForbidden();
        $this->actingAs($this->outsider)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password/open", [
            'password' => '4821',
        ])->assertForbidden();

        // Everybody in it sees that it is locked, and who to ask.
        $seen = $this->actingAs($this->member)->getJson("/api/v1/groups/{$this->group->uuid}/chat-password")
            ->assertOk()->json('data');
        $this->assertTrue($seen['has_password']);
        $this->assertFalse($seen['i_manage']);
        $this->assertSame($this->owner->name, $seen['set_by']);
    }

    public function test_a_locked_group_broadcasts_that_a_message_came_and_not_a_word_of_it(): void
    {
        $message = \App\Models\Message::create([
            'conversation_id' => $this->chat->id, 'user_id' => $this->owner->id,
            'type' => 'text', 'body' => 'The bank password is hunter2',
        ]);

        // Open, the room hears what was said.
        $this->assertStringContainsString('hunter2', (new \App\Events\MessageSent($message->fresh()))->broadcastWith()['preview']);

        $this->actingAs($this->owner)->postJson("/api/v1/groups/{$this->group->uuid}/chat-password", [
            'password' => '4821', 'password_confirmation' => '4821',
        ])->assertOk();

        /*
         * Locked, it hears only that something arrived. Everybody is on this
         * channel whether they have given the password or not, so the text
         * would go to exactly the people the lock keeps it from.
         */
        $this->assertNull((new \App\Events\MessageSent($message->fresh()))->broadcastWith()['preview']);
    }
}
