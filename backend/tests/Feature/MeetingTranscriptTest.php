<?php

namespace Tests\Feature;

use App\Events\MeetingSignal;
use App\Models\Meeting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What was said in a meeting, kept.
 *
 * It used to be a signal and nothing else - typed, broadcast to whoever was
 * in the room at that second, and gone. The link somebody pasted lived only
 * in the tabs that were open at the time.
 */
class MeetingTranscriptTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;
    protected User $alice;
    protected User $bob;
    protected User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Event::fake([MeetingSignal::class]);

        $this->host = User::factory()->create(['name' => 'Host']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->stranger = User::factory()->create(['name' => 'Nobody']);
    }

    /** A room with the three of them in it. */
    private function room(bool $screen = false): array
    {
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', [
            'title' => $screen ? 'My screen share' : 'Site review',
            'requires_approval' => false,
            'is_screen' => $screen,
        ])->assertCreated()->json('data');

        foreach ([$this->host, $this->alice, $this->bob] as $who) {
            $this->actingAs($who)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        }

        return $meeting;
    }

    public function test_the_conversation_outlives_the_call(): void
    {
        $meeting = $this->room();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'The client bank details are on grapout.com/pay.',
        ])->assertOk();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'Noted, thanks.',
        ])->assertOk();

        // Read back during the call, in the order it was said.
        $live = $this->actingAs($this->bob)->getJson("/api/v1/meetings/{$meeting['code']}/chat")->assertOk();
        $this->assertSame(
            ['The client bank details are on grapout.com/pay.', 'Noted, thanks.'],
            collect($live->json('data'))->pluck('message')->all(),
        );
        $this->assertSame('Alice', $live->json('data.0.from'));
        $this->assertFalse($live->json('data.0.is_mine'));

        // The meeting ends, and the words are still there.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/end")->assertOk();

        $after = $this->actingAs($this->bob)->getJson("/api/v1/meetings/{$meeting['code']}/chat")->assertOk();
        $after->assertJsonCount(2, 'data');
        $after->assertJsonPath('meeting.status', 'ended');
        $this->assertNotNull($after->json('meeting.ended_at'));
    }

    public function test_a_private_line_stays_between_the_two_on_it(): void
    {
        $meeting = $this->room();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'Can we talk after this?',
            'to_uuid' => $this->bob->uuid,
        ])->assertOk();

        // Both ends of it read it, and it says it was private.
        foreach ([$this->alice, $this->bob] as $who) {
            $seen = $this->actingAs($who)->getJson("/api/v1/meetings/{$meeting['code']}/chat")->assertOk();
            $seen->assertJsonCount(1, 'data');
            $seen->assertJsonPath('data.0.private', true);
        }

        // The host runs the meeting; that is not a right to read the whispers.
        $this->actingAs($this->host)->getJson("/api/v1/meetings/{$meeting['code']}/chat")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_shared_file_reads_as_part_of_the_conversation(): void
    {
        Storage::fake('local');
        $meeting = $this->room();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/chat-file", [
            'file' => UploadedFile::fake()->create('agenda.pdf', 20, 'application/pdf'),
        ])->assertOk();

        $transcript = $this->actingAs($this->bob)->getJson("/api/v1/meetings/{$meeting['code']}/chat")->assertOk();
        $transcript->assertJsonCount(1, 'data');
        $transcript->assertJsonPath('data.0.file.name', 'agenda.pdf');
        $this->assertNull($transcript->json('data.0.message'));
    }

    public function test_a_screen_share_keeps_its_chat_the_same_way(): void
    {
        $meeting = $this->room(screen: true);

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'Look at the third row.',
        ])->assertOk();

        $this->actingAs($this->alice)->getJson("/api/v1/meetings/{$meeting['code']}/chat")
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Look at the third row.')
            ->assertJsonPath('meeting.is_screen', true);
    }

    public function test_only_somebody_who_was_in_the_room_can_read_it(): void
    {
        $meeting = $this->room();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'Between us.',
        ])->assertOk();

        $this->actingAs($this->stranger)->getJson("/api/v1/meetings/{$meeting['code']}/chat")
            ->assertForbidden();
    }

    public function test_deleting_the_meeting_takes_its_chat_with_it(): void
    {
        $meeting = $this->room();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'Something said.',
        ])->assertOk();

        $this->assertSame(1, \App\Models\MeetingMessage::count());

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/end")->assertOk();
        $this->actingAs($this->host)->deleteJson("/api/v1/meetings/{$meeting['code']}")->assertOk();

        // A meeting nobody can open should not leave its conversation behind.
        $this->assertSame(0, Meeting::where('code', $meeting['code'])->count());
        $this->assertSame(0, \App\Models\MeetingMessage::count());
    }
}
