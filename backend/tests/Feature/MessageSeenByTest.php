<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who has seen a message in a group.
 *
 * The tick on a bubble is one bit for the whole room and only goes double
 * once the last person has read, so a message seen by nine of ten looks
 * exactly like one nobody opened. That is a fine glance and a useless
 * answer - the question actually asked is "has Himanshu seen it".
 */
class MessageSeenByTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reader;

    private User $absentee;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->owner = $this->person('GrapOut CRM', 'grapoutcrm');
        $this->reader = $this->person('Himanshu Sachdeva', 'himanshu');
        $this->absentee = $this->person('Harsh', 'harsh');

        $group = Group::create(['owner_id' => $this->owner->id, 'name' => 'GrapOut Test', 'type' => 'team']);
        $group->members()->attach($this->owner->id, ['role' => 'owner']);
        $group->members()->attach($this->reader->id, ['role' => 'member']);
        $group->members()->attach($this->absentee->id, ['role' => 'member']);

        $uuid = $this->actingAs($this->owner)
            ->getJson("/api/v1/groups/{$group->uuid}/conversation")
            ->assertOk()->json('data.uuid');
        $this->conversation = Conversation::where('uuid', $uuid)->firstOrFail();
    }

    private function person(string $name, string $username): User
    {
        $user = User::factory()->create([
            'name' => $name, 'username' => $username, 'email' => $username . '@netvork.test',
            'email_verified_at' => now(),
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    private function say(string $body): string
    {
        return $this->actingAs($this->owner)
            ->postJson("/api/v1/conversations/{$this->conversation->uuid}/messages", ['body' => $body])
            ->assertCreated()->json('data.uuid');
    }

    /** What the app posts when somebody opens the thread. */
    private function read(User $who): void
    {
        $this->actingAs($who)
            ->postJson("/api/v1/conversations/{$this->conversation->uuid}/read")
            ->assertOk();
    }

    private function seenBy(string $messageUuid, ?User $who = null)
    {
        return $this->actingAs($who ?? $this->owner)
            ->getJson("/api/v1/conversations/{$this->conversation->uuid}/messages/{$messageUuid}/seen");
    }

    public function test_it_says_who_has_read_it_and_who_has_not(): void
    {
        $uuid = $this->say('Payroll goes out on Friday.');
        $this->read($this->reader);

        $data = $this->seenBy($uuid)->assertOk()->json('data');

        $this->assertSame(['Himanshu Sachdeva'], array_column($data['seen'], 'name'));
        $this->assertSame(['Harsh'], array_column($data['pending'], 'name'));
        $this->assertNotNull($data['seen'][0]['at']);
    }

    public function test_reading_the_thread_before_the_message_does_not_count(): void
    {
        // Read, and then - minutes later - something new is said. Having
        // been in the room earlier is not having seen what came after.
        $this->read($this->reader);
        $this->travel(5)->minutes();
        $uuid = $this->say('And one more thing.');

        $data = $this->seenBy($uuid)->assertOk()->json('data');

        $this->assertSame([], $data['seen']);
        $this->assertCount(2, $data['pending']);
    }

    public function test_nine_of_ten_no_longer_looks_like_nobody(): void
    {
        // The whole point: the tick is still single because one person has
        // not read, and the list still names the one who has.
        $uuid = $this->say('Anyone free at 4?');
        $this->read($this->reader);

        $messages = $this->actingAs($this->owner)
            ->getJson("/api/v1/conversations/{$this->conversation->uuid}/messages")
            ->assertOk()->json('data');
        $mine = collect($messages)->firstWhere('uuid', $uuid);

        $this->assertFalse((bool) $mine['read_by_others']);
        $this->assertCount(1, $this->seenBy($uuid)->assertOk()->json('data.seen'));
    }

    public function test_it_is_ones_own_messages_one_may_ask_about(): void
    {
        $uuid = $this->say('Mine.');

        // Whether somebody has read what SOMEBODY ELSE wrote is not the
        // reader's business to look up.
        $this->seenBy($uuid, $this->reader)->assertForbidden();
    }

    public function test_somebody_outside_the_room_is_refused(): void
    {
        $uuid = $this->say('Ours.');
        $stranger = $this->person('Stranger', 'stranger');

        $this->seenBy($uuid, $stranger)->assertForbidden();
    }
}
