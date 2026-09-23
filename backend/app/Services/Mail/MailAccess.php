<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;

/**
 * Who may use Mails, and how many mailboxes they may hold.
 *
 * Three doors, each opened by the one above it:
 *
 *   the platform switches Mails on for a company, and sets the most
 *   mailboxes anybody there may hold (the cap);
 *
 *   the company's Admin always has Mails once it is on, and decides which
 *   of their people get it - the ordinary module right - and how many
 *   mailboxes each may add, never past the cap;
 *
 *   everybody else sees nothing change: no menu, no pages, no API.
 */
class MailAccess
{
    /** How many mailboxes a person may add when nobody has said otherwise. */
    public const DEFAULT_LIMIT = 3;

    /** The most the platform will let a company allow anybody. */
    public const HARD_CAP = 20;

    public static function orgEnabled(?Organization $org): bool
    {
        return (bool) $org?->mails_enabled;
    }

    public static function allows(Member $member): bool
    {
        return self::orgEnabled($member->organization) && $member->can('mails');
    }

    public static function cap(Organization $org): int
    {
        return max(1, min(self::HARD_CAP, (int) ($org->mails_mailbox_cap ?: self::DEFAULT_LIMIT)));
    }

    /** This person's mailbox allowance: their own number, within the cap. */
    public static function limitFor(Member $member): int
    {
        $cap = self::cap($member->organization);

        if ($member->crm_role === 'admin') {
            return $cap;
        }

        return max(1, min($cap, (int) ($member->mail_mailbox_limit ?? min(self::DEFAULT_LIMIT, $cap))));
    }

    /**
     * The room one person's mail may take, in megabytes - null for unlimited.
     *
     * The platform sets the company's ceiling; the Admin shares it out and
     * can never give anybody more than the ceiling. A company with no
     * ceiling set has none to enforce.
     */
    public static function storageFor(Member $member): ?int
    {
        /*
         * Three numbers can apply, and the smallest wins:
         *
         *   the room this person's plan gives them - the platform may raise
         *   or lower it for them alone;
         *   the ceiling the platform set for this company's people;
         *   what their own Admin allocated them out of it.
         *
         * Any of them may be absent, which means it has nothing to say.
         */
        $limits = array_filter([
            self::planLimitMb($member),
            self::storageCeiling($member->organization),
            $member->mail_storage_mb ? (int) $member->mail_storage_mb : null,
        ], fn ($mb) => $mb !== null);

        return $limits ? (int) min($limits) : null;
    }

    /** What this person's Netvork plan allows, in megabytes. */
    public static function planLimitMb(Member $member): ?int
    {
        $user = $member->user;
        if (! $user) {
            return null;
        }

        $bytes = app(\App\Services\SubscriptionEntitlementService::class)->storageLimitBytes($user);

        return $bytes === null ? null : (int) floor($bytes / 1048576);
    }

    /** The company's ceiling per person, in megabytes, or null for unlimited. */
    public static function storageCeiling(?Organization $org): ?int
    {
        $gb = (float) ($org?->mails_storage_gb ?? 0);

        return $gb > 0 ? (int) round($gb * 1024) : null;
    }

    /** What this person's mail actually takes up, in megabytes. */
    public static function storageUsed(Member $member): float
    {
        $bytes = (int) MailMessage::whereIn('mail_account_id', MailAccount::ownedBy($member)->pluck('id'))->sum('size');

        return round($bytes / 1048576, 2);
    }

    /**
     * Full? - asked before new mail is fetched, never mid-message.
     *
     * Two ways to be full: this mailbox has used the room it was allocated,
     * or the person has used their whole Netvork quota - mail, Drive, chat
     * files and all - because it is one quota rather than two.
     */
    public static function isFull(Member $member): bool
    {
        $limit = self::storageFor($member);
        if ($limit !== null && self::storageUsed($member) >= $limit) {
            return true;
        }

        $user = $member->user;
        if (! $user) {
            return false;
        }

        $service = app(\App\Services\SubscriptionEntitlementService::class);
        $whole = $service->storageLimitBytes($user);

        return $whole !== null && $service->usedStorageBytes($user) >= $whole;
    }

    /** What their whole quota looks like, for the screens that show it. */
    public static function quota(Member $member): array
    {
        $user = $member->user;
        $service = app(\App\Services\SubscriptionEntitlementService::class);

        return [
            'mail_used_mb' => self::storageUsed($member),
            'mail_limit_mb' => self::storageFor($member),
            'account_used_mb' => $user ? round($service->usedStorageBytes($user) / 1048576, 2) : 0,
            'account_limit_mb' => $user && $service->storageLimitBytes($user) !== null
                ? (int) floor($service->storageLimitBytes($user) / 1048576)
                : null,
            'plan' => $user ? $service->planFor($user)->slug : null,
        ];
    }

    /**
     * A person's own reading preferences, with the defaults filled in.
     *
     * undo_seconds  - how long a sent mail waits in the outbox, stoppable
     * conversation  - group replies into one thread, or show each mail alone
     * reading_pane  - where an open mail shows: right, bottom, or full page
     * density       - comfortable or compact rows
     * accent        - the colour the mail screens wear
     * load_images   - show remote images at once, or ask first (tracking pixels)
     */
    public static function prefs(Member $member): array
    {
        return array_merge([
            'undo_seconds' => 10,
            'conversation' => true,
            'reading_pane' => 'right',
            'density' => 'comfortable',
            'accent' => 'brand',
            'load_images' => 'ask',
            'default_account' => null,
            'page_size' => 50,
        ], (array) ($member->mail_prefs ?? []));
    }
}
