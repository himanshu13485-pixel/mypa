<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers a notification to every browser/device the user has subscribed
 * for push. Notifications must implement toPush($notifiable): array with
 * at least ['title' => ..., 'body' => ...]; 'url' is opened on tap.
 *
 * Push is best-effort: delivery failures are logged, never thrown, and
 * permanently-gone subscriptions (410/404) are pruned.
 */
class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }

        $subscriptions = $notifiable->pushSubscriptions ?? collect();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $publicKey = config('mypa.webpush.public_key');
        $privateKey = config('mypa.webpush.private_key');
        if (! $publicKey || ! $privateKey) {
            return;
        }

        $payload = json_encode($notification->toPush($notifiable));

        /*
         * How urgently, and for how long.
         *
         * Both were left at the library's defaults, and both defaults are wrong
         * for this app. Normal urgency is the one that hurts: Android holds a
         * normal-priority push until the device's next maintenance window, so a
         * phone awake in someone's hand rings and an identical phone asleep in
         * a pocket does not — which is exactly the "it works for some of us"
         * this was reported as. High urgency wakes the device now.
         *
         * The default TTL is four weeks. For anything time-bound that is worse
         * than not delivering: a call that rings the following morning is not a
         * late notification, it is a wrong one.
         */
        $options = method_exists($notification, 'pushOptions')
            ? $notification->pushOptions()
            : ['TTL' => 3600, 'urgency' => 'normal'];

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('mypa.webpush.subject', config('mypa.frontend_url')),
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);

            foreach ($subscriptions as $sub) {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding,
                ]), $payload, $options);
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
                    PushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Web push delivery failed: ' . $e->getMessage());
        }
    }
}
