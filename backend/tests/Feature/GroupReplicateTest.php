<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The same group again, with the same people or some of them.
 *
 * A team that ran one project runs the next one too, and rebuilding that
 * membership by hand is the work that stops people making the second group.
 */
class GroupReplicateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Group $group;
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->owner = $this->person();
        $this->group = Group::create([
            'owner_id' => $this->owner->id,
            'name' => 'Q3 Launch',
            'type' => 'team',
            'description' => 'Everything for the launch',
            'only_admins_post' => true,
        ]);
        $this->group->members()->attach($this->owner->id, ['role' => 'owner']);

        foreach (['admin', 'member', 'viewer'] as $role) {
            $person = $this->person();
            $this->group->members()->attach($person->id, ['role' => $role]);
            $this->members[$role] = $person;
        }
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    public function test_a_copy_brings_everybody_and_the_settings(): void
    {
        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/replicate",
            ['name' => 'Q4 Launch'],
        )->assertCreated();

        $copy = Group::where('name', 'Q4 Launch')->firstOrFail();

        $this->assertSame(4, $copy->members()->count());
        $this->assertSame('team', $copy->type);
        $this->assertSame('Everything for the launch', $copy->description);
        $this->assertTrue((bool) $copy->only_admins_post);

        // Roles come across.
        $this->assertSame('admin', $copy->roleOf($this->members['admin']));
        $this->assertSame('viewer', $copy->roleOf($this->members['viewer']));
    }

    public function test_a_subset_brings_only_those_chosen(): void
    {
        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/replicate",
            ['name' => 'Just the two of us', 'member_uuids' => [$this->members['member']->uuid]],
        )->assertCreated();

        $copy = Group::where('name', 'Just the two of us')->firstOrFail();

        // Them, and whoever pressed the button.
        $this->assertSame(2, $copy->members()->count());
        $this->assertNotNull($copy->roleOf($this->members['member']));
        $this->assertNull($copy->roleOf($this->members['admin']));
    }

    public function test_an_empty_list_makes_a_group_of_one(): void
    {
        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/replicate",
            ['name' => 'Just me', 'member_uuids' => []],
        )->assertCreated();

        $this->assertSame(1, Group::where('name', 'Just me')->firstOrFail()->members()->count());
    }

    /** Whoever copies it owns it, whatever they were in the original. */
    public function test_the_person_copying_owns_the_copy(): void
    {
        $them = $this->members['member'];

        $this->actingAs($them)->postJson(
            "/api/v1/groups/{$this->group->uuid}/replicate",
            ['name' => 'My own version'],
        )->assertCreated();

        $copy = Group::where('name', 'My own version')->firstOrFail();

        $this->assertSame($them->id, $copy->owner_id);
        $this->assertSame('owner', $copy->roleOf($them));

        // Two owners is not a thing this model has.
        $this->assertSame('admin', $copy->roleOf($this->owner));
    }

    public function test_somebody_outside_the_group_cannot_copy_it(): void
    {
        $this->actingAs($this->person())->postJson(
            "/api/v1/groups/{$this->group->uuid}/replicate",
            ['name' => 'Not mine to take'],
        )->assertForbidden();
    }

    /** A copy is a fresh room with the same people, not a copy of the talk. */
    public function test_a_copy_carries_no_messages(): void
    {
        $conversation = \App\Models\Conversation::create(['type' => 'group', 'group_id' => $this->group->id]);
        $conversation->messages()->create([
            'user_id' => $this->owner->id, 'type' => 'text', 'body' => 'said in the first group',
        ]);

        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/replicate",
            ['name' => 'Fresh start'],
        )->assertCreated();

        $copy = Group::where('name', 'Fresh start')->firstOrFail();
        $copyChat = \App\Models\Conversation::where('group_id', $copy->id)->first();

        $this->assertSame(0, $copyChat?->messages()->count() ?? 0);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/groups/{$this->group->uuid}/replicate", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /** A uuid that was never in the group is dropped, not obeyed. */
    public function test_a_stranger_cannot_be_smuggled_in_through_the_list(): void
    {
        $stranger = $this->person();

        $this->actingAs($this->owner)->postJson(
            "/api/v1/groups/{$this->group->uuid}/replicate",
            ['name' => 'Nice try', 'member_uuids' => [$stranger->uuid, $this->members['member']->uuid]],
        )->assertCreated();

        $copy = Group::where('name', 'Nice try')->firstOrFail();

        $this->assertNull($copy->roleOf($stranger));
        $this->assertSame(2, $copy->members()->count());
    }
}
