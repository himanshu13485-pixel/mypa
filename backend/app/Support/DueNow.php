<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What is due for somebody right now — the one definition of it.
 *
 * The screen asks this, and so does the command that sends the alarm to a
 * phone with the app shut. Two readings of "due" would be two alarms that
 * disagree: a bill snoozed on the laptop still buzzing the phone, and a task
 * the phone never mentioned because only the page knew the rule.
 *
 * Snoozing is part of the rule rather than a filter on top of it, for the
 * same reason.
 */
class DueNow
{
    /**
     * @return Collection<int, Task>
     */
    public static function tasks(User $user, ?Carbon $at = null): Collection
    {
        $now = $at ?? now();

        return Task::where('user_id', $user->id)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->whereNotIn('status', ['completed', 'cancelled', 'archived'])
            ->where(fn ($q) => $q->whereNull('alert_snoozed_until')->orWhere('alert_snoozed_until', '<=', $now))
            ->orderBy('due_at')
            ->limit(20)
            ->get();
    }

    /**
     * @return Collection<int, Bill>
     */
    public static function bills(User $user, ?Carbon $at = null): Collection
    {
        $now = $at ?? now();

        return Bill::where('user_id', $user->id)
            ->where('status', 'unpaid')
            ->whereDate('due_on', '<=', $now->toDateString())
            ->where(fn ($q) => $q->whereNull('alert_snoozed_until')->orWhere('alert_snoozed_until', '<=', $now))
            ->orderBy('due_on')
            ->limit(20)
            ->get()
            /*
             * A bill's own hour, when it has been given one.
             *
             * due_on is a date and due_time the hour somebody wants to be
             * told; without the second, "due today" would ring at midnight,
             * which is the one time of day nobody wants to hear about a bill.
             * A bill from an earlier day is simply late, and says so now.
             */
            ->filter(function (Bill $bill) use ($now) {
                if (! $bill->due_time || $bill->due_on->toDateString() < $now->toDateString()) {
                    return true;
                }

                return Carbon::parse($bill->due_on->toDateString() . ' ' . $bill->due_time)->lte($now);
            })
            ->values();
    }

    /**
     * Has this one already been sent to the person's devices?
     *
     * Pushed once per occurrence, and again after a snooze they asked for
     * has run out - which is what "remind me in twenty minutes" means. A
     * thing pushed and left alone stays on the screen and off the phone,
     * because a notification every five minutes for a task somebody has
     * decided to do later is how people switch notifications off.
     */
    public static function needsPush(Task|Bill $item): bool
    {
        if ($item->alert_pushed_at === null) {
            return true;
        }

        $pushed = Carbon::parse($item->alert_pushed_at);
        $snoozed = $item->alert_snoozed_until ? Carbon::parse($item->alert_snoozed_until) : null;

        return $snoozed !== null && $snoozed->gt($pushed);
    }
}
