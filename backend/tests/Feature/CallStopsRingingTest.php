<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Connection;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\CallOverNotification;
use App\Services\AppIdService;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A ring that stops when the call does.
 *
 * The ring is delivered by push because the app may be closed — and a closed
 * app hears nothing on the websocket, which is the only thing that was ever
 * told the call had ended. So the notification stayed: on Android looping its
 * ringtone under FLAG_INSISTENT until a 45-second timeout, on the web sitting
 * under requireInteraction with no timeout at all, still offering Answer and
 * Decline for a call that was long over.
 *
 * Four moments end a ring, and every one of them needs to say so.
 */
class CallStopsRingingTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
            // A device to ring, so the notification has somewhere to go.
            PushSubscription::create([
                'user_id' => $user->id,
                'endpoint' => 'https://push.example/' . $user->id,
                'endpoint_hash' => hash('sha256', 'https://push.example/' . $user->id),
                'public_key' => 'key',
                'auth_token' => 'auth',
                'content_encoding' => 'aes128gcm',
            ]);
        }

        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    private function aliceRingsBob(): Call
    {
        $conversation = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->assertCreated()->json('data.uuid');

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'video'])
            ->assertCreated()->json('data.uuid');

        return Call::where('uuid', $uuid)->firstOrFail();
    }

    public function test_the_caller_hanging_up_stops_the_callees_phone(): void
    {
        $call = $this->aliceRingsBob();
        Notification::fake();

        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$call->uuid}/end")->assertOk();

        // The reported bug: without this the notification stayed, ringing, for
        // the full 45 seconds of its own timeout.
        Notification::assertSentTo($this->bob, CallOverNotification::class,
            function (CallOverNotification $note) use ($call) {
                $this->assertSame($call->uuid, $note->call->uuid);
                $this->assertSame('missed', $note->reason);

                return true;
            });

        // Not to the person who hung up — their notification is already gone.
        Notification::assertNotSentTo($this->alice, CallOverNotification::class);
    }

    public function test_the_cancel_names_the_notification_it_is_clearing(): void
    {
        $call = $this->aliceRingsBob();
        $payload = (new CallOverNotification($call->load('caller'), 'missed'))->toPush($this->bob);

        // The same tag the ring used, so the device is handed the identity of
        // the thing to take down rather than drawing a second notification.
        $this->assertSame('call-' . $call->uuid, $payload['tag']);
        $this->assertSame('call_cancel', $payload['kind']);
        $this->assertSame($call->uuid, $payload['call_uuid']);
    }

    public function test_the_cancel_travels_as_urgently_as_the_ring(): void
    {
        $call = $this->aliceRingsBob();
        $ring = (new \App\Notifications\IncomingCallNotification($call, 'Alice', false, null))->pushOptions();
        $cancel = (new CallOverNotification($call))->pushOptions();

        // A cancellation held in a maintenance window while the ring went out
        // at high priority is the bug with extra steps.
        $this->assertSame('high', $cancel['urgency']);
        $this->assertSame($ring['TTL'], $cancel['TTL']);
        // The same collapse topic, so a device that was offline gets "call
        // over" rather than a dead ring followed by its cancellation.
        $this->assertSame($ring['topic'], $cancel['topic']);
    }

    public function test_answering_on_one_device_silences_the_others(): void
    {
        $call = $this->aliceRingsBob();
        Notification::fake();

        $this->actingAs($this->bob)
            ->postJson("/api/v1/calls/{$call->uuid}/respond", ['action' => 'accept'])
            ->assertOk();

        // 'handled', not 'missed': Bob is on the call, so nothing should be
        // left behind on his laptop claiming he missed it.
        Notification::assertSentTo($this->bob, CallOverNotification::class,
            fn (CallOverNotification $note) => $note->reason === 'handled');
    }

    public function test_declining_on_one_device_silences_the_others(): void
    {
        $call = $this->aliceRingsBob();
        Notification::fake();

        $this->actingAs($this->bob)
            ->postJson("/api/v1/calls/{$call->uuid}/respond", ['action' => 'decline'])
            ->assertOk();

        Notification::assertSentTo($this->bob, CallOverNotification::class,
            fn (CallOverNotification $note) => $note->reason === 'handled');
    }

    public function test_declining_from_the_phones_own_button_silences_the_rest(): void
    {
        $call = $this->aliceRingsBob();
        Notification::fake();

        // The Android notification's Decline runs native code with no session,
        // so it uses the signed URL baked into the ring payload.
        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'push.calls.decline',
            now()->addSeconds(60),
            ['call' => $call->uuid, 'user' => $this->bob->uuid],
        );

        $this->postJson($url)->assertOk();

        Notification::assertSentTo($this->bob, CallOverNotification::class,
            fn (CallOverNotification $note) => $note->reason === 'handled');
    }

    public function test_a_call_that_was_answered_and_then_ended_does_not_chase_anyone(): void
    {
        $call = $this->aliceRingsBob();
        $this->actingAs($this->bob)
            ->postJson("/api/v1/calls/{$call->uuid}/respond", ['action' => 'accept'])
            ->assertOk();

        Notification::fake();
        $this->actingAs($this->alice)->postJson("/api/v1/calls/{$call->uuid}/end")->assertOk();

        // Bob joined, so nothing of his is ringing. Sending him a cancel would
        // be a push for no reason.
        Notification::assertNotSentTo($this->bob, CallOverNotification::class);
    }

    public function test_it_is_not_stored_in_the_bell(): void
    {
        $call = $this->aliceRingsBob();

        // The missed call already reaches the bell through the conversation's
        // own notification; a second row would say the same thing twice.
        $this->assertNotContains('database', (new CallOverNotification($call))->via($this->bob));
    }

    public function test_a_person_with_no_devices_is_not_pushed_at(): void
    {
        $call = $this->aliceRingsBob();
        $this->bob->pushSubscriptions()->delete();

        $this->assertSame([], (new CallOverNotification($call))->via($this->bob->fresh()));
    }
}
