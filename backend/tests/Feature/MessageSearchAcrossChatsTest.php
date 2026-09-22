<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageDeletion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding a message without knowing which chat it was in.
 *
 * The thread search only looked inside the conversation already open; the
 * question people actually ask is "who sent me that, and where".
 */
class MessageSearchAcrossChatsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $priya;
    private User $vishal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = $this->person('Me');
        $this->priya = $this->person('Priyanshu');
        $this->vishal = $this->person('Vishal');
    }

    private function person(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function chat(User ...$people): Conversation
    {
        $conversation = Conversation::create(['type' => count($people) > 2 ? 'group' : 'direct', 'name' => count($people) > 2 ? 'Payments' : null]);
        $conversation->members()->attach(collect($people)->pluck('id'));

        return $conversation;
    }

    private function say(Conversation $c, User $who, string $body): Message
    {
        return $c->messages()->create(['user_id' => $who->id, 'type' => 'text', 'body' => $body]);
    }

    private function search(string $q)
    {
        return $this->actingAs($this->me)->getJson('/api/v1/messages/search?q=' . urlencode($q))->assertOk()->json('data');
    }

    public function test_it_finds_words_in_any_chat_and_says_which(): void
    {
        $direct = $this->chat($this->me, $this->priya);
        $group = $this->chat($this->me, $this->priya, $this->vishal);
        $this->say($direct, $this->priya, 'The HDFC account is 50200064177849');
        $this->say($group, $this->vishal, 'Please use the HDFC account for the refund');
        $this->say($group, $this->vishal, 'Lunch at one');

        $found = $this->search('hdfc');

        $this->assertCount(2, $found);
        // Newest first, and each one names where it was said and by whom.
        $this->assertSame('Payments', $found[0]['conversation_name']);
        $this->assertSame('Vishal', $found[0]['sender_name']);
        $this->assertSame('Priyanshu', $found[1]['conversation_name']);
        $this->assertSame($direct->uuid, $found[1]['conversation_uuid']);
    }

    public function test_a_chat_you_are_not_in_is_never_searched(): void
    {
        $theirs = $this->chat($this->priya, $this->vishal);
        $this->say($theirs, $this->priya, 'salary revision for everyone');

        $this->assertSame([], $this->search('salary'));
    }

    public function test_deleted_messages_stay_deleted(): void
    {
        $c = $this->chat($this->me, $this->priya);
        $gone = $this->say($c, $this->priya, 'the old password was tulip');
        $mine = $this->say($c, $this->priya, 'tulip bulbs arrived');
        MessageDeletion::create(['user_id' => $this->me->id, 'message_id' => $mine->id]);
        $gone->delete();

        $this->assertSame([], $this->search('tulip'));
    }

    public function test_the_snippet_shows_the_part_that_matched(): void
    {
        $c = $this->chat($this->me, $this->priya);
        $this->say($c, $this->priya, str_repeat('Background about the client and the order. ', 8)
            . 'The invoice number is INV-200622 please check. ' . str_repeat('More notes. ', 10));

        $snippet = $this->search('INV-200622')[0]['snippet'];

        $this->assertStringContainsString('INV-200622', $snippet);
        $this->assertStringStartsWith('…', $snippet);
        $this->assertLessThan(180, mb_strlen($snippet));
    }

    public function test_one_letter_finds_nothing_rather_than_everything(): void
    {
        $c = $this->chat($this->me, $this->priya);
        $this->say($c, $this->priya, 'a message');

        $this->assertSame([], $this->search('a'));
    }

    public function test_percent_and_underscore_are_searched_for_literally(): void
    {
        $c = $this->chat($this->me, $this->priya);
        $this->say($c, $this->priya, 'Discount is 5% this month');
        $this->say($c, $this->priya, 'Discount is 50 this month');

        $found = $this->search('5%');

        $this->assertCount(1, $found);
        $this->assertStringContainsString('5%', $found[0]['snippet']);
    }
}
