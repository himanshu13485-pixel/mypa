<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Notifications\BillDueNotification;
use Illuminate\Console\Command;

class SendBillReminders extends Command
{
    protected $signature = 'mypa:send-bill-reminders';

    protected $description = 'Notify users about unpaid bills entering their reminder window (daily)';

    public function handle(): int
    {
        $sent = 0;

        Bill::with('user.settings')
            ->where('status', 'unpaid')
            ->whereRaw('due_on <= DATE(?)', [now()->addDays(60)->toDateString()])
            ->where(function ($q) {
                $q->whereNull('last_reminded_at')
                    ->orWhere('last_reminded_at', '<', now()->startOfDay());
            })
            ->chunkById(200, function ($bills) use (&$sent) {
                foreach ($bills as $bill) {
                    $windowStart = $bill->due_on->copy()->subDays($bill->remind_days_before);
                    if (now()->startOfDay()->lt($windowStart)) {
                        continue; // not yet inside the reminder window
                    }

                    $bill->user->notify(new BillDueNotification($bill));
                    $bill->updateQuietly(['last_reminded_at' => now()]);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} bill reminder(s).");

        return self::SUCCESS;
    }
}
