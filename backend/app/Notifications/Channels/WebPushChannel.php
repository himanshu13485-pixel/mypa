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
                ]), $payload);
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
