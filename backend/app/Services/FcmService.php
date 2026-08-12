<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Rings the Android app, through Firebase Cloud Messaging.
 *
 * Web push cannot reach it: the shell is a WebView, and Android's WebView has
 * no Push API — that is a Chrome feature, not a WebView one. FCM is the one
 * channel Android itself keeps open for every app, and a high-priority FCM
 * message wakes a sleeping phone properly, which web push on this project
 * never reliably managed even in the browser.
 *
 * Hand-rolled OAuth rather than google/apiclient, in the same spirit as the
 * LiveKit tokens: authenticating to FCM is one RS256-signed JWT swapped for a
 * bearer token, and a dependency tree the size of the Google SDK is a lot to
 * carry for two HTTP calls.
 *
 * Credentials are a service-account JSON file on disk, named by
 * FCM_CREDENTIALS in .env, chmod 600, never in git — it can mint messages to
 * every user, which makes it exactly as sensitive as the LiveKit secret.
 */
class FcmService
{
    public function configured(): bool
    {
        $path = config('mypa.fcm.credentials');

        return $path && is_readable($path);
    }

    /** Send one push payload (the toPush() shape) to every device a user has. */
    public function sendToUser(User $user, array $payload, array $options = []): void
    {
        if (! $this->configured()) {
            return;
        }

        $tokens = $user->fcmTokens;
        if ($tokens->isEmpty()) {
            return;
        }

        try {
            $creds = $this->credentials();
            $bearer = $this->accessToken($creds);
        } catch (\Throwable $e) {
            Log::warning('fcm: could not authenticate: '.$e->getMessage());

            return;
        }

        foreach ($tokens as $token) {
            $this->sendToToken($creds['project_id'], $bearer, $token, $payload, $options);
        }
    }

    protected function sendToToken(string $project, string $bearer, FcmToken $token, array $payload, array $options): void
    {
        /*
         * A notification message on a named channel — NOT a data-only one.
         *
         * Data-only looks better on paper: the payload reaches the app's own
         * handler, which could ring however it liked. The catch is the word
         * "running" — a data message reaches JavaScript only while the app is
         * alive, and the whole reason this service exists is the phone whose
         * app is closed. A notification block is displayed by Android itself,
         * app dead or not, using the channel's sound and urgency. The app
         * creates the 'calls' channel at first launch at maximum importance;
         * the tap carries the data payload in, and the app opens on the call.
         *
         * The trade: no Answer/Decline buttons on the notification and no
         * 30-second ringtone — those need a native ConnectionService, which is
         * a later piece of work. Heads-up, loud, and tap-to-answer is what
         * this buys, on every phone, reliably.
         *
         * Every data value must be a string: FCM rejects nested values with a
         * 400 that names no field.
         */
        $data = collect($payload)
            ->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v))
            ->all();

        $message = [
            'token' => $token->token,
            'notification' => [
                'title' => (string) ($payload['title'] ?? 'Netvork'),
                'body' => (string) ($payload['body'] ?? ''),
            ],
            'data' => $data,
            'android' => [
                /*
                 * High priority is the entire point. Normal-priority FCM is
                 * batched to the device's next maintenance window — the exact
                 * behaviour that made web push ring the phone in your hand and
                 * not the one in your pocket.
                 */
                'priority' => ($options['urgency'] ?? null) === 'high' ? 'high' : 'normal',
                // Same idea as web push TTL: a ring delivered late is not a
                // late ring, it is a wrong one.
                'ttl' => (int) ($options['TTL'] ?? 3600).'s',
                'notification' => [
                    // calls2: channels are immutable once created on a device,
                    // and the first 'calls' shipped without its ringtone. The
                    // shell creates calls2 with the sound and retires the old
                    // channel; a message addressed to a channel a phone does
                    // not have is dropped silently, so these two names must
                    // move together.
                    'channel_id' => ($payload['kind'] ?? null) === 'call' ? 'calls2' : 'default',
                    // One notification per call, replaced on re-ring, exactly
                    // like the web push 'tag'.
                    'tag' => (string) ($payload['tag'] ?? ''),
                ],
            ],
        ];

        try {
            /*
             * Retries are not optional politeness here; they are the fix for
             * a measured pathology of this host. fcm.googleapis.com resolves
             * to several addresses and, from this box, some of Google's IPs
             * are reachable and some are blackholed — the same curl succeeded
             * in 130ms or hung for the full timeout depending on nothing but
             * which address the resolver dealt. Each retry opens a fresh
             * connection and re-rolls that draw; the short connect timeout
             * makes a dead address fail fast enough to matter. Three draws at
             * these odds has yet to lose.
             */
            $res = Http::withToken($bearer)
                ->connectTimeout(3)
                ->timeout(10)
                ->retry(2, 200, throw: false)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => $message,
                ]);

            if ($res->status() === 404 || str_contains($res->body(), 'UNREGISTERED')) {
                // The app was uninstalled, or the token rotated. Keeping the
                // row means paying a failed request on every future ring.
                $token->delete();
            } elseif ($res->failed()) {
                Log::warning('fcm: send failed', ['status' => $res->status(), 'body' => substr($res->body(), 0, 200)]);
            }
        } catch (\Throwable $e) {
            Log::warning('fcm: send failed: '.$e->getMessage());
        }
    }

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri: string} */
    protected function credentials(): array
    {
        $creds = json_decode((string) file_get_contents(config('mypa.fcm.credentials')), true);
        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (empty($creds[$key])) {
                throw new \RuntimeException("service account file is missing {$key}");
            }
        }

        return $creds + ['token_uri' => $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token'];
    }

    /**
     * A bearer token for the FCM API, cached most of its hour-long life.
     *
     * The exchange is the standard service-account flow: sign a JWT with the
     * account's own key, trade it for an access token. Cached under a hash of
     * the client email so rotating the service account cannot serve a stale
     * token from the old one.
     */
    protected function accessToken(array $creds): string
    {
        $cacheKey = 'fcm:token:'.substr(hash('sha256', $creds['client_email']), 0, 16);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($creds) {
            $now = time();
            $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');
            $body = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
                'iss' => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $creds['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ]);

            $ok = openssl_sign($body, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
            if (! $ok) {
                throw new \RuntimeException('could not sign with the service-account key');
            }
            $jwt = $body.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            // Same lottery, same tickets — see the note on the send call.
            $res = Http::asForm()->connectTimeout(3)->timeout(10)->retry(2, 200, throw: false)->post($creds['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            $token = $res->json('access_token');
            if (! $token) {
                throw new \RuntimeException('token exchange refused: '.substr($res->body(), 0, 200));
            }

            return $token;
        });
    }
}
