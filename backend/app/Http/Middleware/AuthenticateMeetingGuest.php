<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs in a meeting guest from their pass, for that meeting only.
 *
 * Guests are ordinary user rows hidden behind a global scope, so this is the
 * one place that asks for them back. Once resolved, everything downstream sees
 * a normal authenticated user and the meeting endpoints need no special cases.
 *
 * Three things are checked every request, not just at join: the pass exists,
 * it has not run out, and the meeting in the URL is the one it was minted for.
 * Without the last of those a pass for any meeting would work on all of them.
 */
class AuthenticateMeetingGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * The pass is re-checked from scratch on every request, with no
         * shortcut for an already-resolved user.
         *
         * An earlier version returned early when something had already put a
         * user on the request. That is a hole: whatever set it did not check
         * the expiry, so a guest whose half hour had run out could keep going
         * as long as the guard still held them. These routes carry no other
         * authentication, so the pass is the only thing that may speak here.
         */
        $raw = $request->bearerToken() ?: $request->header('X-Guest-Token');

        if (! $raw) {
            abort(401, 'This meeting needs a guest pass.');
        }

        $guest = User::withoutGlobalScope('withoutMeetingGuests')
            ->whereNotNull('guest_meeting_id')
            ->where('guest_token', hash('sha256', $raw))
            ->first();

        abort_if($guest === null, 401, 'That guest pass is not valid.');

        if ($guest->guestPassExpired()) {
            // Say so plainly: the guest has not done anything wrong, their
            // half hour is simply up, and the client shows that rather than a
            // generic failure.
            abort(response()->json([
                'message' => 'Your 30 minutes are up. Ask the host for a new link, or sign in.',
                'code' => 'guest_pass_expired',
            ], 401));
        }

        // The pass belongs to one meeting. Anything else is not theirs.
        $code = $request->route('meeting');
        $code = is_object($code) ? $code->code : $code;
        abort_unless(
            $code !== null && $guest->guest_meeting_id === \App\Models\Meeting::where('code', $code)->value('id'),
            403,
            'That guest pass is not for this meeting.',
        );

        auth()->setUser($guest);
        $request->setUserResolver(fn () => $guest);

        return $next($request);
    }
}
