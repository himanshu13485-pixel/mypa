<?php

namespace App\Notifications;

use App\Models\Call;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Notification;

/**
 * Stop ringing — the call is no longer answerable.
 *
 * A ring is delivered by push precisely because the app may be closed, and a
 * closed app hears nothing on the websocket. So the websocket 'end' signal
 * that CallController sends reaches an open tab and nobody else, and the
 * notification it should have cleared stays exactly where it is.
 *
 * On Android that is not merely untidy. The ringing notification carries
 * FLAG_INSISTENT, which loops the ringtone until something dismisses it, and
 * nothing did: the caller hung up and the callee's phone went on ringing until
 * the 45-second timeout ran out on its own. On the web it is worse — the
 * notification is posted with requireInteraction and no timeout at all, so it
 * sat there until somebody clicked it, however many hours later.
 *
 * This is the message that was missing. Deliberately the same transports, the
 * same tag and the same collapse topic as the ring, so it lands on the very
 * notification it is cancelling rather than beside it.
 */
class CallOverNotification extends Notification
{
    public function __construct(
        public Call $call,
        /** missed = nobody took it; handled = answered or declined elsewhere. */
        public string $reason = 'missed',
    ) {
    }

    /**
     * Not queued, and not stored.
     *
     * Not queued because a cancellation that arrives after the ring has timed
     * out has missed its entire purpose — this has to go out in the same
     * request that ended the call.
     *
     * Not 'database' because the bell already learns about missed calls from
     * MessageController's own notification; a second row saying the same thing
     * would be noise.
     */
    public function via(object $notifiable): array
    {
        $via = [];

        if ($notifiable->pushSubscriptions()->exists()) {
            $via[] = WebPushChannel::class;
        }
        if ($notifiable->fcmTokens()->exists()) {
            $via[] = \App\Notifications\Channels\FcmChannel::class;
        }

        return $via;
    }

    /**
     * As urgent as the ring, and no longer-lived.
     *
     * The same collapse topic, so a cancellation queued behind a ring for a
     * device that was offline replaces it rather than arriving after it — the
     * device comes back and gets "call over", not a ring for a dead call
     * followed by its cancellation.
     */
    public function pushOptions(): array
    {
        return [
            'TTL' => 45,
            'urgency' => 'high',
            'topic' => 'call-' . substr(hash('sha256', $this->call->uuid), 0, 24),
        ];
    }

    public function toPush(object $notifiable): array
    {
        return [
            // The same tag the ring used, so the device is being handed the
            // identity of the notification to clear.
            'tag' => 'call-' . $this->call->uuid,
            'kind' => 'call_cancel',
            'call_uuid' => $this->call->uuid,
            'reason' => $this->reason,
            'caller_name' => $this->call->caller?->name,
            // Only read if the receiver decides to leave something behind in
            // its place; a handled call leaves nothing.
            'title' => 'Missed call',
            'body' => $this->call->caller?->name
                ? $this->call->caller->name . ' called'
                : 'You missed a call',
            'url' => '/calls',
        ];
    }
}
