<?php

namespace App\Services;

use App\Models\AppId;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AppIdService
{
    /**
     * Generate the unique, permanent Netvork App ID for a user (e.g. NV-100001).
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
        return config('mypa.app_id_prefix', 'NV') . '-' . $sequence;
    }

    /**
     * Search for people to connect with, by part of a name, username or App ID.
     *
     * Exact lookup answers "I have their handle". This answers the question
     * people actually arrive with — they remember a piece of it, or they
     * only ever knew the person's name. Somebody registered as harshgrapout
     * is found by "grapout", and Priyanshu is found by "Priyanshu".
     *
     * Deliberately narrow about what a fragment may match. A name, a
     * username and an App ID are what one colleague knows another by, and
     * matching part of one gives away nothing the whole one did not. An
     * e-mail address is not like that: it still has to be typed in full, or
     * this becomes a way to read the address book of the whole platform
     * three letters at a time.
     *
     * Every candidate goes through the same privacy gate as a direct lookup,
     * so being findable by fragment is never broader than being findable.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function searchVisibleUsers(string $term, User $viewer, int $limit = 10): \Illuminate\Support\Collection
    {
        $term = trim($term);

        // An exact handle answers first, and at any length.
        $exact = $this->findVisibleUser($term, $viewer);

        // Fragments start at three characters: "a" is not a search, it is a
        // request for the user table. An address is matched whole, never part.
        if (mb_strlen($term) < 3 || str_contains($term, '@')) {
            return collect($exact && $exact->id !== $viewer->id ? [$exact] : []);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)) . '%';
        $candidates = User::with(['settings', 'profile', 'appId'])
            ->where('status', 'active')
            ->whereKeyNot($viewer->id)
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(username) LIKE ?', [$like])
                // A name, because that is what people type when looking for
                // a colleague. The typeahead on the same box already
                // matched names; the button beside it did not, so pressing
                // Enter found nobody the dropdown had just offered.
                ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereHas('appId', fn ($a) => $a->where('is_active', true)
                    ->whereRaw('LOWER(app_id) LIKE ?', [$like])))
            ->orderBy('username')
            ->limit($limit * 3)
            ->get()
            ->filter(fn (User $candidate) => $this->findVisibleUser(
                $candidate->username ?: ($candidate->appId?->app_id ?? ''), $viewer,
            ) !== null);

        return collect($exact && $exact->id !== $viewer->id ? [$exact] : [])
            ->concat($candidates)
            ->unique('id')
            ->take($limit)
            ->values();
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
     * Resolve any identity handle to a user: username, email, or App ID.
     * Mobile numbers are records-only and deliberately NOT resolvable.
     */
    public function lookup(string $identifier): ?User
    {
        $identifier = trim($identifier);

        // App ID — current prefix plus legacy ones (old accounts keep their IDs)
        $prefixes = array_merge(
            [config('mypa.app_id_prefix', 'NV')],
            config('mypa.app_id_legacy_prefixes', [])
        );
        $pattern = '/^(' . implode('|', array_map(fn ($p) => preg_quote($p, '/'), $prefixes)) . ')-/i';
        if (preg_match($pattern, $identifier)) {
            return AppId::with('user.settings', 'user.profile')
                ->where('app_id', strtoupper($identifier))
                ->where('is_active', true)
                ->first()?->user;
        }

        // Email
        if (str_contains($identifier, '@')) {
            return User::with(['settings', 'profile'])
                ->where('email', mb_strtolower($identifier))
                ->first();
        }

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
