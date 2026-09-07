<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\DialRequested;
use App\Http\Controllers\Controller;
use App\Services\FcmService;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Click a number on a laptop, dial it on your own phone.
 *
 * The salesperson already has a phone with a paid-up SIM, and calls on it are
 * free. Every cloud-telephony option costs money per minute to replace a call
 * that costs nothing — so this replaces only the part that is actually
 * missing: the number getting from the screen they are working at to the
 * handset in their pocket, without being read out and typed in.
 *
 * It sends to the caller's OWN devices and takes no target user, which is the
 * only thing keeping it from being a way to make somebody else's phone ring a
 * number of your choosing.
 *
 * Two transports, because they fail in different places. The websocket is
 * instant and needs the app open; FCM survives a backgrounded app and arrives
 * as something to tap. Both are sent every time: whichever gets there first
 * opens the dialler, and the second is then a notification the person
 * ignores — which is a great deal better than a dial that quietly never
 * happened.
 */
class DialController extends Controller
{
    public function store(Request $request, FcmService $fcm): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $number = $this->dialable($data['number']);

        if ($number === null) {
            return response()->json([
                'message' => 'That does not look like a number a phone could ring.',
            ], 422);
        }

        Realtime::send(new DialRequested($me->uuid, $number, $data['label'] ?? null));

        /*
         * And the same thing as a notification, for a phone whose app is not
         * open. The url is what the shell's tap handler already navigates to,
         * so this needs nothing new on the device — the same path that has
         * been carrying answered calls carries this.
         */
        $fcm->sendToUser($me, [
            'title' => 'Call ' . ($data['label'] ?? $number),
            'body' => 'Tap to dial ' . $number,
            'tag' => 'dial',
            'url' => '/dial?number=' . urlencode($number),
            'kind' => 'dial',
            'channel' => 'calls2',
        ], ['TTL' => 60, 'urgency' => 'high']);

        return response()->json(['data' => ['number' => $number]]);
    }

    /**
     * The digits, and a leading + where there was one.
     *
     * Kept identical to the browser's telHref on purpose: the number shown as
     * a tappable link and the number pushed to a phone must be the same
     * number, or the two ways of ringing the same lead reach different
     * people. Anything under six digits is an extension or a placeholder like
     * "-" rather than something to dial.
     */
    protected function dialable(string $raw): ?string
    {
        $trimmed = trim($raw);
        $international = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D/', '', $trimmed) ?? '';

        if (strlen($digits) < 6) {
            return null;
        }

        return ($international ? '+' : '') . $digits;
    }
}
