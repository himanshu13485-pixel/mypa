<?php

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DueNowNotification;
use App\Support\DueNow;
use Illuminate\Console\Command;

/**
 * The due-now alarm, sent to the devices rather than to an open page.
 *
 * The screen alarm can only ring a page somebody is looking at, which is the
 * wrong half of the day - a due date passes while the laptop is shut. This
 * asks the same question the screen asks, from the same place, and tells the
 * phone.
 *
 * Once per thing, and again after a snooze the person asked for has run out.
 * A notification every few minutes about a task they have decided to do later
 * is how people turn notifications off.
 */
class PushDueAlerts extends Command
{
    protected $signature = 'mypa:push-due-alerts {--dry-run : Say what would go, send nothing}';

    protected $description = 'Push the tasks and bills that are due now to their owner’s devices';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;

        /*
         * Only people with something due, found from the items rather than by
         * walking every account: a company of three hundred has perhaps
         * twelve things due at any minute, and this runs every five.
         */
        $owners = Task::whereNotNull('due_at')->where('due_at', '<=', now())
            ->whereNotIn('status', ['completed', 'cancelled', 'archived'])
            ->distinct()->pluck('user_id')
            ->merge(
                Bill::where('status', 'unpaid')->whereDate('due_on', '<=', now()->toDateString())
                    ->distinct()->pluck('user_id')
            )
            ->unique();

        User::whereIn('id', $owners)->chunkById(100, function ($users) use ($dry, &$sent) {
            foreach ($users as $user) {
                $due = DueNow::tasks($user)->concat(DueNow::bills($user));

                foreach ($due as $item) {
                    if (! DueNow::needsPush($item)) {
                        continue;
                    }

                    $sent++;
                    if ($dry) {
                        continue;
                    }

                    $user->notify(new DueNowNotification($item));
                    // Quietly: this is the alarm's own bookkeeping, not a
                    // change to the task, and it must not shuffle the thing
                    // up somebody's list or count as an edit.
                    $item->updateQuietly(['alert_pushed_at' => now()]);
                }
            }
        });

        $this->info(($dry ? 'Would send ' : 'Sent ') . $sent . ' due alert(s).');

        return self::SUCCESS;
    }
}
