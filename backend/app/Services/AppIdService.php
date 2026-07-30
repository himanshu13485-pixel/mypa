<?php

namespace App\Services;

use App\Models\AppId;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AppIdService
{
    /**
     * Generate the unique, permanent My PA App ID for a user (e.g. MYPA-100001).
     * The number comes from the app_ids auto-increment, so uniqueness is
     * guaranteed by the database even under concurrent registrations.
     */
    public function generateFor(User $user): AppId
    {
        return DB::transaction(function () use ($user) {
            $record = AppId::create([
                'user_id' => $user->id,
                'app_id' => 'PENDING-' . $user->id,
            ]);

            $record->update([
                'app_id' => $this->format($record->id),
            ]);

            return $record;
        });
    }

    /**
     * Regenerate an App ID (admin-only, exceptional cases). The old ID is
     * recorded and permanently retired.
     */
    public function regenerateFor(User $user): AppId
    {
        return DB::transaction(function () use ($user) {
            $old = $user->appId;
            $oldId = $old?->app_id;
            $old?->delete();

            $record = AppId::create([
                'user_id' => $user->id,
                'app_id' => 'PENDING-' . $user->id,
                'regenerated_from' => $oldId,
            ]);

            $record->update([
                'app_id' => $this->format($record->id),
            ]);

            return $record;
        });
    }

    protected function format(int $sequence): string
    {
        return config('mypa.app_id_prefix', 'MYPA') . '-' . $sequence;
    }

    /**
     * Find a user by App ID, respecting the target's privacy settings.
     * Returns null when not found, inactive, or hidden from the viewer.
     */
    public function findVisibleUser(string $appId, User $viewer): ?User
    {
        $target = $this->lookup(trim($appId));

        if (! $target || $target->status !== 'active' || $target->id === $viewer->id) {
            return $target?->id === $viewer->id ? $target : null;
        }

        if ($target->hasBlocked($viewer) || $viewer->hasBlocked($target)) {
            return null;
        }

        $visibility = $target->settings?->privacyValue('who_can_find_me') ?? 'everyone';

        if ($visibility === 'nobody') {
            return null;
        }

        if ($visibility === 'connections' && ! $this->areConnected($viewer, $target)) {
            return null;
        }

        return $target;
    }

    /**
     * Resolve any identity handle to a user: App ID (MYPA-…), username, or
     * mobile number with ISD code.
     */
    protected function lookup(string $identifier): ?User
    {
        // App ID
        if (preg_match('/^' . preg_quote(config('mypa.app_id_prefix', 'MYPA'), '/') . '-/i', $identifier)) {
            return AppId::with('user.settings', 'user.profile')
                ->where('app_id', strtoupper($identifier))
                ->where('is_active', true)
                ->first()?->user;
        }

        // Mobile numbers are records-only and deliberately NOT searchable.
        // Username
        return User::with(['settings', 'profile'])
            ->whereRaw('LOWER(username) = ?', [mb_strtolower($identifier)])
            ->first();
    }

    public function areConnected(User $a, User $b): bool
    {
        return \App\Models\Connection::where('status', 'accepted')
            ->where(function ($q) use ($a, $b) {
                $q->where(fn ($w) => $w->where('requester_id', $a->id)->where('addressee_id', $b->id))
                    ->orWhere(fn ($w) => $w->where('requester_id', $b->id)->where('addressee_id', $a->id));
            })
            ->exists();
    }
}
