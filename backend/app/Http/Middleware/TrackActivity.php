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
 * The work happens in terminate(), not handle(), for two reasons and the
 * first one is a bug this had. Global middleware runs BEFORE route middleware,
 * so in handle() the auth:sanctum on these routes has not run yet and
 * $request->user() is null for every request there is — presence recorded
 * nothing at all, silently, because a middleware that decides there is nobody
 * to record looks exactly like one that is working. terminate() runs after the
 * response, by which time the user is long since resolved. And as a bonus
 * nobody waits on it: the write happens after they have their answer.
 *
 * Throttled to one write a minute per user. Without it a busy client would
 * turn every poll into an UPDATE on the users table, which is a lot of writes
 * to record a fact that only needs to be right to within a minute or two.
 * Cache::add is the lock: it succeeds only if the key was absent, so
 * concurrent requests from the same person cannot all decide to write.
 *
 * Silent about failure — presence is a nicety, and by terminate() the person
 * already has their response, so there is nothing left to spoil.
 */
class TrackActivity
{
    /** How often a single user's row may be rewritten. */
    protected const EVERY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        if (! $user || $user->isGuest()) {
            return;
        }

        try {
            if (\Illuminate\Support\Facades\Cache::add("seen:{$user->id}", 1, self::EVERY_SECONDS)) {
                // Not touch(): that would drag updated_at along, which several
                // screens sort by and which means "this profile changed", not
                // "this person is awake".
                \App\Models\User::withoutGlobalScopes()
                    ->whereKey($user->id)
                    ->update(['last_active_at' => now()]);
            }
        } catch (\Throwable $e) {
            // See the note above.
        }
    }
}
