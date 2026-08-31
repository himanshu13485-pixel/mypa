<?php

namespace App\Console\Commands;

use App\Models\Goal;
use App\Notifications\SocialNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tell people a goal's date is coming, while there is still time to act on it.
 *
 * Goals carried a target_date that nothing ever looked at, so the only way to
 * find out one had run out was to go and look. Three moments are worth an
 * interruption and no more: a week out, the day before, and the day itself.
 *
 * Daily rather than every minute, and only on those exact offsets, which is
 * what keeps a goal with a date three weeks away from saying so every morning
 * for twenty-one days. That also means no "last reminded" column: each offset
 * can only match once.
 */
class SendGoalReminders extends Command
{
    protected $signature = 'mypa:goal-reminders';

    protected $description = 'Remind people about goals whose target date is approaching';

    public function handle(): int
    {
        $sent = 0;

        Goal::with(['user.profile'])
            ->where('status', 'active')
            ->whereNotNull('target_date')
            ->chunkById(200, function ($goals) use (&$sent) {
                foreach ($goals as $goal) {
                    if (! $goal->user) {
                        continue;
                    }

                    $tz = $goal->user->profile?->timezone ?? config('app.timezone');
                    $today = Carbon::now($tz)->startOfDay();
                    $days = (int) $today->diffInDays($goal->target_date->copy()->startOfDay(), false);

                    $message = match ($days) {
                        7 => "“{$goal->title}” is due in a week" . $this->progress($goal) . '.',
                        1 => "“{$goal->title}” is due tomorrow" . $this->progress($goal) . '.',
                        0 => "“{$goal->title}” is due today" . $this->progress($goal) . '.',
                        default => null,
                    };

                    if ($message === null) {
                        continue;
                    }

                    $goal->user->notify(new SocialNotification(
                        'goal_reminder',
                        $message,
                        ['goal_uuid' => $goal->uuid, 'title' => $goal->title, 'days_left' => $days],
                        '/goals',
                    ));
                    $sent++;
                }
            });

        $this->info("Sent {$sent} goal reminder(s).");

        return self::SUCCESS;
    }

    /** " — 60% done", when there is progress worth mentioning. */
    protected function progress(Goal $goal): string
    {
        $progress = (int) $goal->progress;

        return $progress > 0 ? " — {$progress}% done" : '';
    }
}
