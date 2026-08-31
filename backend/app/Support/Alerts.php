<?php

namespace App\Support;

/**
 * What kind of alert a notification is, and how loudly a device should say it.
 *
 * Every notification in the app now reaches the phone, which turns a question
 * that used to answer itself into one worth deciding: a chat message, a bill
 * falling due and an admin suspending your account all used to look identical
 * on the lock screen, because everything was posted to one unnamed channel at
 * one urgency with one sound. When only calls and shares pushed, that was
 * merely plain. With everything pushing it is unusable — you cannot learn to
 * ignore a sound that means six different things.
 *
 * So each notification kind belongs to a category, and the category decides
 * three things at once:
 *
 *   The Android channel it is posted to. Channels are the only mechanism
 *   Android gives a user to turn one sort of alert down without turning the
 *   app off, and they are per-channel forever once created — which is why the
 *   ids are versioned. An existing channel's importance and sound cannot be
 *   changed by an app update; only a new id gets new defaults.
 *
 *   Its urgency, which on both transports decides whether a sleeping phone is
 *   woken now or at its next maintenance window. Getting this wrong is not a
 *   cosmetic matter: a normal-urgency reminder for 9am can arrive at 11.
 *
 *   How long it is worth delivering. A "meeting starts in 10 minutes" pushed
 *   to a phone that was off, delivered when it comes back on that evening, is
 *   not a late notification — it is a false one.
 */
class Alerts
{
    /**
     * Kind → category. Anything unlisted is 'social', which is the safe
     * middle: it notifies, it makes a sound, it does not wake a sleeping
     * phone. A new kind that deserves more than that says so here.
     */
    private const KINDS = [
        // Chat. Its own category because it is the only one that arrives in
        // bursts, and the only one where each item must stand alone.
        'message' => 'chat',
        'missed_call' => 'chat',

        // Time-bound: something happens soon, or has just stopped waiting.
        'task_reminder' => 'reminders',
        'habit_reminder' => 'reminders',
        'goal_reminder' => 'reminders',
        'meeting_soon' => 'reminders',
        'event_reminder' => 'reminders',
        'project_reminder' => 'reminders',
        'entry_reminder' => 'reminders',

        // Money. Kept apart from reminders because the cost of missing one is
        // different in kind — a late fee is not a missed habit.
        'bill_due' => 'money',
        'bill_alarm' => 'money',
        'payment_successful' => 'money',
        'payment_failed' => 'money',
        'subscription_renewal_reminder' => 'money',
        'subscription_expired' => 'money',
        'expense_added' => 'money',
        'expense_updated' => 'money',
        'expense_deleted' => 'money',

        // Someone did something involving you.
        'connection_request' => 'social',
        'connection_accepted' => 'social',
        'task_assigned' => 'social',
        'task_updated' => 'social',
        'task_completed' => 'social',
        'task_comment' => 'social',
        'group_added' => 'social',
        'group_removed' => 'social',
        'group_role' => 'social',
        'conversation_added' => 'social',
        'event_invite' => 'social',
        'event_response' => 'social',
        'meeting_invite' => 'social',
        'file_shared' => 'social',
        'note_shared' => 'social',
        'project_shared' => 'social',

        // The app or an administrator acting on your account. Rare, and worth
        // reading even though nothing is on fire.
        'moderation_warning' => 'system',
        'report_resolved' => 'system',
        'change_request_approved' => 'system',
        'change_request_rejected' => 'system',
        'account_suspended' => 'system',
        'account_activated' => 'system',
        'account_roles' => 'system',
        'account_security' => 'system',
        'project_reset_request' => 'system',
    ];

    /**
     * Category → [android channel, urgency, TTL seconds, default title].
     *
     * The titles matter more than they look. A notification block shows the
     * title in bold and the body beneath, and every one of these used to read
     * "My PA" — so the bold half of every alert on the lock screen carried no
     * information at all. Naming the category there means the first glance is
     * already worth something.
     */
    private const CATEGORIES = [
        'chat' => ['messages_v1', 'high', 86400, 'New message'],
        'reminders' => ['reminders_v1', 'high', 3600, 'Reminder'],
        'money' => ['money_v1', 'high', 86400, 'Money'],
        'social' => ['social_v1', 'normal', 86400, 'Netvork'],
        'system' => ['system_v1', 'normal', 259200, 'Your account'],
        // Calls never reach these tables — they are data-only, and the shell
        // builds the ringing notification itself — but the channel is named
        // here so the set of channels lives in one place.
        'calls' => ['calls2', 'high', 45, 'Incoming call'],
    ];

    public static function categoryOf(string $kind): string
    {
        return self::KINDS[$kind] ?? 'social';
    }

    /** The Android channel id a kind's notification should be posted to. */
    public static function channelOf(string $kind): string
    {
        return self::CATEGORIES[self::categoryOf($kind)][0];
    }

    /** The default bold line, when a notification does not supply its own. */
    public static function titleOf(string $kind): string
    {
        return self::CATEGORIES[self::categoryOf($kind)][3];
    }

    /**
     * The delivery options both push transports read: urgency for web push,
     * priority for FCM, TTL for both.
     */
    public static function optionsOf(string $kind): array
    {
        [, $urgency, $ttl] = self::CATEGORIES[self::categoryOf($kind)];

        return ['TTL' => $ttl, 'urgency' => $urgency];
    }
}
