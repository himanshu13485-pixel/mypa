<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs in a meeting guest if they present a pass, and otherwise does nothing.
 *
 * Unlike AuthenticateMeetingGuest this never rejects: it runs in front of
 * auth:sanctum on shared endpoints — channel authorisation, chiefly — where an
 * ordinary signed-in user must still pass through untouched. A guest gets
 * resolved here so Sanctum finds somebody already authenticated; anyone else
 * carries on to Sanctum as before.
 *
 * The expiry is still enforced, so a lapsed pass cannot hold a realtime
 * channel open after its 30 minutes are up. What this cannot check is which
 * meeting the request is for — these endpoints carry no meeting in the URL —
 * so the scoping is left to the channel rule itself, which only lets a guest
 * onto their own channel.
 */
class ResolveMeetingGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return $next($request);
        }

        $raw = $request->bearerToken() ?: $request->header('X-Guest-Token');

        if ($raw) {
            $guest = User::withoutGlobalScope('withoutMeetingGuests')
                ->whereNotNull('guest_meeting_id')
                ->where('guest_token', hash('sha256', $raw))
                ->first();

            if ($guest && ! $guest->guestPassExpired()) {
                auth()->setUser($guest);
                $request->setUserResolver(fn () => $guest);
            }
        }

        return $next($request);
    }
}
