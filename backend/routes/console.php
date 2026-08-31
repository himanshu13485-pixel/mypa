<?php

use Illuminate\Support\Facades\Schedule;

// Reminder engine: dispatch due reminders every minute.
Schedule::command('mypa:process-reminders')->everyMinute()->withoutOverlapping();

// Recurring tasks: roll forward completed/missed occurrences.
Schedule::command('mypa:generate-recurring')->hourly()->withoutOverlapping();

// Bill reminders: once per day, morning.
Schedule::command('mypa:send-bill-reminders')->dailyAt('08:00')->withoutOverlapping();

// Same-day bill alarms (due time minus N minutes) need minute resolution.
Schedule::command('mypa:send-bill-alarms')->everyMinute()->withoutOverlapping();

Schedule::command('mypa:project-reminders')->everyMinute()->withoutOverlapping();

// Meeting presence: drop participants whose browser vanished without leaving,
// and end meetings once the room is empty.
Schedule::command('mypa:reap-meetings')->everyMinute()->withoutOverlapping();

// Ten minutes before a scheduled meeting, everyone invited hears about it.
// Every minute, because "in ten minutes" is only true for one of them.
Schedule::command('mypa:send-meeting-reminders')->everyMinute()->withoutOverlapping();

// Daily ledger emails go out at 6 AM, only for projects that changed.
Schedule::command('mypa:project-daily-reports')->dailyAt('06:00')->withoutOverlapping();

// Subscription lifecycle: expiry, renewal reminders, stale order cleanup.
Schedule::command('mypa:subscription-lifecycle')->dailyAt('07:30')->withoutOverlapping();

// Housekeeping: purge read notifications older than 60 days.
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('created_at', '<', now()->subDays(60))
        ->delete();
})->daily()->name('prune-read-notifications');

// Unpaid invoices, chased on the days each company chose (its own schedule
// decides; a company with the schedule off is skipped).
Schedule::command('crm:chase-payments')->dailyAt('09:00')->withoutOverlapping();

// Subscriptions bill before anyone is chased about them: the morning's
// recurring documents go out first, then the reminder run reads the books.
Schedule::command('crm:generate-recurring')->dailyAt('07:30')->withoutOverlapping();

// The paid-leave accrual: one day a month to everyone past probation, and
// on 1 April the year just ended is closed out and paid.
Schedule::command('crm:credit-leaves')->monthlyOn(1, '00:20')->withoutOverlapping();
