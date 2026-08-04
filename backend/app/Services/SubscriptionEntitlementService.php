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

        return $drive + $chat + $meetings;
    }

    public function storageLimitBytes(User $user): ?int
    {
        return $this->planFor($user)->limit('storage_bytes')
            ?? (int) config('mypa.files.storage_limit_bytes');
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
        ];
    }
}
