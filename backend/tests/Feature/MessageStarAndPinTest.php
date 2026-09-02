<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageStar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Two different ways of marking a message.
 *
 * Starring is private — the address you were sent, the number you will need
 * again — and nobody else learns you kept it. Pinning is public: the decision
 * this conversation keeps referring back to, held up for everyone.
 */
class MessageStarAndPinTest extends TestCase
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

    private function message(string $body = 'the address is 12 Green Lane'): Message
    {
        return $this->chat->messages()->create([
            'user_id' => $this->them->id, 'type' => 'text', 'body' => $body,
        ]);
    }

    private function star(Message $m, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me)
            ->postJson("/api/v1/conversations/{$this->chat->uuid}/messages/{$m->uuid}/star");
    }

    private function pin(Message $m, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me)
            ->postJson("/api/v1/conversations/{$this->chat->uuid}/messages/{$m->uuid}/pin");
    }

    public function test_starring_is_a_toggle(): void
    {
        $m = $this->message();

        $this->star($m)->assertOk()->assertJsonPath('data.starred', true);
        $this->assertSame(1, MessageStar::count());

        $this->star($m)->assertOk()->assertJsonPath('data.starred', false);
        $this->assertSame(0, MessageStar::count());
    }

    /** Starring twice is starring, however confused the client is. */
    public function test_starring_never_makes_two_rows(): void
    {
        $m = $this->message();

        $this->star($m)->assertOk();
        $this->star($m)->assertOk();
        $this->star($m)->assertOk();

        $this->assertSame(1, MessageStar::count());
    }

    /**
     * The private half.
     *
     * A star that the other side could see would stop being useful the moment
     * anybody noticed.
     */
    public function test_a_star_is_invisible_to_everybody_else(): void
    {
        $m = $this->message();
        $this->star($m, $this->me)->assertOk();

        $mine = $this->actingAs($this->me)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")
            ->assertOk()->json('data.0.is_starred');

        $theirs = $this->actingAs($this->them)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/messages")
            ->assertOk()->json('data.0.is_starred');

        $this->assertTrue($mine);
        $this->assertFalse($theirs);
    }

    public function test_pinning_is_a_toggle_and_is_shared(): void
    {
        $m = $this->message();

        $this->pin($m)->assertOk()->assertJsonPath('data.pinned', true);
        $this->assertNotNull($m->fresh()->pinned_at);
        $this->assertSame($this->me->id, $m->fresh()->pinned_by_id);

        // The other side sees it, which is the whole point of a pin.
        $this->actingAs($this->them)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/pinned")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->pin($m)->assertOk()->assertJsonPath('data.pinned', false);
        $this->assertNull($m->fresh()->pinned_at);
    }

    public function test_the_pinned_list_is_newest_first(): void
    {
        $first = $this->message('one');
        $second = $this->message('two');

        $this->pin($first)->assertOk();
        $this->pin($second)->assertOk();

        $bodies = collect($this->actingAs($this->me)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/pinned")
            ->assertOk()->json('data'))->pluck('body')->all();

        $this->assertSame(['two', 'one'], $bodies);
    }

    /**
     * A pinned list of forty is an unpinned list.
     *
     * The oldest makes way rather than the request being refused: whoever is
     * pinning has decided this one matters now.
     */
    public function test_the_oldest_pin_makes_way_at_the_cap(): void
    {
        $messages = collect(range(1, 6))->map(fn ($i) => $this->message("message {$i}"));

        foreach ($messages as $m) {
            $this->pin($m)->assertOk()->assertJsonPath('data.pinned', true);
        }

        $pinned = $this->actingAs($this->me)
            ->getJson("/api/v1/conversations/{$this->chat->uuid}/pinned")->assertOk()->json('data');

        $this->assertCount(5, $pinned);

        // The first one pinned is the one that went.
        $this->assertNull($messages->first()->fresh()->pinned_at);
        $this->assertNotNull($messages->last()->fresh()->pinned_at);
    }

    public function test_somebody_outside_the_conversation_can_do_neither(): void
    {
        $stranger = $this->person();
        $m = $this->message();

        $this->star($m, $stranger)->assertForbidden();
        $this->pin($m, $stranger)->assertForbidden();
    }

    /** Whoever may post may pin; in an announcement group that is the admins. */
    public function test_an_announcement_group_only_lets_its_admins_pin(): void
    {
        $group = Group::create([
            'owner_id' => $this->them->id, 'name' => 'Notices', 'type' => 'team', 'only_admins_post' => true,
        ]);
        $group->members()->attach($this->them->id, ['role' => 'owner']);
        $group->members()->attach($this->me->id, ['role' => 'member']);

        $chat = Conversation::create(['type' => 'group', 'group_id' => $group->id]);
        $chat->members()->attach([$this->me->id, $this->them->id]);

        $m = $chat->messages()->create(['user_id' => $this->them->id, 'type' => 'text', 'body' => 'notice']);

        $this->actingAs($this->me)
            ->postJson("/api/v1/conversations/{$chat->uuid}/messages/{$m->uuid}/pin")
            ->assertForbidden();

        $this->actingAs($this->them)
            ->postJson("/api/v1/conversations/{$chat->uuid}/messages/{$m->uuid}/pin")
            ->assertOk();
    }

    /** Both show up on the profile, which is where people go looking. */
    public function test_the_profile_carries_what_was_kept_and_what_was_pinned(): void
    {
        $starred = $this->message('starred one');
        $pinned = $this->message('pinned one');

        $this->star($starred)->assertOk();
        $this->pin($pinned)->assertOk();

        $shared = $this->actingAs($this->me)
            ->getJson("/api/v1/people/{$this->them->uuid}")
            ->assertOk()->json('data.shared');

        $this->assertSame($this->chat->uuid, $shared['conversation_uuid']);
        $this->assertSame(['starred one'], collect($shared['starred_messages'])->pluck('body')->all());
        $this->assertSame(['pinned one'], collect($shared['pinned_messages'])->pluck('body')->all());
    }

    /** Their stars are not mine to see, on the profile or anywhere else. */
    public function test_the_profile_shows_only_my_own_stars(): void
    {
        $theirs = $this->message('they kept this');
        $this->star($theirs, $this->them)->assertOk();

        $shared = $this->actingAs($this->me)
            ->getJson("/api/v1/people/{$this->them->uuid}")
            ->assertOk()->json('data.shared');

        $this->assertSame([], $shared['starred_messages']);
    }

    public function test_the_profile_lists_groups_you_are_both_in(): void
    {
        $together = Group::create(['owner_id' => $this->me->id, 'name' => 'Sunday Football', 'type' => 'team']);
        $together->members()->attach([$this->me->id, $this->them->id]);

        $alone = Group::create(['owner_id' => $this->me->id, 'name' => 'Just me', 'type' => 'other']);
        $alone->members()->attach($this->me->id);

        $groups = $this->actingAs($this->me)
            ->getJson("/api/v1/people/{$this->them->uuid}")
            ->assertOk()->json('data.shared.groups');

        $this->assertSame(['Sunday Football'], collect($groups)->pluck('name')->all());
    }

    /** My own profile has no "what we share": there is no other party. */
    public function test_my_own_profile_has_no_shared_section(): void
    {
        $this->actingAs($this->me)
            ->getJson("/api/v1/people/{$this->me->uuid}")
            ->assertOk()->assertJsonPath('data.shared', null);
    }
}
