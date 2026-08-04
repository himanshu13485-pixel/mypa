<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Broadcasting that cannot take a request down with it.
 *
 * Call and meeting signals are ShouldBroadcastNow, so they go out over HTTP to
 * Reverb inside the request. If Reverb is unreachable that throws a
 * BroadcastException, and because the join handler broadcasts to every peer
 * already in the room, a websocket server that was merely *down* turned into
 * "could not join the meeting — server error". The participant row had been
 * written; only the notification failed.
 *
 * Liveness is not worth a failed request. Joining a meeting, ending a call and
 * telling someone you are typing all have a sensible meaning without it: the
 * state is saved, the room just does not update until Reverb is back. So a
 * transport failure is logged and swallowed.
 *
 * Genuine programming errors are NOT swallowed — only the broadcast layer's
 * own exception is caught, so a malformed event or a bad channel still
 * surfaces in tests and in development.
 */
class Realtime
{
    /** Broadcast, tolerating a broadcaster that is down. */
    public static function send(object $event): void
    {
        try {
            broadcast($event);
        } catch (\Illuminate\Broadcasting\BroadcastException $e) {
            static::note($event, $e);
        }
    }

    /** Same, for events that should skip the socket that caused them. */
    public static function toOthers(object $event): void
    {
        try {
            broadcast($event)->toOthers();
        } catch (\Illuminate\Broadcasting\BroadcastException $e) {
            static::note($event, $e);
        }
    }

    protected static function note(object $event, \Throwable $e): void
    {
        Log::warning('[realtime] broadcast dropped — the request carried on', [
            'event' => class_basename($event),
            'reason' => $e->getMessage(),
        ]);
    }
}
