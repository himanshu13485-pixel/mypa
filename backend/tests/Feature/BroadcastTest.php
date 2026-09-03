<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * One message to several people, arriving as several private ones.
 *
 * Two things are being tested and only one of them is the delivery. The other
 * is the silence: a recipient must not be able to tell, from anything the API
 * hands them, that they were one of a list. That is the entire promise of the
 * feature, and it is exactly the sort of promise that survives being written
 * down and then quietly breaks the next time somebody adds a field to the
 * message serialiser.
 */
class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->sender = $this->person();
    }

    private function person(array $privacy = []): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create($privacy ? ['privacy' => $privacy] : []);

        return $user;
    }

    private function connect(User $a, User $b): void
    {
        Connection::create([
            'requester_id' => $a->id,
            'addressee_id' => $b->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    private function send(array $recipients, string $body = 'Office is shut on Friday.')
    {
        return $this->actingAs($this->sender)->postJson('/api/v1/broadcasts', [
            'user_uuids' => collect($recipients)->pluck('uuid')->all(),
            'body' => $body,
        ]);
    }

    public function test_each_recipient_gets_it_in_their_own_private_conversation(): void
    {
        $people = collect(range(1, 3))->map(function () {
            $them = $this->person();
            $this->connect($this->sender, $them);

            return $them;
        });

        $this->send($people->all())
            ->assertCreated()
            ->assertJsonPath('data.sent', 3);

        // Three conversations, not one room with four people in it.
        $this->assertSame(3, Conversation::where('type', 'direct')->count());

        foreach ($people as $them) {
            $conversation = Conversation::directBetween($this->sender, $them);

            $this->assertSame(2, $conversation->members()->count());
            $this->assertSame('Office is shut on Friday.', $conversation->messages()->first()->body);
        }
    }

    public function test_a_recipient_is_told_nothing_about_the_others(): void
    {
        $them = $this->person();
        $other = $this->person();
        $this->connect($this->sender, $them);
        $this->connect($this->sender, $other);

        $this->send([$them, $other])->assertCreated();

        $conversation = Conversation::directBetween($this->sender, $them);

        $body = $this->actingAs($them)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages")
            ->assertOk()
            ->json('data.0');

        // The message itself is ordinary in every respect a recipient can see.
        $this->assertNull($body['broadcast_to']);
        $this->assertFalse($body['is_own']);
        $this->assertSame('Office is shut on Friday.', $body['body']);

        // And nothing anywhere in the payload names the other recipient or
        // hints that there was a list at all.
        $raw = json_encode($body);
        $this->assertStringNotContainsString($other->uuid, $raw);
        $this->assertStringNotContainsString($other->name, $raw);
        $this->assertStringNotContainsString('broadcast_to":', str_replace('"broadcast_to":null', '', $raw));
    }

    public function test_the_sender_sees_how_many_it_went_to(): void
    {
        $them = $this->person();
        $other = $this->person();
        $this->connect($this->sender, $them);
        $this->connect($this->sender, $other);

        $this->send([$them, $other])->assertCreated();

        $conversation = Conversation::directBetween($this->sender, $them);

        $this->actingAs($this->sender)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.broadcast_to', 2);
    }

    public function test_it_cannot_reach_somebody_a_typed_message_could_not(): void
    {
        // Connections-only, and not connected: the same refusal opening a chat
        // by hand would get. A broadcast that got past this would be a way
        // round the setting rather than a convenience.
        $stranger = $this->person(['who_can_message' => 'connections']);
        $closed = $this->person(['who_can_message' => 'nobody']);
        $this->connect($this->sender, $closed);

        $reachable = $this->person();
        $this->connect($this->sender, $reachable);

        $response = $this->send([$stranger, $closed, $reachable])
            ->assertCreated()
            ->assertJsonPath('data.sent', 1);

        $this->assertCount(2, $response->json('data.refused'));

        $this->assertSame(0, Conversation::query()
            ->whereHas('members', fn ($m) => $m->where('users.id', $stranger->id))
            ->count());

        // The count the sender is shown is the number who actually got it.
        $this->assertSame(1, Broadcast::first()->recipient_count);
    }

    public function test_a_send_that_reaches_nobody_leaves_no_record(): void
    {
        $stranger = $this->person(['who_can_message' => 'connections']);

        $this->send([$stranger])->assertStatus(422);

        $this->assertSame(0, Broadcast::count());
        $this->assertSame(0, \App\Models\Message::count());
    }

    public function test_the_list_has_a_ceiling(): void
    {
        $uuids = collect(range(1, Broadcast::MAX_RECIPIENTS + 1))
            ->map(fn () => (string) \Illuminate\Support\Str::uuid())->all();

        $this->actingAs($this->sender)
            ->postJson('/api/v1/broadcasts', ['user_uuids' => $uuids, 'body' => 'hi'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_uuids');
    }

    public function test_the_live_event_carries_nothing_about_the_broadcast_either(): void
    {
        // The other surface a recipient's browser reads. The message payload
        // is a whitelist, which is why this passes today — and this test is
        // what makes it stay a whitelist.
        \Illuminate\Support\Facades\Event::fake([\App\Events\MessageSent::class]);

        $them = $this->person();
        $this->connect($this->sender, $them);

        $this->send([$them])->assertCreated();

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\MessageSent::class,
            function (\App\Events\MessageSent $event) {
                $payload = json_encode($event->broadcastWith());

                return ! str_contains($payload, 'broadcast');
            },
        );
    }

    public function test_recipients_are_notified_exactly_as_for_an_ordinary_message(): void
    {
        $them = $this->person();
        $this->connect($this->sender, $them);

        $this->send([$them])->assertCreated();

        Notification::assertSentTo($them, \App\Notifications\SocialNotification::class);
    }
}
