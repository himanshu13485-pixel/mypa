<?php

namespace App\Jobs;

use App\Models\TaskReminder;
use App\Notifications\TaskReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTaskReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 60;

    /** Re-nag interval when repeat_until_acknowledged is set. */
    public const REPEAT_MINUTES = 10;

    public function __construct(public int $reminderId)
    {
    }

    public function handle(): void
    {
        $reminder = TaskReminder::with(['task', 'user.settings'])->find($this->reminderId);

        if (! $reminder || $reminder->acknowledged_at) {
            return;
        }

        $task = $reminder->task;

        // Don't nag about tasks that no longer need attention.
        if (! $task || in_array($task->status, ['completed', 'cancelled', 'archived'])) {
            $reminder?->update(['acknowledged_at' => now()]);

            return;
        }

        // Respect an active snooze: skip now, the scheduler re-dispatches when due.
        if ($reminder->snoozed_until && $reminder->snoozed_until->isFuture()) {
            return;
        }

        $reminder->user->notify(new TaskReminderNotification($reminder));
        $reminder->update(['sent_at' => now()]);

        if ($reminder->repeat_until_acknowledged) {
            self::dispatch($reminder->id)->delay(now()->addMinutes(self::REPEAT_MINUTES));
        }
    }
}
