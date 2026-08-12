<?php

namespace App\Notifications;

use App\Models\Call;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Notifications\Notification;

/**
 * A ringing phone, for a phone that is not looking at the app.
 *
 * Every other signal in a call travels over the websocket, which only exists
 * while a tab is open. Close the app and an incoming call arrived nowhere at
 * all — the caller heard ringing and the callee heard silence.
 *
 * Push only, and never queued: a call rings for about thirty seconds, so a
 * notification that waits for a queue worker is a notification about a call
 * that has already been missed. It is also deliberately not written to the
 * database — the Calls list already records missed calls, and a bell badge
 * for a call that stopped ringing a minute ago is noise.
 */
class IncomingCallNotification extends Notification
{
    public function __construct(
        public Call $call,
        public string $callerName,
        public bool $isGroup = false,
        public ?string $groupName = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        $prefs = $notifiable->settings?->notification_preferences ?? [];

        // A call is the one thing worth waking someone for, but "no push"
        // still means no push.
        if (! ($prefs['push'] ?? true)) {
            return [];
        }

        // Browsers and the Android app are different transports for the same
        // ring: web push cannot reach the app (its WebView has no Push API),
        // and FCM cannot reach a browser. Each is skipped for a user with no
        // devices of its kind.
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
     * A ring is the most urgent thing this app sends, and the shortest-lived.
     *
     * High urgency because Android holds a normal-priority push until the
     * device's next maintenance window — which is why a phone in someone's
     * hand rang and the same phone asleep in a pocket did not.
     *
     * Forty-five seconds because a ring is worthless after that. The library's
     * default is four weeks; delivering this one late is worse than not
     * delivering it, since answering a call that stopped ringing yesterday is
     * not something anyone can do.
     *
     * The topic collapses re-rings for the same call into one notification
     * rather than a row of them.
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
        $video = $this->call->type === 'video';

        return [
            'title' => $this->isGroup && $this->groupName
                ? $this->groupName
                : $this->callerName,
            'body' => $this->isGroup
                ? "{$this->callerName} started a " . ($video ? 'video call' : 'call')
                : ($video ? 'Incoming video call' : 'Incoming call'),
            /*
             * One tag per call, so a re-ring replaces the notification rather
             * than stacking a second one, and `renotify` makes the device
             * alert again rather than swapping it silently.
             */
            'tag' => 'call-' . $this->call->uuid,
            'renotify' => true,
            // Stays on screen until it is answered or dismissed, which is the
            // closest a web notification gets to a ringing phone.
            'requireInteraction' => true,
            'kind' => 'call',
            'call_uuid' => $this->call->uuid,
            'url' => '/calls?join=' . $this->call->uuid,
            'actions' => [
                ['action' => 'answer', 'title' => 'Answer'],
                ['action' => 'decline', 'title' => 'Decline'],
            ],
        ];
    }
}
