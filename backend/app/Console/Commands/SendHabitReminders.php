<?php

namespace App\Console\Commands;

use App\Models\Habit;
use App\Notifications\SocialNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Nudge people about a habit at the time they asked to be nudged.
 *
 * `reminder_time` has been collectable since habits shipped and nothing has
 * ever read it: you could set a reminder for 7am and no reminder existed.
 *
 * Runs every minute because the time is the user's own wall clock, and 7am
 * happens at a different instant for each of them. Matching to the minute is
 * also what keeps this from repeating — a given local HH:MM comes round once
 * a day, so there is no "already sent" column to keep.
 */
class SendHabitReminders extends Command
{
    protected $signature = 'mypa:habit-reminders';

    protected $description = 'Remind people about habits due at this minute in their own timezone';

    public function handle(): int
    {
        $sent = 0;

        Habit::with(['user.profile'])
            ->whereNull('archived_at')
            ->whereNotNull('reminder_time')
            ->chunkById(200, function ($habits) use (&$sent) {
                foreach ($habits as $habit) {
                    if (! $habit->user) {
                        continue;
                    }

                    $tz = $habit->user->profile?->timezone ?? config('app.timezone');
                    $now = Carbon::now($tz);

                    // H:i on both sides: the column is a TIME and comes back
                    // as "07:00:00", which never equals a formatted "07:00".
                    if ($now->format('H:i') !== Carbon::parse($habit->reminder_time)->format('H:i')) {
                        continue;
                    }

                    // Nothing is more annoying than being told to do the thing
                    // you have already done, so the period's target is checked
                    // first — and a habit done early simply stays quiet.
                    if ($this->doneForPeriod($habit, $now)) {
                        continue;
                    }

                    $habit->user->notify(new SocialNotification(
                        'habit_reminder',
                        "Time for “{$habit->name}”.",
                        ['habit_uuid' => $habit->uuid, 'name' => $habit->name],
                        '/habits',
                    ));
                    $sent++;
                }
            });

        $this->info("Sent {$sent} habit reminder(s).");

        return self::SUCCESS;
    }

    /** Has this habit already hit its target for the period it is counted in? */
    protected function doneForPeriod(Habit $habit, Carbon $now): bool
    {
        [$from, $to] = match ($habit->frequency) {
            'weekly' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };

        $done = (int) $habit->logs()
            ->whereBetween('logged_on', [$from->toDateString(), $to->toDateString()])
            ->sum('count');

        return $done >= max(1, (int) $habit->target_per_period);
    }
}
