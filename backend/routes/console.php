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

// Subscription lifecycle: expiry, renewal reminders, stale order cleanup.
Schedule::command('mypa:subscription-lifecycle')->dailyAt('07:30')->withoutOverlapping();

// Housekeeping: purge read notifications older than 60 days.
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('notifications')
        ->whereNotNull('read_at')
        ->where('created_at', '<', now()->subDays(60))
        ->delete();
})->daily()->name('prune-read-notifications');
