<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Notifications\BillDueNotification;
use Illuminate\Console\Command;

/**
 * Same-day bill alarm: bills with a due TIME and a minutes-before setting get
 * one sharp alert at (due datetime - minutes), on top of the daily
 * days-before reminder. Runs every minute.
 */
class SendBillAlarms extends Command
{
    protected $signature = 'mypa:send-bill-alarms';

    protected $description = 'Ring same-day bill alarms (due time minus N minutes)';

    public function handle(): int
    {
        $sent = 0;

        Bill::with('user.settings')
            ->where('status', 'unpaid')
            ->whereNull('alarm_sent_at')
            ->whereNotNull('due_time')
            ->whereNotNull('remind_minutes_before')
            ->whereDate('due_on', '<=', now()->toDateString())
            ->chunkById(200, function ($bills) use (&$sent) {
                foreach ($bills as $bill) {
                    $dueAt = $bill->due_on->copy()->setTimeFromTimeString($bill->due_time);
                    $alarmAt = $dueAt->copy()->subMinutes($bill->remind_minutes_before);
                    if (now()->lt($alarmAt)) {
                        continue; // not time yet
                    }

                    $bill->user->notify(new BillDueNotification($bill, alarm: true));
                    $bill->updateQuietly(['alarm_sent_at' => now()]);
                    $sent++;
                }
            });

        $this->info("Rang {$sent} bill alarm(s).");

        return self::SUCCESS;
    }
}
