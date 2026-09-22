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

// Half an hour before a calendar entry, for the same reason and on the same
// cadence. Longer notice than a meeting because an appointment usually has a
// journey in front of it.
Schedule::command('mypa:send-event-reminders')->everyMinute()->withoutOverlapping();

// Habit nudges at the time the person set, in their own timezone — so every
// minute, because 7am is a different instant for each of them.
Schedule::command('mypa:habit-reminders')->everyMinute()->withoutOverlapping();

// Goal target dates: a week out, the day before, and the day itself. Once a
// day is enough, and is what stops a distant date reminding every morning.
Schedule::command('mypa:goal-reminders')->dailyAt('08:30')->withoutOverlapping();

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

// Disappearing messages. Hourly is close enough for spans measured in days,
// and a conversation with no span set is never touched.
Schedule::command('chat:purge-expired')->hourly()->withoutOverlapping();

/*
 * The due-now alarm, for the device that is not looking at the app.
 *
 * Five minutes is close enough for a due date to feel answered and far
 * enough that a quiet afternoon costs almost nothing. The command decides
 * what is due and what has already been said - see App\Support\DueNow.
 */
Schedule::command('mypa:push-due-alerts')->everyFiveMinutes()->withoutOverlapping();

/*
 * Sales served to the end of their term.
 *
 * Validity is measured in days, so once a night is often enough - and early
 * morning means the office finds yesterday's endings already accounted for
 * rather than watching rows change under them during the day.
 */
Schedule::command('crm:close-served-dispatches')->dailyAt('01:30')->withoutOverlapping();

/*
 * Work orders about to run out.
 *
 * Once a day, because validity is measured in days and the warnings are set
 * in days. Early, so the office finds them waiting rather than watching them
 * arrive through the afternoon - and after the dispatch sweep, so a work
 * order that ended last night is already closed rather than being warned
 * about on its way out.
 */
Schedule::command('crm:warn-renewals')->dailyAt('02:00')->withoutOverlapping();

/*
 * Mails. Due mail is swept up every minute - the delayed job normally sends
 * it to the second, and this is the net under it - and every mailbox is
 * brought up to date every five, one queued job each.
 */
Schedule::command('mails:tick dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('mails:tick sync')->everyFiveMinutes()->withoutOverlapping();
