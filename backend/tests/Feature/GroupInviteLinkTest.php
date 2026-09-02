<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\GroupJoinRequest;
use App\Models\User;
use App\Notifications\SocialNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A group you can be pointed at, rather than typed into.
 *
 * Two shapes: a link that admits, and a link that asks. The second is the
 * default, because a link is a thing people forward.
 */
class GroupInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $outsider;
    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->person();
        $this->outsider = $this->person();

        $this->group = Group::create([
            'owner_id' => $this->owner->id,
            'name' => 'Sunday Football',
            'type' => 'team',
        ]);
        $this->group->members()->attach($this->owner->id, ['role' => 'owner']);
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    private function enable(string $mode = 'request'): string
    {
        $this->actingAs($this->owner)
            ->putJson("/api/v1/groups/{$this->group->uuid}/invite", ['enabled' => true, 'mode' => $mode])
            ->assertOk();

        return $this->group->fresh()->invite_token;
    }

    public function test_a_group_has_no_link_until_one_is_asked_for(): void
    {
        $body = $this->actingAs($this->owner)
            ->getJson("/api/v1/groups/{$this->group->uuid}/invite")
            ->assertOk()->json('data');

        $this->assertFalse($body['enabled']);
        $this->assertNull($body['url']);

        // Asking is the safer of the two shapes, so it is the default.
        $this->assertSame('request', $body['mode']);
    }

    public function test_only_a_manager_may_see_or_change_the_link(): void
    {
        $this->actingAs($this->outsider)
            ->getJson("/api/v1/groups/{$this->group->uuid}/invite")->assertForbidden();

        $this->actingAs($this->outsider)
            ->putJson("/api/v1/groups/{$this->group->uuid}/invite", ['enabled' => true])->assertForbidden();
    }

    public function test_an_open_link_admits_whoever_follows_it(): void
    {
        Notification::fake();
        $token = $this->enable('open');

        $this->actingAs($this->outsider)
            ->postJson("/api/v1/join-group/{$token}")
            ->assertCreated()
            ->assertJsonPath('data.status', 'member');

        $this->assertTrue($this->group->members()->where('users.id', $this->outsider->id)->exists());
        $this->assertSame(0, GroupJoinRequest::count());
    }

    public function test_a_request_link_puts_them_in_the_queue_instead(): void
    {
        Notification::fake();
        $token = $this->enable('request');

        $this->actingAs($this->outsider)
            ->postJson("/api/v1/join-group/{$token}")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertFalse($this->group->members()->where('users.id', $this->outsider->id)->exists());
        $this->assertSame(1, GroupJoinRequest::where('status', 'pending')->count());
    }

    public function test_tapping_twice_is_not_asking_twice(): void
    {
        Notification::fake();
        $token = $this->enable('request');

        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();
        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertOk();

        $this->assertSame(1, GroupJoinRequest::count());
    }

    public function test_an_admin_can_admit_somebody_waiting(): void
    {
        Notification::fake();
        $token = $this->enable('request');
        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        $waiting = $this->actingAs($this->owner)
            ->getJson("/api/v1/groups/{$this->group->uuid}/join-requests")
            ->assertOk()->json('data');
        $this->assertCount(1, $waiting);

        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/join-requests/{$waiting[0]['uuid']}",
            ['action' => 'approve'],
        )->assertOk();

        $this->assertTrue($this->group->members()->where('users.id', $this->outsider->id)->exists());
    }

    public function test_a_declined_request_leaves_them_out(): void
    {
        Notification::fake();
        $token = $this->enable('request');
        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        $uuid = GroupJoinRequest::first()->uuid;
        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/join-requests/{$uuid}",
            ['action' => 'decline'],
        )->assertOk();

        $this->assertFalse($this->group->members()->where('users.id', $this->outsider->id)->exists());
        $this->assertSame('declined', GroupJoinRequest::first()->status);
    }

    /** No in March is not no forever. */
    public function test_somebody_declined_may_ask_again(): void
    {
        Notification::fake();
        $token = $this->enable('request');
        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        $uuid = GroupJoinRequest::first()->uuid;
        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/join-requests/{$uuid}",
            ['action' => 'decline'],
        )->assertOk();

        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        $this->assertSame(1, GroupJoinRequest::count());
        $this->assertSame('pending', GroupJoinRequest::first()->status);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        Notification::fake();
        $token = $this->enable('request');
        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        $uuid = GroupJoinRequest::first()->uuid;
        $url = "/api/v1/groups/{$this->group->uuid}/join-requests/{$uuid}";

        $this->actingAs($this->owner)->postJson($url, ['action' => 'approve'])->assertOk();
        $this->actingAs($this->owner)->postJson($url, ['action' => 'decline'])->assertStatus(409);
    }

    public function test_turning_the_link_off_stops_it_resolving(): void
    {
        Notification::fake();
        $token = $this->enable('open');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/groups/{$this->group->uuid}/invite", ['enabled' => false])
            ->assertOk();

        $this->actingAs($this->outsider)->getJson("/api/v1/join-group/{$token}")->assertNotFound();
        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertNotFound();
    }

    /** Rotating is the only honest way to take back a URL already forwarded. */
    public function test_rotating_breaks_the_old_link(): void
    {
        Notification::fake();
        $old = $this->enable('open');

        $this->actingAs($this->owner)
            ->postJson("/api/v1/groups/{$this->group->uuid}/invite/rotate")->assertOk();

        $new = $this->group->fresh()->invite_token;
        $this->assertNotSame($old, $new);

        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$old}")->assertNotFound();
        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$new}")->assertCreated();
    }

    public function test_the_preview_says_what_following_the_link_would_do(): void
    {
        Notification::fake();
        $token = $this->enable('request');

        $body = $this->actingAs($this->outsider)
            ->getJson("/api/v1/join-group/{$token}")->assertOk()->json('data');

        $this->assertSame('Sunday Football', $body['name']);
        $this->assertSame('request', $body['mode']);
        $this->assertFalse($body['already_member']);
        $this->assertFalse($body['already_requested']);

        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        $after = $this->actingAs($this->outsider)
            ->getJson("/api/v1/join-group/{$token}")->assertOk()->json('data');
        $this->assertTrue($after['already_requested']);
    }

    public function test_a_member_following_the_link_is_told_they_are_already_in(): void
    {
        Notification::fake();
        $token = $this->enable('open');

        $this->actingAs($this->owner)->postJson("/api/v1/join-group/{$token}")
            ->assertOk()->assertJsonPath('data.status', 'member');

        $this->assertSame(1, $this->group->members()->count());
    }

    public function test_the_managers_hear_about_a_request(): void
    {
        Notification::fake();
        $token = $this->enable('request');

        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        Notification::assertSentTo($this->owner, SocialNotification::class);
    }

    public function test_somebody_admitted_can_see_the_group_chat(): void
    {
        Notification::fake();
        $token = $this->enable('open');

        // The chat exists before they arrive, as it would for a group that
        // has been talking for a month.
        $conversation = Conversation::create(['type' => 'group', 'group_id' => $this->group->id]);
        $conversation->members()->sync([$this->owner->id]);

        $this->actingAs($this->outsider)->postJson("/api/v1/join-group/{$token}")->assertCreated();

        $this->assertTrue(
            $conversation->fresh()->members()->where('users.id', $this->outsider->id)->exists(),
        );
    }
}
