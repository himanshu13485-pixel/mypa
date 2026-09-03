<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * How long a message can be taken back, and from whom.
 *
 * Two deletions wearing one word. "For me" hides it from one person's screen
 * and has no clock — an old message you would rather not see is still an old
 * message tomorrow. "For everyone" removes it from the conversation, and that
 * one is bounded: the case it exists for is the message sent to the wrong chat
 * and noticed after lunch, not a week-old thread being hollowed out under the
 * people who already replied to it.
 */
class MessageDeleteWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $them;
    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->me = $this->person();
        $this->them = $this->person();

        $this->chat = Conversation::create(['type' => 'direct']);
        $this->chat->members()->attach([$this->me->id, $this->them->id]);
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    private function messageAgedHours(float $hours, ?Conversation $in = null): Message
    {
        $message = ($in ?? $this->chat)->messages()->create([
            'user_id' => $this->me->id,
            'type' => 'text',
            'body' => 'Sent to the wrong chat.',
        ]);

        $message->forceFill(['created_at' => now()->subMinutes((int) round($hours * 60))])->save();

        return $message->fresh();
    }

    private function unsend(Message $message, User $who, ?Conversation $in = null)
    {
        $uuid = ($in ?? $this->chat)->uuid;

        return $this->actingAs($who)
            ->deleteJson("/api/v1/conversations/{$uuid}/messages/{$message->uuid}?for=everyone");
    }

    public function test_a_message_from_this_morning_can_still_be_unsent(): void
    {
        $message = $this->messageAgedHours(5);

        $this->unsend($message, $this->me)->assertOk();

        $this->assertTrue($message->fresh()->trashed());
    }

    public function test_a_message_past_the_window_cannot(): void
    {
        $message = $this->messageAgedHours(Message::DELETE_WINDOW_HOURS + 1);

        $this->unsend($message, $this->me)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'delete it for yourself'));

        $this->assertFalse($message->fresh()->trashed());
    }

    public function test_deleting_it_only_for_yourself_has_no_clock(): void
    {
        $message = $this->messageAgedHours(Message::DELETE_WINDOW_HOURS * 10);

        $this->actingAs($this->me)
            ->deleteJson("/api/v1/conversations/{$this->chat->uuid}/messages/{$message->uuid}?for=me")
            ->assertOk();

        // Gone from their list, still in everybody else's.
        $this->assertFalse($message->fresh()->trashed());

        $mine = $this->actingAs($this->me)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")->json('data');
        $theirs = $this->actingAs($this->them)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")->json('data');

        $this->assertCount(0, $mine);
        $this->assertCount(1, $theirs);
    }

    public function test_an_unsent_message_leaves_a_mark_rather_than_a_gap(): void
    {
        $message = $this->messageAgedHours(1);
        $this->unsend($message, $this->me)->assertOk();

        $row = $this->actingAs($this->them)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")
            ->assertOk()
            ->json('data.0');

        // The reader is told something was there and is not any more — a
        // silent gap would let a conversation be quietly rewritten.
        $this->assertTrue($row['is_deleted']);
        $this->assertNull($row['body']);
    }

    public function test_the_clock_does_not_bind_whoever_runs_the_group(): void
    {
        $group = Group::create(['name' => 'Sales floor', 'owner_id' => $this->them->id]);
        $group->members()->attach([
            $this->them->id => ['role' => 'admin'],
            $this->me->id => ['role' => 'member'],
        ]);

        $room = Conversation::create(['type' => 'group', 'group_id' => $group->id]);
        $room->members()->attach([$this->me->id, $this->them->id]);

        // Said a week ago, reported today. A moderator who cannot act on that
        // is not a moderator.
        $old = $this->messageAgedHours(24 * 7, $room);

        $this->unsend($old, $this->them, $room)->assertOk();

        $this->assertTrue($old->fresh()->trashed());
    }

    public function test_the_button_disappears_at_the_same_moment_the_rule_bites(): void
    {
        $fresh = $this->messageAgedHours(1);
        $stale = $this->messageAgedHours(Message::DELETE_WINDOW_HOURS + 1);

        $rows = collect($this->actingAs($this->me)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")
            ->json('data'))
            ->keyBy('uuid');

        $this->assertTrue($rows[$fresh->uuid]['can_delete_for_everyone']);
        $this->assertFalse($rows[$stale->uuid]['can_delete_for_everyone']);

        // And never on somebody else's message in a chat with no moderator.
        $rows = collect($this->actingAs($this->them)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")
            ->json('data'))
            ->keyBy('uuid');

        $this->assertFalse($rows[$fresh->uuid]['can_delete_for_everyone']);
    }
}
