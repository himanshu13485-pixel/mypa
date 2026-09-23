<?php

namespace App\Services;

use App\Models\File;
use App\Models\Group;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;

/**
 * Single authority for "what can this user do on their plan".
 * All plan restrictions are enforced here on the backend — the frontend only
 * mirrors what these checks return.
 */
class SubscriptionEntitlementService
{
    /** @var array<int, Plan> per-request cache */
    protected array $planCache = [];

    public function planFor(User $user): Plan
    {
        if (isset($this->planCache[$user->id])) {
            return $this->planCache[$user->id];
        }

        $subscription = Subscription::with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest('started_at')
            ->first();

        $plan = $subscription?->isCurrentlyActive() ? $subscription->plan : null;

        // Everyone falls back to the Free plan.
        $plan ??= Plan::where('slug', 'free')->first()
            ?? new Plan(['slug' => 'free', 'name' => 'Free', 'limits' => [], 'features' => []]);

        return $this->planCache[$user->id] = $plan;
    }

    public function subscriptionFor(User $user): ?Subscription
    {
        return Subscription::with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trial'])
            ->latest('started_at')
            ->first();
    }

    // --- Limit checks (null limit = unlimited) ------------------------------

    public function canCreateTask(User $user): bool
    {
        $limit = $this->planFor($user)->limit('max_tasks');

        return $limit === null
            || Task::where('user_id', $user->id)->where('status', '!=', 'archived')->count() < $limit;
    }

    public function canUploadBytes(User $user, int $incoming): bool
    {
        $limit = $this->storageLimitBytes($user);

        return $limit === null || ($this->usedStorageBytes($user) + $incoming) <= $limit;
    }

    /**
     * Everything this user has put on disk, not just their Drive.
     *
     * Chat attachments and meeting chat files were never counted, so the
     * quota could be bypassed entirely by sending files through a
     * conversation instead of uploading them — and the storage figure shown
     * to the user understated what they were actually using.
     */
    public function usedStorageBytes(User $user): int
    {
        $drive = (int) File::where('user_id', $user->id)->sum('size');

        $chat = (int) \App\Models\MessageAttachment::whereHas(
            'message',
            fn ($m) => $m->where('user_id', $user->id),
        )->sum('size');

        $meetings = (int) \App\Models\MeetingFile::where('user_id', $user->id)->sum('size');

        return $drive + $chat + $meetings + $this->mailBytes($user);
    }

    /**
     * The mail this person keeps in the CRM.
     *
     * Mail used to be measured on its own, against a number of its own, so a
     * person on the 20 GB plan had 20 GB for files and something else
     * entirely for mail. It is one quota: what arrives in a mailbox takes up
     * the same room as what is uploaded to Drive.
     */
    public function mailBytes(User $user): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('crm_mail_messages')) {
            return 0;
        }

        $accounts = \App\Models\Crm\MailAccount::whereIn(
            'member_id',
            \App\Models\Crm\Member::where('user_id', $user->id)->pluck('id'),
        )->pluck('id');

        return $accounts->isEmpty()
            ? 0
            : (int) \App\Models\Crm\MailMessage::whereIn('mail_account_id', $accounts)->sum('size');
    }

    /**
     * How much room this person has, in bytes - null when nothing limits it.
     *
     * The platform may raise or lower it for one person without inventing a
     * plan for them; otherwise it is whatever their plan says. A plan that
     * names storage_bytes as null means unlimited and is honoured as such -
     * it used to fall through to the 1 GB default, so "unlimited" was
     * impossible to express.
     */
    public function storageLimitBytes(User $user): ?int
    {
        if ($user->storage_override_bytes !== null) {
            return (int) $user->storage_override_bytes;
        }

        $plan = $this->planFor($user);
        if (array_key_exists('storage_bytes', (array) $plan->limits)) {
            $limit = $plan->limits['storage_bytes'];

            return $limit === null ? null : (int) $limit;
        }

        return (int) config('mypa.files.storage_limit_bytes');
    }

    public function canCreateGroup(User $user): bool
    {
        $limit = $this->planFor($user)->limit('max_groups');

        return $limit === null
            || Group::where('owner_id', $user->id)->count() < $limit;
    }

    public function canAddGroupMember(User $user, Group $group): bool
    {
        $limit = $this->planFor($group->owner ?? $user)->limit('max_group_members');

        return $limit === null || $group->members()->count() < $limit;
    }

    /**
     * How many people may be in one of this user's meetings, null for no cap.
     *
     * Always the host's plan, never the joiner's — the meeting belongs to
     * whoever opened it, and a guest has no plan at all to consult.
     */
    public function meetingParticipantLimit(User $host): ?int
    {
        return $this->planFor($host)->limit('max_meeting_participants');
    }

    /** How long one of this user's meetings may run, in minutes. Null = no cap. */
    public function meetingMinutesLimit(User $host): ?int
    {
        return $this->planFor($host)->limit('max_meeting_minutes');
    }

    public function hasFeature(User $user, string $feature): bool
    {
        return $this->planFor($user)->hasFeature($feature);
    }

    /** Upgrade hint: the cheapest public plan whose limit satisfies $needed. */
    public function planWithHigherLimit(string $limitKey, int $needed): ?Plan
    {
        return Plan::where('is_active', true)->where('is_public', true)
            ->orderBy('monthly_price')
            ->get()
            ->first(fn (Plan $plan) => $plan->limit($limitKey) === null || $plan->limit($limitKey) > $needed);
    }

    public function usage(User $user): array
    {
        $plan = $this->planFor($user);

        return [
            'tasks' => [
                'used' => Task::where('user_id', $user->id)->where('status', '!=', 'archived')->count(),
                'limit' => $plan->limit('max_tasks'),
            ],
            'storage' => [
                'used' => $this->usedStorageBytes($user),
                'limit' => $this->storageLimitBytes($user),
            ],
            'groups' => [
                'used' => Group::where('owner_id', $user->id)->count(),
                'limit' => $plan->limit('max_groups'),
            ],
            // No "used" for these: they are per-meeting ceilings rather than
            // a running total, so the settings page shows the ceiling alone.
            'meeting_participants' => ['limit' => $plan->limit('max_meeting_participants')],
            'meeting_minutes' => ['limit' => $plan->limit('max_meeting_minutes')],
        ];
    }
}
