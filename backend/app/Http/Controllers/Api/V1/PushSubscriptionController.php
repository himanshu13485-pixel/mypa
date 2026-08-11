<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /** VAPID public key the browser needs to subscribe. */
    public function publicKey(): JsonResponse
    {
        return response()->json(['data' => ['key' => config('mypa.webpush.public_key')]]);
    }

    /** Register (or refresh) this browser's push subscription. */
    /**
     * The browser moved a subscription; point the existing row at the new one.
     *
     * Unauthenticated on purpose, and it has to be: a service worker holds no
     * session, and pushsubscriptionchange can fire when no tab is open to lend
     * it one. What stands in for a session is the old endpoint — a long opaque
     * URL that only the browser holding that subscription and this server ever
     * knew. Anyone who can produce it already had the subscription.
     *
     * Deliberately narrow: it moves a row that already exists and creates
     * nothing. An unknown old endpoint is answered exactly like a known one so
     * the route cannot be used to work out which endpoints are live.
     */
    public function rotate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'old_endpoint' => ['required', 'string', 'max:2000'],
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        PushSubscription::where('endpoint_hash', hash('sha256', $data['old_endpoint']))
            ->update([
                'endpoint' => $data['endpoint'],
                'endpoint_hash' => hash('sha256', $data['endpoint']),
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
            ]);

        return response()->json(['message' => 'ok']);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['sometimes', 'string', 'max:32'],
        ]);

        $request->user()->pushSubscriptions()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            ],
        );

        return response()->json(['message' => 'Push notifications enabled on this device.']);
    }

    /** Remove this browser's subscription (user turned push off). */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        $request->user()->pushSubscriptions()
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['message' => 'Push notifications disabled on this device.']);
    }
}
