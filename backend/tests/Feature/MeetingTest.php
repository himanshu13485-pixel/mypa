<?php

namespace Tests\Feature;

use App\Events\MeetingSignal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MeetingTest extends TestCase
{
    use RefreshDatabase;

    protected User $host;
    protected User $alice;
    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->host = User::factory()->create(['name' => 'Host']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
    }

    public function test_full_meeting_lifecycle(): void
    {
        Event::fake([MeetingSignal::class]);

        // Host creates a meeting and gets a shareable code.
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', [
            'title' => 'Site review', 'requires_approval' => false,
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $meeting['code']);

        // Anyone signed in with the code can look it up and join.
        $this->actingAs($this->alice)->getJson("/api/v1/meetings/{$meeting['code']}")->assertOk();
        $join = $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertEquals('active', $join->json('data.status'));
        $this->assertCount(0, $join->json('data.joined_peers'));

        // Host joins; sees Alice as an existing peer; Alice is notified.
        $join = $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertCount(1, $join->json('data.joined_peers'));
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'join' && $e->toUserUuid === $this->alice->uuid);

        // Targeted signalling relay.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/signal", [
            'signal' => 'offer',
            'payload' => ['sdp' => 'x', 'type' => 'offer'],
            'to_uuid' => $this->alice->uuid,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'offer' && $e->toUserUuid === $this->alice->uuid);

        // Non-participants cannot signal.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/signal", [
            'signal' => 'offer', 'payload' => ['sdp' => 'x'], 'to_uuid' => $this->alice->uuid,
        ])->assertForbidden();

        // Bob joins late (meeting already active) and meshes with both.
        $join = $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertCount(2, $join->json('data.joined_peers'));

        // Only the host can end for everyone.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/end")->assertForbidden();
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/end")->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'end');

        // An ended meeting cannot be re-joined.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertStatus(410);
    }

    public function test_display_name_per_meeting(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');

        // Join with a custom name; a later joiner sees it in joined_peers.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join", [
            'display_name' => 'Boss',
        ])->assertOk();
        $join = $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->assertEquals('Boss', $join->json('data.joined_peers.0.name'));

        // Rename mid-meeting broadcasts to the others.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/name", [
            'display_name' => 'Alice (Site A)',
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'rename' && $e->payload['name'] === 'Alice (Site A)');

        // Outsiders cannot rename.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/name", [
            'display_name' => 'Intruder',
        ])->assertForbidden();
    }

    public function test_reactions_broadcast_to_the_room(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/react", [
            'emoji' => 'thumbsup',
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'react'
            && $e->payload['emoji'] === 'thumbsup' && $e->toUserUuid === $this->host->uuid);

        // Unknown emoji rejected; outsiders rejected.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/react", [
            'emoji' => 'rocketship',
        ])->assertStatus(422);
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/react", [
            'emoji' => 'thumbsup',
        ])->assertForbidden();
    }

    public function test_screen_sessions_are_separate_from_meetings(): void
    {
        $screen = $this->actingAs($this->host)->postJson('/api/v1/meetings', [
            'is_screen' => true, 'title' => 'Screen share',
        ])->assertCreated()->json('data');
        $this->assertTrue($screen['is_screen']);
        $this->actingAs($this->host)->postJson('/api/v1/meetings', ['title' => 'Normal'])->assertCreated();

        // Meetings list excludes screen sessions and vice versa.
        $meetings = $this->actingAs($this->host)->getJson('/api/v1/meetings')->json('data');
        $screens = $this->actingAs($this->host)->getJson('/api/v1/meetings?screen=1')->json('data');
        $this->assertCount(1, $meetings);
        $this->assertEquals('Normal', $meetings[0]['title']);
        $this->assertCount(1, $screens);
        $this->assertEquals('Screen share', $screens[0]['title']);
    }

    public function test_meeting_ends_itself_when_everyone_leaves(): void
    {
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/leave")->assertOk();
        $this->assertEquals('active', \App\Models\Meeting::where('code', $meeting['code'])->first()->status);

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/leave")->assertOk();
        $this->assertEquals('ended', \App\Models\Meeting::where('code', $meeting['code'])->first()->status);
    }

    public function test_waiting_room_host_approval_flow(): void
    {
        Event::fake([MeetingSignal::class]);
        // Default meeting requires approval.
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', [])->json('data');
        $this->assertTrue($meeting['requires_approval']);

        // Host joins directly; Alice knocks and waits.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $wait = $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertStatus(202);
        $this->assertTrue($wait->json('data.waiting'));
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'knock' && $e->toUserUuid === $this->host->uuid);

        // Only the host can admit.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/admit", [
            'user_uuid' => $this->alice->uuid, 'allow' => true,
        ])->assertForbidden();

        // Host admits Alice -> she is notified and can now join for real.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/admit", [
            'user_uuid' => $this->alice->uuid, 'allow' => true,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'admitted' && $e->toUserUuid === $this->alice->uuid);
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        // Bob knocks and is denied.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertStatus(202);
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/admit", [
            'user_uuid' => $this->bob->uuid, 'allow' => false,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'denied' && $e->toUserUuid === $this->bob->uuid);

        // Host bypasses the waiting room -> Bob still waits? No: open access now.
        $this->actingAs($this->host)->putJson("/api/v1/meetings/{$meeting['code']}/approval", [
            'requires_approval' => false,
        ])->assertOk();
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
    }

    public function test_meeting_chat_everyone_and_private(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        foreach ([$this->host, $this->alice, $this->bob] as $u) {
            $this->actingAs($u)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        }

        // To everyone: both others receive it.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'Welcome all',
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat'
            && $e->payload['message'] === 'Welcome all' && ! $e->payload['private'] && $e->toUserUuid === $this->alice->uuid);
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat' && $e->toUserUuid === $this->bob->uuid);

        // Private: only Alice gets it, flagged private.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'just for you', 'to_uuid' => $this->alice->uuid,
        ])->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat'
            && $e->payload['message'] === 'just for you' && $e->payload['private'] && $e->toUserUuid === $this->alice->uuid);
        Event::assertNotDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat'
            && $e->payload['message'] === 'just for you' && $e->toUserUuid === $this->bob->uuid);

        // Non-participants cannot chat.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson("/api/v1/meetings/{$meeting['code']}/chat", [
            'message' => 'hi',
        ])->assertForbidden();
    }

    public function test_chat_file_share_and_participant_only_download(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        Event::fake([MeetingSignal::class]);
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $file = \Illuminate\Http\UploadedFile::fake()->create('site-plan.png', 100, 'image/png');
        $res = $this->actingAs($this->host)->post("/api/v1/meetings/{$meeting['code']}/chat-file", [
            'file' => $file,
        ])->assertOk();
        $uuid = $res->json('data.uuid');
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'chat'
            && ($e->payload['file']['name'] ?? null) === 'site-plan.png' && $e->toUserUuid === $this->alice->uuid);

        // Participant can download; outsider cannot.
        $this->actingAs($this->alice)->get("/api/v1/meetings/{$meeting['code']}/chat-file/{$uuid}")->assertOk();
        $this->actingAs($this->bob)->get("/api/v1/meetings/{$meeting['code']}/chat-file/{$uuid}")->assertForbidden();

        // Non-participants cannot share files either.
        $this->actingAs($this->bob)->post("/api/v1/meetings/{$meeting['code']}/chat-file", [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 10),
        ])->assertForbidden();
    }

    public function test_meetings_index_lists_hosted_and_attended(): void
    {
        $meeting = $this->actingAs($this->host)->postJson('/api/v1/meetings', ['title' => 'A', 'requires_approval' => false])->json('data');
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();

        $this->assertCount(1, $this->actingAs($this->host)->getJson('/api/v1/meetings')->json('data'));
        $this->assertCount(1, $this->actingAs($this->alice)->getJson('/api/v1/meetings')->json('data'));
        $this->assertCount(0, $this->actingAs($this->bob)->getJson('/api/v1/meetings')->json('data'));
    }

    // --- Presence -----------------------------------------------------------

    public function test_heartbeat_returns_the_live_roster(): void
    {
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice, ['display_name' => 'Ali']);

        $beat = $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->assertOk();
        $this->assertEquals('active', $beat->json('data.status'));
        $this->assertCount(2, $beat->json('data.participants'));

        $ali = collect($beat->json('data.participants'))->firstWhere('uuid', $this->alice->uuid);
        $this->assertEquals('Ali', $ali['name']);
        $this->assertEquals('participant', $ali['role']);

        // Somebody who is not in the room has nothing to beat about.
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->assertStatus(409);
    }

    public function test_reaper_drops_silent_participants_and_ends_the_meeting(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice);

        $model = \App\Models\Meeting::where('code', $meeting)->first();
        $stale = now()->subSeconds(\App\Models\Meeting::PRESENCE_TIMEOUT_SECONDS + 10);

        // Alice's browser vanished — no leave call, just silence.
        \Illuminate\Support\Facades\DB::table('meeting_participants')
            ->where('meeting_id', $model->id)->where('user_id', $this->alice->id)
            ->update(['last_seen_at' => $stale]);
        \Illuminate\Support\Facades\DB::table('meeting_participants')
            ->where('meeting_id', $model->id)->where('user_id', $this->host->id)
            ->update(['last_seen_at' => now()]);

        $this->artisan('mypa:reap-meetings')->assertSuccessful();

        // Alice is out, the host is told, and the meeting is still running.
        $this->assertEquals('left', \Illuminate\Support\Facades\DB::table('meeting_participants')
            ->where('meeting_id', $model->id)->where('user_id', $this->alice->id)->value('status'));
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'leave'
            && $e->toUserUuid === $this->host->uuid && $e->fromUserUuid === $this->alice->uuid);
        $this->assertEquals('active', $model->fresh()->status);

        // Now the host goes quiet too: the room is empty, so the meeting ends.
        \Illuminate\Support\Facades\DB::table('meeting_participants')
            ->where('meeting_id', $model->id)->where('user_id', $this->host->id)
            ->update(['last_seen_at' => $stale]);
        $model->update(['started_at' => $stale]);

        $this->artisan('mypa:reap-meetings')->assertSuccessful();
        $this->assertEquals('ended', $model->fresh()->status);
        $this->assertNotNull($model->fresh()->ended_at);
    }

    public function test_reaper_leaves_a_brand_new_empty_meeting_alone(): void
    {
        // Created and joined seconds ago: nobody has had time to miss a beat.
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/leave")->assertOk();

        // That leave already ended it, so re-open a fresh active one instead.
        $model = \App\Models\Meeting::where('code', $meeting)->first();
        $model->update(['status' => 'active', 'ended_at' => null, 'started_at' => now()]);

        $this->artisan('mypa:reap-meetings')->assertSuccessful();
        $this->assertEquals('active', $model->fresh()->status);
    }

    // --- Host controls ------------------------------------------------------

    public function test_host_can_mute_stop_video_and_remove_a_participant(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice);
        $this->join($meeting, $this->bob);

        $this->hostAction($meeting, $this->host, 'mute', $this->alice->uuid)->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'host-mute' && $e->toUserUuid === $this->alice->uuid);

        $this->hostAction($meeting, $this->host, 'stop_video', $this->alice->uuid)->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'host-stop-video' && $e->toUserUuid === $this->alice->uuid);

        // The roster reflects both.
        $roster = collect($this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->json('data.participants'));
        $ali = $roster->firstWhere('uuid', $this->alice->uuid);
        $this->assertFalse($ali['mic_on']);
        $this->assertFalse($ali['cam_on']);

        // Mute all leaves the host alone unmuted.
        $this->hostAction($meeting, $this->host, 'mute_all')->assertOk();
        $roster = collect($this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->json('data.participants'));
        $this->assertFalse($roster->firstWhere('uuid', $this->bob->uuid)['mic_on']);
        $this->assertTrue($roster->firstWhere('uuid', $this->host->uuid)['mic_on']);

        // Removing Bob drops him from the room and tells everyone.
        $this->hostAction($meeting, $this->host, 'remove', $this->bob->uuid)->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'removed' && $e->toUserUuid === $this->bob->uuid);
        $this->actingAs($this->bob)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->assertStatus(409);

        // Ordinary participants have none of these powers.
        $this->hostAction($meeting, $this->alice, 'mute', $this->host->uuid)->assertForbidden();
    }

    public function test_cohost_shares_the_controls_but_not_the_host_seat(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice);
        $this->join($meeting, $this->bob);

        $this->hostAction($meeting, $this->host, 'promote', $this->alice->uuid)->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'role' && $e->payload['role'] === 'cohost');

        // Alice can now moderate Bob...
        $this->hostAction($meeting, $this->alice, 'mute', $this->bob->uuid)->assertOk();
        // ...but not the host, and she cannot hand the meeting over.
        $this->hostAction($meeting, $this->alice, 'mute', $this->host->uuid)->assertForbidden();
        $this->hostAction($meeting, $this->alice, 'transfer_host', $this->bob->uuid)->assertForbidden();

        // Only the host ends it for everyone.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting}/end")->assertForbidden();
    }

    public function test_transferring_host_swaps_the_two_roles(): void
    {
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice);

        $this->hostAction($meeting, $this->host, 'transfer_host', $this->alice->uuid)->assertOk();

        $this->assertEquals('host', $this->actingAs($this->alice)->getJson("/api/v1/meetings/{$meeting}")->json('data.my_role'));
        $this->assertEquals('cohost', $this->actingAs($this->host)->getJson("/api/v1/meetings/{$meeting}")->json('data.my_role'));

        // The new host can end it; the old one cannot.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/end")->assertForbidden();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting}/end")->assertOk();
    }

    public function test_host_leaving_hands_the_room_to_whoever_is_left(): void
    {
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice);
        $this->join($meeting, $this->bob);
        $this->hostAction($meeting, $this->host, 'promote', $this->bob->uuid)->assertOk();

        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/leave")->assertOk();

        // The co-host inherits it, not the earlier joiner.
        $this->assertEquals('host', $this->actingAs($this->bob)->getJson("/api/v1/meetings/{$meeting}")->json('data.my_role'));
        $this->assertEquals('active', \App\Models\Meeting::where('code', $meeting)->first()->status);
    }

    public function test_locking_a_meeting_shuts_the_door(): void
    {
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);

        $this->hostAction($meeting, $this->host, 'lock')->assertOk();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting}/join")->assertStatus(423);

        // The host is never locked out of their own room (refresh must work).
        $this->join($meeting, $this->host);

        $this->hostAction($meeting, $this->host, 'unlock')->assertOk();
        $this->join($meeting, $this->alice);
    }

    public function test_passcode_is_required_and_hidden_from_participants(): void
    {
        $created = $this->actingAs($this->host)->postJson('/api/v1/meetings', [
            'requires_approval' => false, 'passcode' => 'let5in',
        ])->assertCreated()->json('data');
        $code = $created['code'];

        $this->assertTrue($created['has_passcode']);
        $this->assertEquals('let5in', $created['passcode']);

        // Wrong or missing passcode is turned away; the right one gets in.
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$code}/join")->assertForbidden();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$code}/join", ['passcode' => 'nope'])->assertForbidden();
        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$code}/join", ['passcode' => 'let5in'])->assertOk();

        // Participants never see the passcode itself.
        $this->assertNull($this->actingAs($this->alice)->getJson("/api/v1/meetings/{$code}")->json('data.passcode'));
        $this->assertTrue($this->actingAs($this->alice)->getJson("/api/v1/meetings/{$code}")->json('data.has_passcode'));

        // The host never has to type it.
        $this->actingAs($this->host)->postJson("/api/v1/meetings/{$code}/join")->assertOk();
    }

    public function test_spotlight_is_broadcast_and_survives_in_the_meeting_state(): void
    {
        Event::fake([MeetingSignal::class]);
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice);

        $this->hostAction($meeting, $this->host, 'spotlight', $this->alice->uuid)->assertOk();
        Event::assertDispatched(MeetingSignal::class, fn ($e) => $e->signalType === 'spotlight'
            && $e->payload['uuid'] === $this->alice->uuid);
        $this->assertEquals(
            $this->alice->uuid,
            $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->json('data.spotlight_uuid'),
        );

        $this->hostAction($meeting, $this->host, 'clear_spotlight')->assertOk();
        $this->assertNull($this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->json('data.spotlight_uuid'));
    }

    public function test_raised_hand_is_remembered_on_the_roster(): void
    {
        $meeting = $this->openMeeting();
        $this->join($meeting, $this->host);
        $this->join($meeting, $this->alice);

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting}/react", ['emoji' => 'hand'])->assertOk();
        $roster = collect($this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->json('data.participants'));
        $this->assertTrue($roster->firstWhere('uuid', $this->alice->uuid)['hand_raised']);

        $this->actingAs($this->alice)->postJson("/api/v1/meetings/{$meeting}/react", ['emoji' => 'hand_down'])->assertOk();
        $roster = collect($this->actingAs($this->host)->postJson("/api/v1/meetings/{$meeting}/heartbeat")->json('data.participants'));
        $this->assertFalse($roster->firstWhere('uuid', $this->alice->uuid)['hand_raised']);
    }

    // --- helpers ------------------------------------------------------------

    /** An open-access meeting; returns its join code. */
    protected function openMeeting(array $extra = []): string
    {
        return $this->actingAs($this->host)
            ->postJson('/api/v1/meetings', ['requires_approval' => false] + $extra)
            ->assertCreated()
            ->json('data.code');
    }

    protected function join(string $code, User $as, array $payload = []): void
    {
        $this->actingAs($as)->postJson("/api/v1/meetings/{$code}/join", $payload)->assertOk();
    }

    protected function hostAction(string $code, User $as, string $action, ?string $target = null)
    {
        return $this->actingAs($as)->postJson("/api/v1/meetings/{$code}/host-action", array_filter([
            'action' => $action,
            'user_uuid' => $target,
        ]));
    }
}
