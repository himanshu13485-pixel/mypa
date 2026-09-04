<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Notifications\SocialNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A selection of messages, passed on together.
 *
 * The single forward already worked; the reason this is not the browser
 * calling it in a loop is everything below — order, one notification rather
 * than ten, and a half-failure that can still say what happened.
 */
class ForwardManyTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $them;
    private Conversation $source;
    private Conversation $target;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->me = $this->person();
        $this->them = $this->person();

        $this->source = Conversation::create(['type' => 'direct']);
        $this->source->members()->attach([$this->me->id, $this->them->id]);

        $this->target = Conversation::create(['type' => 'direct']);
        $this->target->members()->attach([$this->me->id, $this->person()->id]);
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    /** @return list<Message> */
    private function say(array $bodies): array
    {
        return collect($bodies)->map(fn (string $body) => $this->source->messages()->create([
            'user_id' => $this->them->id,
            'type' => 'text',
            'body' => $body,
        ]))->all();
    }

    private function forward(array $messages, ?array $targets = null)
    {
        return $this->actingAs($this->me)->postJson(
            "/api/v1/conversations/{$this->source->uuid}/messages/forward",
            [
                'message_uuids' => collect($messages)->pluck('uuid')->all(),
                'conversation_uuids' => $targets ?? [$this->target->uuid],
            ],
        );
    }

    public function test_several_messages_land_in_the_other_conversation(): void
    {
        $said = $this->say(['One', 'Two', 'Three']);

        $this->forward($said)
            ->assertCreated()
            ->assertJsonPath('data.messages', 3);

        $this->assertSame(3, $this->target->messages()->count());
        $this->assertTrue($this->target->messages()->get()->every->is_forwarded);
    }

    public function test_they_arrive_in_the_order_they_were_written(): void
    {
        $said = $this->say(['One', 'Two', 'Three']);

        // Deliberately handed over shuffled: the client sends a set of what
        // was ticked, and the thread's order is the only one that means
        // anything on the other side.
        $this->forward([$said[2], $said[0], $said[1]])->assertCreated();

        $this->assertSame(
            ['One', 'Two', 'Three'],
            $this->target->messages()->orderBy('id')->pluck('body')->all(),
        );
    }

    public function test_a_batch_rings_once_rather_than_once_per_message(): void
    {
        $recipient = $this->target->members()->where('users.id', '!=', $this->me->id)->first();

        $this->forward($this->say(['One', 'Two', 'Three', 'Four']))->assertCreated();

        // Ten forwarded messages must not put ten lines on a lock screen.
        Notification::assertSentToTimes($recipient, SocialNotification::class, 1);
    }

    public function test_it_reaches_several_conversations_at_once(): void
    {
        $second = Conversation::create(['type' => 'direct']);
        $second->members()->attach([$this->me->id, $this->person()->id]);

        $this->forward($this->say(['One', 'Two']), [$this->target->uuid, $second->uuid])
            ->assertCreated()
            ->assertJsonPath('data.messages', 2);

        $this->assertSame(2, $this->target->messages()->count());
        $this->assertSame(2, $second->messages()->count());
    }

    public function test_a_conversation_that_is_not_yours_is_skipped_not_obeyed(): void
    {
        $strangers = Conversation::create(['type' => 'direct']);
        $strangers->members()->attach([$this->person()->id, $this->person()->id]);

        $this->forward($this->say(['One']), [$strangers->uuid])->assertStatus(422);

        $this->assertSame(0, $strangers->messages()->count());
    }

    public function test_a_message_from_somebody_elses_thread_is_not_carried(): void
    {
        // Only messages from the conversation in the URL are forwardable, so
        // a uuid picked from elsewhere finds nothing.
        $elsewhere = Conversation::create(['type' => 'direct']);
        $elsewhere->members()->attach([$this->person()->id, $this->person()->id]);
        $theirs = $elsewhere->messages()->create([
            'user_id' => $elsewhere->members()->first()->id,
            'type' => 'text',
            'body' => 'Private',
        ]);

        $this->forward([$theirs])->assertStatus(404);

        $this->assertSame(0, $this->target->messages()->count());
    }

    public function test_a_deleted_message_in_the_selection_is_left_behind(): void
    {
        $said = $this->say(['One', 'Two']);
        $said[0]->delete();

        $this->forward($said)->assertCreated();

        $this->assertSame(['Two'], $this->target->messages()->pluck('body')->all());
    }

    public function test_the_selection_has_a_ceiling(): void
    {
        $tooMany = \App\Http\Controllers\Api\V1\MessageController::MAX_FORWARD_AT_ONCE + 1;
        $uuids = collect(range(1, $tooMany))
            ->map(fn () => (string) \Illuminate\Support\Str::uuid())->all();

        $this->actingAs($this->me)->postJson(
            "/api/v1/conversations/{$this->source->uuid}/messages/forward",
            ['message_uuids' => $uuids, 'conversation_uuids' => [$this->target->uuid]],
        )->assertStatus(422)->assertJsonValidationErrors('message_uuids');
    }

    public function test_somebody_outside_the_conversation_cannot_forward_from_it(): void
    {
        $said = $this->say(['One']);
        $outsider = $this->person();

        $this->actingAs($outsider)->postJson(
            "/api/v1/conversations/{$this->source->uuid}/messages/forward",
            ['message_uuids' => [$said[0]->uuid], 'conversation_uuids' => [$this->target->uuid]],
        )->assertStatus(403);
    }
}
