<?php

namespace App\Support;

/**
 * The menus a notification belongs to, so each can be switched off alone.
 *
 * There were two switches for the whole app - e-mail on, push on - which is
 * not a choice anybody actually wants to make. What people want is "stop
 * writing to me every time somebody logs a payment" while keeping the leave
 * approvals and the tasks. So every notification kind is filed under the
 * screen it comes from, and each screen carries its own pair of switches.
 *
 * A kind that is not listed falls under 'other', which is one switch for
 * everything nobody has filed yet - not silence. A notification that stopped
 * arriving because nobody remembered to categorise it is a bug that looks
 * like a setting.
 */
class NotificationTopics
{
    /**
     * The topics, in the order the settings screen shows them: the personal
     * app first, then the CRM by its own sidebar groups.
     *
     * @var array<string, array{label: string, hint: string, group: string}>
     */
    public const TOPICS = [
        'chat' => ['label' => 'Messages and calls', 'hint' => 'New messages, missed calls, group changes.', 'group' => 'Personal'],
        'connections' => ['label' => 'Connections', 'hint' => 'Requests to connect, and acceptances.', 'group' => 'Personal'],
        'calendar' => ['label' => 'Calendar and meetings', 'hint' => 'Invites, responses, and what starts soon.', 'group' => 'Personal'],
        'reminders' => ['label' => 'Reminders', 'hint' => 'Tasks, habits, goals and bills falling due.', 'group' => 'Personal'],
        'files' => ['label' => 'Notes and files', 'hint' => 'Something shared with you.', 'group' => 'Personal'],
        'account' => ['label' => 'Account and security', 'hint' => 'Sign-in codes, plan changes, admin notices.', 'group' => 'Personal'],

        'leads' => ['label' => 'Leads', 'hint' => 'New leads, follow-ups and transfers.', 'group' => 'CRM'],
        'clients' => ['label' => 'Clients', 'hint' => 'Access requests and client changes.', 'group' => 'CRM'],
        'invoices' => ['label' => 'Invoices and proforma', 'hint' => 'Documents raised, changed or cancelled.', 'group' => 'CRM'],
        'payments' => ['label' => 'Payments', 'hint' => 'Money logged, claimed and settled.', 'group' => 'CRM'],
        'tds' => ['label' => 'TDS certificates', 'hint' => 'Certificates chased and received.', 'group' => 'CRM'],
        'vendors' => ['label' => 'Vendors', 'hint' => 'Vendor bills and vendor changes.', 'group' => 'CRM'],
        'expenses' => ['label' => 'Expenses', 'hint' => 'Expenses added, approved and paid.', 'group' => 'CRM'],
        'salary' => ['label' => 'Salary and incentives', 'hint' => 'Slips, holds and payouts.', 'group' => 'CRM'],
        'tasks' => ['label' => 'Tasks and pendencies', 'hint' => 'Work assigned to you, and replies on it.', 'group' => 'CRM'],
        'approvals' => ['label' => 'Approvals', 'hint' => 'Requests waiting on you, and your own decided.', 'group' => 'CRM'],
        'leaves' => ['label' => 'Leaves and attendance', 'hint' => 'Leave applied, approved or refused.', 'group' => 'CRM'],
        'complaints' => ['label' => 'Complaints (CMS)', 'hint' => 'Complaints raised and answered.', 'group' => 'CRM'],
        'contests' => ['label' => 'Contests', 'hint' => 'Contests opening, and results.', 'group' => 'CRM'],
        'notice' => ['label' => 'Notice board', 'hint' => 'Notices, birthdays and celebrations.', 'group' => 'CRM'],

        'other' => ['label' => 'Everything else', 'hint' => 'Anything not covered above.', 'group' => 'Other'],
    ];

