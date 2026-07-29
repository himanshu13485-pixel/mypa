<?php

namespace App\Console\Commands;

use App\Jobs\SendTaskReminder;
use App\Models\TaskReminder;
use Illuminate\Console\Command;

class ProcessDueReminders extends Command
{
    protected $signature = 'mypa:process-reminders';

    protected $description = 'Dispatch queued notifications for task reminders that are due';

    public function handle(): int
    {
        $due = TaskReminder::query()
            ->whereNull('acknowledged_at')
            ->where(function ($q) {
                // Never sent and due, or snooze period elapsed.
                $q->where(fn ($w) => $w->whereNull('sent_at')->where('remind_at', '<=', now()))
                    ->orWhere(fn ($w) => $w->whereNotNull('snoozed_until')->where('snoozed_until', '<=', now()));
            })
            ->whereHas('task', fn ($t) => $t->whereNotIn('status', ['completed', 'cancelled', 'archived']))
            ->limit(500)
            ->get();

        foreach ($due as $reminder) {
            // Clear the consumed snooze so it isn't re-triggered next minute.
            if ($reminder->snoozed_until && $reminder->snoozed_until->isPast()) {
                $reminder->update(['snoozed_until' => null, 'sent_at' => null]);
            }
            SendTaskReminder::dispatch($reminder->id);
        }

        $this->info("Dispatched {$due->count()} reminder(s).");

        return self::SUCCESS;
    }
}
