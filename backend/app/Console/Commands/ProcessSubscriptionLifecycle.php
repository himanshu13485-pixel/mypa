<?php

namespace App\Console\Commands;

use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Notifications\SubscriptionLifecycleNotification;
use Illuminate\Console\Command;

class ProcessSubscriptionLifecycle extends Command
{
    protected $signature = 'mypa:subscription-lifecycle';

    protected $description = 'Expire ended subscriptions, send renewal reminders, clean stale checkout orders (daily)';

    public function handle(): int
    {
        // 1. Expire subscriptions whose paid period has ended.
        $expired = 0;
        Subscription::with(['user.settings', 'plan'])
            ->whereIn('status', ['active', 'trial'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->chunkById(100, function ($subscriptions) use (&$expired) {
                foreach ($subscriptions as $subscription) {
                    $subscription->update(['status' => 'expired']);
                    $subscription->user->notify(
                        new SubscriptionLifecycleNotification($subscription, 'expired'),
                    );
                    $expired++;
                }
            });

        // 2. Renewal reminders at the configured advance intervals — skipped
        //    for cancelled subscriptions (the user opted out of renewing).
        $reminded = 0;
        $days = config('mypa.billing.renewal_reminder_days', [15, 7, 3, 1, 0]);
        Subscription::with(['user.settings', 'plan'])
            ->where('status', 'active')
            ->whereNull('cancelled_at')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(max($days) + 1)])
            ->chunkById(100, function ($subscriptions) use ($days, &$reminded) {
                foreach ($subscriptions as $subscription) {
                    $daysLeft = (int) now()->startOfDay()->diffInDays($subscription->ends_at->copy()->startOfDay(), false);
                    if (in_array($daysLeft, $days, true)) {
                        $subscription->user->notify(
                            new SubscriptionLifecycleNotification($subscription, 'renewal_reminder', $daysLeft),
                        );
                        $reminded++;
                    }
                }
            });

        // 3. Expire stale unpaid checkout orders.
        $stale = PaymentOrder::whereIn('status', ['created', 'pending'])
            ->where('expires_at', '<', now()->subHour())
            ->update(['status' => 'expired']);

        $this->info("Expired {$expired} subscription(s), sent {$reminded} reminder(s), cleaned {$stale} stale order(s).");

        return self::SUCCESS;
    }
}
