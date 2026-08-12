<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\FcmToken;
use App\Models\User;
use App\Notifications\IncomingCallNotification;
use App\Services\AppIdService;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ringing the Android app.
 *
 * The app is a WebView, and Android's WebView has no Push API — so web push,
 * which is how browsers ring, cannot reach it at all. FCM is the channel
 * Android keeps open for every app, and high priority is the property that
 * matters: normal-priority FCM is batched to the device's next maintenance
 * window, which is the exact "phone in a hand rings, phone in a pocket does
 * not" that plagued web push.
 */
class FcmDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected string $credentialsFile;

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
        }

        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        /*
         * A real (throwaway) RSA key, because the service authenticates by
         * actually signing a JWT before any HTTP happens — a fake key would
         * fail at openssl_sign, before Http::fake ever saw a request.
         */
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->credentialsFile = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($this->credentialsFile, json_encode([
            'project_id' => 'netvork-test',
            'client_email' => 'ring@netvork-test.iam.gserviceaccount.com',
            'private_key' => $pem,
        ]));
        config(['mypa.fcm.credentials' => $this->credentialsFile]);
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsFile);
        parent::tearDown();
    }

    private function fakeFcm(array $sendResponse = [], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'bearer-for-test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($sendResponse, $status),
        ]);
    }

    private function ring(User $to): void
    {
        $conversation = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $to->appId->app_id])
            ->json('data.uuid');
        $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->assertCreated();
    }

    public function test_a_call_rings_the_app_at_high_priority(): void
    {
        $this->fakeFcm();
        FcmToken::create(['user_id' => $this->bob->id, 'token' => str_repeat('t', 64), 'platform' => 'android']);

        $this->ring($this->bob);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com/v1/projects/netvork-test/messages:send')) {
                return false;
            }
            $message = $request['message'];

            /*
             * High priority is the entire point of the exercise, and 45s is
             * the ring's own lifetime — a ring delivered after that is not a
             * late ring, it is a wrong one.
             */
            return $message['android']['priority'] === 'high'
                && $message['android']['ttl'] === '45s'
                && $message['token'] === str_repeat('t', 64)
                /*
                 * The notification block is what rings a dead app: Android
                 * displays it itself, on the 'calls' channel the app created
                 * at maximum importance. A data-only message would reach
                 * JavaScript only while the app was running — the one case
                 * that never needed FCM.
                 */
                && isset($message['notification']['title'])
                && $message['android']['notification']['channel_id'] === 'calls'
                && $message['data']['kind'] === 'call';
        });
    }

    public function test_every_data_value_is_a_string(): void
    {
        // FCM rejects nested values with a 400 that names no field; the
        // payload's arrays (actions) must arrive JSON-encoded.
        $this->fakeFcm();
        FcmToken::create(['user_id' => $this->bob->id, 'token' => str_repeat('u', 64)]);

        $this->ring($this->bob);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'messages:send')) {
                return false;
            }
            foreach ($request['message']['data'] as $value) {
                if (! is_string($value)) {
                    return false;
                }
            }

            return true;
        });
    }

    public function test_a_server_without_credentials_sends_nothing(): void
    {
        config(['mypa.fcm.credentials' => null]);
        Http::fake();
        FcmToken::create(['user_id' => $this->bob->id, 'token' => str_repeat('v', 64)]);

        $this->ring($this->bob);

        Http::assertNothingSent();
    }

    public function test_an_uninstalled_app_is_forgotten(): void
    {
        // UNREGISTERED means the app is gone; keeping the row means paying a
        // failed request on every future ring, for ever.
        $this->fakeFcm(['error' => ['status' => 'UNREGISTERED']], 404);
        $token = FcmToken::create(['user_id' => $this->bob->id, 'token' => str_repeat('w', 64)]);

        $this->ring($this->bob);

        $this->assertDatabaseMissing('fcm_tokens', ['id' => $token->id]);
    }

    public function test_a_phone_changing_hands_rings_for_its_new_owner_only(): void
    {
        // The same physical device signing into a second account must ring for
        // that account — not for both, which would make every shared or resold
        // phone a wiretap on its previous owner.
        $token = str_repeat('x', 64);

        $this->actingAs($this->alice)
            ->postJson('/api/v1/push/fcm-token', ['token' => $token])
            ->assertOk();
        $this->actingAs($this->bob)
            ->postJson('/api/v1/push/fcm-token', ['token' => $token])
            ->assertOk();

        $this->assertSame(1, FcmToken::where('token', $token)->count());
        $this->assertSame($this->bob->id, FcmToken::where('token', $token)->first()->user_id);
    }

    public function test_signing_out_stops_the_ringing(): void
    {
        $token = str_repeat('y', 64);
        $this->actingAs($this->bob)->postJson('/api/v1/push/fcm-token', ['token' => $token])->assertOk();

        $this->actingAs($this->bob)
            ->deleteJson('/api/v1/push/fcm-token', ['token' => $token])
            ->assertOk();

        $this->assertDatabaseMissing('fcm_tokens', ['token' => $token]);
    }
}
