<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hiding your presence costs you the view of everybody else's.
 *
 * A setting that takes without giving is not a privacy setting, it is an
 * advantage: you would see who is at their desk while they could not see you,
 * which is exactly the arrangement the person switching it off was trying to
 * prevent for themselves.
 */
class PresenceReciprocityTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private User $them;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->person();
        $this->them = $this->person();

        Connection::create([
            'requester_id' => $this->me->id,
            'addressee_id' => $this->them->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    private function person(): User
    {
        $user = User::factory()->create(['last_active_at' => now()]);
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    private function hide(User $user, string $key = 'online_status_visibility'): void
    {
        $user->settings->update(['privacy' => array_merge($user->settings->privacy ?? [], [$key => 'nobody'])]);
        $user->load('settings');
    }

    public function test_two_connections_see_each_other_by_default(): void
    {
        $this->assertSame('online', $this->them->presenceFor($this->me));
        $this->assertSame('online', $this->me->presenceFor($this->them));
    }

    public function test_hiding_yours_hides_you_from_them(): void
    {
        $this->hide($this->them);

        $this->assertNull($this->them->presenceFor($this->me));
    }

    /** The half that was missing. */
    public function test_hiding_yours_also_stops_you_seeing_theirs(): void
    {
        $this->hide($this->me);

        $this->assertNull($this->them->presenceFor($this->me->fresh()));
    }

    public function test_you_can_always_see_your_own(): void
    {
        $this->hide($this->me);

        $this->assertSame('online', $this->me->fresh()->presenceFor($this->me->fresh()));
    }

    /** 'connections' is a narrower question, not a refusal to answer. */
    public function test_connections_only_is_not_hiding(): void
    {
        $this->me->settings->update(['privacy' => ['online_status_visibility' => 'connections']]);

        $this->assertSame('online', $this->them->presenceFor($this->me->fresh()));
    }

    public function test_last_seen_is_reciprocal_on_its_own_setting(): void
    {
        // Hiding last seen costs the view of last seen...
        $this->hide($this->me, 'last_seen_visibility');
        $this->assertFalse($this->them->presenceVisibleTo($this->me->fresh(), 'last_seen_visibility'));

        // ...but not the view of who is online, which is a separate switch.
        $this->assertTrue($this->them->presenceVisibleTo($this->me->fresh(), 'online_status_visibility'));
    }

    public function test_the_chat_list_says_so_too(): void
    {
        $conversation = \App\Models\Conversation::create(['type' => 'direct']);
        $conversation->members()->attach([$this->me->id, $this->them->id]);

        $before = $this->actingAs($this->me)->getJson('/api/v1/conversations')
            ->assertOk()->json('data.0.other_user');
        $this->assertTrue($before['online_visible']);

        $this->hide($this->me);

        $after = $this->actingAs($this->me->fresh())->getJson('/api/v1/conversations')
            ->assertOk()->json('data.0.other_user');
        $this->assertFalse($after['online_visible']);
        $this->assertNull($after['presence']);
    }

    /**
     * And the live broadcast obeys it.
     *
     * The read paths already answer null, but a broadcast arriving anyway
     * would light their screen up with dots the API refuses to confirm - the
     * setting would look like it worked until the page was reloaded.
     */
    public function test_somebody_hiding_theirs_is_not_broadcast_to(): void
    {
        $this->assertContains($this->me->uuid, $this->them->presenceAudience());

        $this->hide($this->me);

        $this->assertNotContains($this->me->uuid, $this->them->fresh()->presenceAudience());
    }
}
