<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\MobileOtp;
use App\Models\User;
use App\Notifications\ChatLockResetNotification;
use App\Notifications\SocialNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Chats kept behind a password, and chats kept out of sight.
 *
 * One password per person opens every chat they locked and the folder of
 * the ones they hid. The lock is enforced by the server, not drawn on the
 * screen: a chat that only looked locked would hand its messages to anybody
 * who opened the network tab.
 */
class ChatLockTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $priya;
    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = $this->person('Me', 'me@netvork.test');
        $this->priya = $this->person('Priyanshu', 'priya@netvork.test');

        $this->chat = Conversation::create(['type' => 'direct']);
        $this->chat->members()->attach([$this->me->id, $this->priya->id]);
        $this->chat->messages()->create(['user_id' => $this->priya->id, 'type' => 'text', 'body' => 'Salary slips attached']);
    }

    private function person(string $name, string $email): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function setPassword(string $password = '123456'): string
    {
        return $this->actingAs($this->me)->postJson('/api/v1/chat-lock', [
            'password' => $password, 'password_confirmation' => $password,
        ])->assertOk()->json('data.token');
    }

    private function thread(?string $token = null)
    {
        return $this->actingAs($this->me)->withHeaders($token ? ['X-Chat-Unlock' => $token] : [])
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages");
    }

    private function list(array $query = [], ?string $token = null)
    {
        return $this->actingAs($this->me)->withHeaders($token ? ['X-Chat-Unlock' => $token] : [])
            ->getJson('/api/v1/conversations?' . http_build_query($query));
    }

    // ---- Locking ----------------------------------------------------------

    public function test_a_locked_chat_will_not_give_up_its_messages_without_the_password(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456'])
            ->assertOk();

        // A fresh session: no unlock yet.
        $this->app['cache']->flush();
        $this->thread()->assertStatus(423)->assertJsonPath('locked', true);

        $token = $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '123456'])
            ->assertOk()->json('data.token');

        $this->thread($token)->assertOk()->assertJsonPath('data.0.body', 'Salary slips attached');
    }

    public function test_the_wrong_password_opens_nothing(): void
    {
        $this->setPassword();

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '654321'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_locked_chat_still_shows_in_the_list_marked_locked(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456']);

        $row = collect($this->list()->assertOk()->json('data'))->firstWhere('uuid', $this->chat->uuid);

        $this->assertTrue($row['is_locked']);
        $this->assertFalse($row['is_hidden']);
    }

    public function test_the_other_person_sees_nothing_change(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456']);

        // Priyanshu's own view of the same chat is untouched.
        $this->actingAs($this->priya)->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")->assertOk();
        $row = collect($this->actingAs($this->priya)->getJson('/api/v1/conversations')->json('data'))
            ->firstWhere('uuid', $this->chat->uuid);
        $this->assertFalse($row['is_locked']);
    }

    public function test_locking_needs_a_password_to_exist_first(): void
    {
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456'])
            ->assertStatus(422);
    }

    // ---- Hiding -----------------------------------------------------------

    public function test_a_hidden_chat_is_absent_from_the_list_and_found_only_with_the_password(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => '123456'])
            ->assertOk();
        $this->app['cache']->flush();

        $this->assertNull(collect($this->list()->json('data'))->firstWhere('uuid', $this->chat->uuid));
        $this->list(['hidden' => 1])->assertStatus(423);

        $token = $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '123456'])->json('data.token');
        $hidden = $this->list(['hidden' => 1], $token)->assertOk()->json('data');

        $this->assertSame([$this->chat->uuid], collect($hidden)->pluck('uuid')->all());
        $this->thread($token)->assertOk();
    }

    public function test_unhiding_asks_for_the_password_and_brings_it_back_to_the_list(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => '123456']);

        $this->actingAs($this->me)->deleteJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => 'nope'])
            ->assertStatus(422);
        $this->actingAs($this->me)->deleteJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => '123456'])
            ->assertOk();

        $this->app['cache']->flush();
        $row = collect($this->list()->json('data'))->firstWhere('uuid', $this->chat->uuid);
        $this->assertFalse($row['is_hidden']);
        $this->assertFalse($row['is_locked']);
        $this->thread()->assertOk();
    }

    /** Hidden and locked are two things a chat can be at once. */
    public function test_a_chat_can_be_hidden_and_locked_and_unhiding_keeps_the_lock(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => '123456'])->assertOk();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456'])->assertOk();

        $token = $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '123456'])->json('data.token');
        $row = collect($this->list(['hidden' => 1], $token)->json('data'))->firstWhere('uuid', $this->chat->uuid);
        $this->assertTrue($row['is_hidden']);
        $this->assertTrue($row['is_locked']);

        $this->actingAs($this->me)->deleteJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => '123456'])->assertOk();

        $row = collect($this->list()->json('data'))->firstWhere('uuid', $this->chat->uuid);
        $this->assertFalse($row['is_hidden']);
        $this->assertTrue($row['is_locked']);
    }

    public function test_a_hidden_chat_adds_nothing_to_the_unread_badge(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => '123456']);

        $this->actingAs($this->me)->getJson('/api/v1/badges')->assertOk()->assertJsonPath('data.messages', 0);
    }

    // ---- Everywhere else a chat's words could escape -----------------------

    public function test_search_leaves_sealed_chats_out_until_the_password_is_in(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456']);
        $this->app['cache']->flush();

        $this->actingAs($this->me)->getJson('/api/v1/messages/search?q=salary')->assertOk()->assertJsonCount(0, 'data');

        $token = $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '123456'])->json('data.token');
        $this->actingAs($this->me)->withHeaders(['X-Chat-Unlock' => $token])
            ->getJson('/api/v1/messages/search?q=salary')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_notification_from_a_locked_chat_says_nothing_about_it(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456']);
        Notification::fake();

        $this->actingAs($this->priya)->postJson("/api/v1/conversations/{$this->chat->uuid}/messages", ['body' => 'The PAN is ABCDE1234F'])
            ->assertCreated();

        Notification::assertSentTo($this->me, SocialNotification::class, function (SocialNotification $n) {
            return $n->message === 'You have a new message.'
                && ! str_contains($n->message, 'ABCDE')
                && $n->actionPath === '/messages';
        });
    }

    // ---- Forgetting and removing -------------------------------------------

    public function test_a_forgotten_password_is_reset_with_a_code_from_the_email(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/lock", ['password' => '123456']);
        Notification::fake();

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/forgot')->assertOk();
        Notification::assertSentTo($this->me, ChatLockResetNotification::class);
        $code = MobileOtp::where('user_id', $this->me->id)->where('purpose', 'chat_lock_reset')->latest('id')->value('code');

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/reset', [
            'code' => $code, 'password' => '9999', 'password_confirmation' => '9999',
        ])->assertOk();

        // The new one works, the old one does not, and the lock stayed on.
        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '123456'])->assertStatus(422);
        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '9999'])->assertOk();
        $row = collect($this->list()->json('data'))->firstWhere('uuid', $this->chat->uuid);
        $this->assertTrue($row['is_locked']);
    }

    public function test_a_wrong_code_does_not_reset_anything(): void
    {
        $this->setPassword();
        Notification::fake();
        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/forgot')->assertOk();

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/reset', [
            'code' => '000000', 'password' => '9999', 'password_confirmation' => '9999',
        ])->assertStatus(422);

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '123456'])->assertOk();
    }

    public function test_removing_the_password_makes_every_chat_ordinary_again(): void
    {
        $this->setPassword();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$this->chat->uuid}/hide", ['password' => '123456']);

        $this->actingAs($this->me)->deleteJson('/api/v1/chat-lock', ['password' => '123456'])->assertOk();

        $this->assertFalse($this->actingAs($this->me)->getJson('/api/v1/chat-lock')->json('data.has_password'));
        $row = collect($this->list()->json('data'))->firstWhere('uuid', $this->chat->uuid);
        $this->assertFalse($row['is_hidden']);
        $this->thread()->assertOk();
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $this->setPassword();

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock', [
            'password' => '4321', 'password_confirmation' => '4321',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock', [
            'current_password' => '123456', 'password' => '4321', 'password_confirmation' => '4321',
        ])->assertOk();
    }

    public function test_guessing_is_held_down(): void
    {
        $this->setPassword();

        foreach (range(1, 6) as $i) {
            $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => 'guess' . $i]);
        }

        $this->actingAs($this->me)->postJson('/api/v1/chat-lock/unlock', ['password' => '123456'])->assertStatus(429);
    }

    // ---- The chat with yourself --------------------------------------------

    public function test_a_chat_with_yourself_can_be_written_in_but_not_called(): void
    {
        $self = $this->actingAs($this->me)->postJson('/api/v1/conversations/self')->assertCreated()->json('data');

        $this->assertTrue($self['is_self']);
        $this->assertSame('You', $self['name']);

        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$self['uuid']}/messages", ['body' => 'Call the bank at 11'])
            ->assertCreated();
        $this->actingAs($this->me)->postJson("/api/v1/conversations/{$self['uuid']}/calls", ['type' => 'audio'])
            ->assertStatus(422)->assertJsonPath('message', 'You cannot call yourself.');
    }

    public function test_it_is_one_chat_however_often_it_is_opened(): void
    {
        $first = $this->actingAs($this->me)->postJson('/api/v1/conversations/self')->json('data.uuid');
        $second = $this->actingAs($this->me)->postJson('/api/v1/conversations/self')->json('data.uuid');

        $this->assertSame($first, $second);
        // And it is never mistaken for the direct chat with somebody else.
        $this->assertNotSame($this->chat->uuid, $first);
        $this->assertSame($this->chat->id, Conversation::directBetween($this->me, $this->priya)->id);
    }
}
