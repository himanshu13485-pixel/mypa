<?php

namespace App\Notifications\Channels;

use App\Services\FcmService;
use Illuminate\Notifications\Notification;

/**
 * The native-app twin of WebPushChannel.
 *
 * Reads the very same toPush() payload and pushOptions() a notification
 * already declares for web push, so a notification that rings browsers rings
 * the Android app with no knowledge that two transports exist. Best-effort
 * like its twin: a ring must never take the request that caused it down.
 */
class FcmChannel
{
    public function __construct(protected FcmService $fcm)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $options = method_exists($notification, 'pushOptions')
            ? $notification->pushOptions()
            : ['TTL' => 3600, 'urgency' => 'normal'];

        $this->fcm->sendToUser($notifiable, $notification->toPush($notifiable), $options);
    }
}
