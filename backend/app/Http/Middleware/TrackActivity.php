<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notices that somebody is using the app.
 *
 * Presence needs no channel of its own: an open app already talks to this
 * server constantly — heartbeats, polling, every screen it opens — so the
 * requests themselves are the signal. A person is online exactly when their
 * app is asking for things.
 *
 * Throttled to one write a minute per user. Without it a busy client would
 * turn every poll into an UPDATE on the users table, which is a lot of writes
 * to record a fact that only needs to be right to within a minute or two.
 * Cache::add is the lock: it succeeds only if the key was absent, so
 * concurrent requests from the same person cannot all decide to write.
 *
 * Deliberately silent about failure — presence is a nicety, and a cache blip
 * must never cost somebody the request they actually made.
 */
class TrackActivity
{
    /** How often a single user's row may be rewritten. */
    protected const EVERY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            try {
                if (\Illuminate\Support\Facades\Cache::add("seen:{$user->id}", 1, self::EVERY_SECONDS)) {
                    // Not touch(): that would drag updated_at along, which
                    // several screens sort by and which means "this profile
                    // changed", not "this person is awake".
                    \App\Models\User::whereKey($user->id)->update(['last_active_at' => now()]);
                }
            } catch (\Throwable $e) {
                // See the note above.
            }
        }

        return $next($request);
    }
}