    /**
     * Kind -> topic. Anything absent is 'other'.
     *
     * @var array<string, string>
     */
    private const OF_KIND = [
        'message' => 'chat',
        'missed_call' => 'chat',
        'call_invite' => 'chat',
        'group_added' => 'chat',
        'group_removed' => 'chat',
        'group_role' => 'chat',
        'conversation_added' => 'chat',

        'connection_request' => 'connections',
        'connection_accepted' => 'connections',

        'event_invite' => 'calendar',
        'event_response' => 'calendar',
        'event_reminder' => 'calendar',
        'meeting_invite' => 'calendar',
        'meeting_soon' => 'calendar',
        'booking_made' => 'calendar',
        'booking_cancelled' => 'calendar',

        'task_reminder' => 'reminders',
        'habit_reminder' => 'reminders',
        'goal_reminder' => 'reminders',
        'project_reminder' => 'reminders',
        'entry_reminder' => 'reminders',
        'bill_due' => 'reminders',
        'bill_alarm' => 'reminders',

        'note_shared' => 'files',
        'file_shared' => 'files',
        'folder_shared' => 'files',

        'sign_in_code' => 'account',
        'payment_successful' => 'account',
        'payment_failed' => 'account',
        'subscription_renewal_reminder' => 'account',
        'subscription_expired' => 'account',
        'account_notice' => 'account',

        // Personal task sharing lives with CRM tasks: it is the same word on
        // the same screen, and splitting it would need two switches to
        // silence one thing.
        'task_assigned' => 'tasks',
        'task_updated' => 'tasks',
        'task_completed' => 'tasks',
        'task_comment' => 'tasks',
        'crm_task' => 'tasks',

        'crm_lead' => 'leads',
        'crm_lead_access' => 'leads',
        'crm_client_access' => 'clients',
        'crm_invoice' => 'invoices',
        'crm_invoice_update' => 'invoices',
        'crm_payment' => 'payments',
        'crm_tds' => 'tds',
        'crm_vendor' => 'vendors',
        'crm_expense' => 'expenses',
        'expense_added' => 'expenses',
        'expense_updated' => 'expenses',
        'expense_deleted' => 'expenses',
        'crm_salary' => 'salary',
        'crm_incentive' => 'salary',
        'crm_approval' => 'approvals',
        'crm_leave' => 'leaves',
        'crm_punch' => 'leaves',
        'crm_complaint' => 'complaints',
        'crm_contest' => 'contests',
        'crm_notice' => 'notice',
        'crm_celebration' => 'notice',
        'crm_birthday' => 'notice',
    ];

    /** Which menu this notification belongs to. */
    public static function of(string $kind): string
    {
        return self::OF_KIND[$kind] ?? 'other';
    }

    /** The topics, as the settings screen wants them. */
    public static function all(): array
    {
        return collect(self::TOPICS)
            ->map(fn (array $topic, string $key) => ['key' => $key] + $topic)
            ->values()
            ->all();
    }

    public static function exists(string $topic): bool
    {
        return array_key_exists($topic, self::TOPICS);
    }

    public static function isCrm(string $topic): bool
    {
        return (self::TOPICS[$topic]['group'] ?? null) === 'CRM';
    }

    /** The topics a person decides for themselves: everything but the CRM's. */
    public static function personal(): array
    {
        return array_values(array_filter(self::all(), fn (array $t) => $t['group'] !== 'CRM'));
    }

    /** The topics a company's Admin decides for everybody in it. */
    public static function crm(): array
    {
        return array_values(array_filter(self::all(), fn (array $t) => $t['group'] === 'CRM'));
    }

    /**
     * May a notification from this menu reach this person on this channel?
     *
     * Three layers, in this order. The person's own app-wide switch first:
     * somebody who turned e-mail off entirely is not written to by anything.
     * Then, for a CRM menu, the company's policy - an Admin decides for the
     * whole company whether payments or vendors send mail, and an employee
     * does not overrule the company about the company's own mail. And for
     * everything personal, the person's own per-menu switch.
     */
    public static function allows(object $notifiable, string $topic, string $channel): bool
    {
        $settings = $notifiable->settings ?? null;

        if ($settings && ! $settings->notificationValue($channel === 'email' ? 'email' : 'push')) {
            return false;
        }

        if (self::isCrm($topic) && $notifiable instanceof \App\Models\User) {
            $org = self::companyOf($notifiable);
            if ($org) {
                return $org->topicAllows($topic, $channel);
            }
        }

        return $settings?->topicAllows($topic, $channel) ?? true;
    }

    /** The active company this person works for, if any. */
    private static function companyOf(\App\Models\User $user): ?\App\Models\Crm\Organization
    {
        return \App\Models\Crm\Member::with('organization')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get()
            ->map(fn ($m) => $m->organization)
            ->first(fn ($org) => $org && $org->status === 'active');
    }
}
