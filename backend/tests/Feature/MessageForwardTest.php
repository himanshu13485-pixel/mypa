<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Passing a message along.
 *
 * Copying the text out and pasting it into three chats loses the attachments
 * and takes three trips.
 */
class MessageForwardTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $them;
    private User $third;
    private Conversation $source;
    private Conversation $target;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->me = $this->person();
        $this->them = $this->person();
        $this->third = $this->person();

        $this->source = $this->chatBetween($this->me, $this->them);
        $this->target = $this->chatBetween($this->me, $this->third);
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    private function chatBetween(User $a, User $b): Conversation
    {
        $c = Conversation::create(['type' => 'direct']);
        $c->members()->attach([$a->id, $b->id]);

        return $c;
    }

    private function message(?User $from = null, string $body = 'the original'): Message
    {
        return $this->source->messages()->create([
            'user_id' => ($from ?? $this->them)->id,
            'type' => 'text',
            'body' => $body,
        ]);
    }

    private function forward(Message $message, array $targets, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me)->postJson(
            "/api/v1/conversations/{$this->source->uuid}/messages/{$message->uuid}/forward",
            ['conversation_uuids' => $targets],
        );
    }

    public function test_a_message_arrives_in_the_other_thread(): void
    {
        $this->forward($this->message(), [$this->target->uuid])->assertCreated();

        $copy = $this->target->messages()->first();

        $this->assertNotNull($copy);
        $this->assertSame('the original', $copy->body);

        // Sent by whoever forwarded it, not by whoever wrote it.
        $this->assertSame($this->me->id, $copy->user_id);
    }

    /** Marked, because a forward reads exactly like something you wrote. */
    public function test_the_copy_says_it_was_forwarded(): void
    {
        $original = $this->message();
        $this->forward($original, [$this->target->uuid])->assertCreated();

        $this->assertFalse((bool) $original->fresh()->is_forwarded);
        $this->assertTrue((bool) $this->target->messages()->first()->is_forwarded);
    }

    public function test_it_reaches_several_threads_at_once(): void
    {
        $another = $this->chatBetween($this->me, $this->person());

        $this->forward($this->message(), [$this->target->uuid, $another->uuid])
            ->assertCreated()
            ->assertJsonCount(2, 'data.sent');

        $this->assertSame(1, $this->target->messages()->count());
        $this->assertSame(1, $another->messages()->count());
    }

    /**
     * The attachment is copied, not pointed at.
     *
     * Two rows sharing one file means deleting either message takes the
     * other one's attachment with it.
     */
    public function test_an_attachment_comes_across_as_its_own_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('chat-files/1/original.pdf', 'the bytes');

        $original = $this->message();
        $original->attachments()->create([
            'name' => 'contract.pdf',
            'path' => 'chat-files/1/original.pdf',
            'mime_type' => 'application/pdf',
            'size' => 9,
        ]);

        $this->forward($original, [$this->target->uuid])->assertCreated();

        $copied = $this->target->messages()->with('attachments')->first()->attachments->first();

        $this->assertNotNull($copied);
        $this->assertSame('contract.pdf', $copied->name);
        $this->assertNotSame('chat-files/1/original.pdf', $copied->path);
        Storage::disk('local')->assertExists($copied->path);

        // And the original is untouched.
        Storage::disk('local')->assertExists('chat-files/1/original.pdf');
    }

    /** A quote means nothing in a thread that never saw what was quoted. */
    public function test_the_reply_it_was_part_of_does_not_travel(): void
    {
        $answered = $this->message($this->them, 'the question');
        $reply = $this->source->messages()->create([
            'user_id' => $this->them->id,
            'type' => 'text',
            'body' => 'the answer',
            'reply_to_id' => $answered->id,
        ]);

        $this->forward($reply, [$this->target->uuid])->assertCreated();

        $this->assertNull($this->target->messages()->first()->reply_to_id);
    }

    public function test_a_thread_you_are_not_in_is_not_a_destination(): void
    {
        $theirs = $this->chatBetween($this->them, $this->third);

        $this->forward($this->message(), [$theirs->uuid])->assertStatus(422);
        $this->assertSame(0, $theirs->messages()->count());
    }

    public function test_you_cannot_forward_out_of_a_thread_you_are_not_in(): void
    {
        $this->forward($this->message(), [$this->target->uuid], $this->third)->assertForbidden();
    }

    public function test_an_announcement_group_still_only_takes_admins(): void
    {
        $group = Group::create(['owner_id' => $this->them->id, 'name' => 'Notices', 'type' => 'team', 'only_admins_post' => true]);
        $group->members()->attach($this->them->id, ['role' => 'owner']);
        $group->members()->attach($this->me->id, ['role' => 'member']);

        $chat = Conversation::create(['type' => 'group', 'group_id' => $group->id]);
        $chat->members()->attach([$this->me->id, $this->them->id]);

        $this->forward($this->message(), [$chat->uuid])
            ->assertCreated()
            ->assertJsonCount(0, 'data.sent')
            ->assertJsonCount(1, 'data.refused');

        $this->assertSame(0, $chat->messages()->count());
    }

    public function test_a_message_that_is_gone_cannot_be_passed_on(): void
    {
        $original = $this->message();
        $original->delete();

        $this->forward($original, [$this->target->uuid])->assertNotFound();
    }

    public function test_a_destination_is_required(): void
    {
        $this->actingAs($this->me)->postJson(
            "/api/v1/conversations/{$this->source->uuid}/messages/{$this->message()->uuid}/forward",
            [],
        )->assertStatus(422)->assertJsonValidationErrors('conversation_uuids');
    }
}
