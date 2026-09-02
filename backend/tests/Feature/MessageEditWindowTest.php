<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An hour to fix a typo, and then it stands.
 *
 * Editing used to be unbounded, so a message somebody had already answered
 * could be rewritten under their reply — the reply stays, the thing it
 * replied to does not.
 */
class MessageEditWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $them;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create();
        $this->me->profile()->create(['timezone' => 'UTC']);
        $this->them = User::factory()->create();
        $this->them->profile()->create(['timezone' => 'UTC']);

        $this->conversation = Conversation::create(['type' => 'direct']);
        $this->conversation->members()->attach([$this->me->id, $this->them->id]);
    }

    private function message(int $minutesAgo = 0, ?User $from = null, string $type = 'text'): Message
    {
        $message = $this->conversation->messages()->create([
            'user_id' => ($from ?? $this->me)->id,
            'type' => $type,
            'body' => 'the original',
        ]);

        // Written after creation: created_at is set by the timestamps.
        $message->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $message->fresh();
    }

    private function edit(Message $message, string $body = 'the correction')
    {
        return $this->actingAs($this->me)->putJson(
            "/api/v1/conversations/{$this->conversation->uuid}/messages/{$message->uuid}",
            ['body' => $body],
        );
    }

    public function test_a_message_just_sent_can_be_edited(): void
    {
        $message = $this->message(0);

        $this->edit($message)->assertOk();
        $this->assertSame('the correction', $message->fresh()->body);
    }

    public function test_a_message_inside_the_window_can_still_be_edited(): void
    {
        $message = $this->message(Message::EDIT_WINDOW_MINUTES - 1);

        $this->edit($message)->assertOk();
        $this->assertSame('the correction', $message->fresh()->body);
    }

    public function test_a_message_past_the_window_is_refused(): void
    {
        $message = $this->message(Message::EDIT_WINDOW_MINUTES + 1);

        $this->edit($message)->assertStatus(422);
        $this->assertSame('the original', $message->fresh()->body);
    }

    public function test_yesterdays_message_is_refused(): void
    {
        $message = $this->message(60 * 24);

        $this->edit($message)->assertStatus(422);
        $this->assertSame('the original', $message->fresh()->body);
    }

    /** The mark is the other half of the deal: an edit is never silent. */
    public function test_an_edited_message_says_so(): void
    {
        $message = $this->message(5);
        $this->assertNull($message->edited_at);

        $this->edit($message)->assertOk();
        $this->assertNotNull($message->fresh()->edited_at);
    }

    public function test_somebody_elses_message_is_refused_however_recent(): void
    {
        $message = $this->message(0, $this->them);

        $this->edit($message)->assertStatus(403);
        $this->assertSame('the original', $message->fresh()->body);
    }
}
