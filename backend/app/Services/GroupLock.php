<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The password on a group, and who has just given it.
 *
 * Not the same thing as the chat password in ChatLock. That one belongs to
 * a person and locks their own copy of a chat; this one belongs to the
 * group and locks it for everybody in it, including the admins who set it.
 * A member can be subject to both at once, and each is answered separately.
 *
 * Giving it opens that one group for a quarter of an hour, kept alive by
 * use, and the proof is a random token held only in the app's memory - so
 * closing the app locks the group again. Checked on the server, because a
 * group that only looked locked would hand its messages to anybody who
 * opened the network tab.
 */
class GroupLock
{
    /** How long one unlock lasts without being used. */
    public const WINDOW_MINUTES = 15;

    public const HEADER = 'X-Group-Unlock';

    public function has(?Group $group): bool
    {
        return $group !== null && filled($group->chat_password_hash);
    }

    public function matches(Group $group, ?string $password): bool
    {
        return $this->has($group) && is_string($password) && Hash::check($password, $group->chat_password_hash);
    }

    /** The first password, or a new one in place of the old. */
    public function set(Group $group, string $password, User $by): void
    {
        $group->forceFill([
            'chat_password_hash' => Hash::make($password),
            'chat_password_set_at' => now(),
            'chat_password_set_by' => $by->id,
        ])->save();

        // A new password shuts every door the old one opened, for everybody.
        $this->forgetAll($group);
    }

    public function remove(Group $group): void
    {
        $group->forceFill([
            'chat_password_hash' => null,
            'chat_password_set_at' => null,
            'chat_password_set_by' => null,
        ])->save();

        $this->forgetAll($group);
    }

    /** Issue the proof that the password was just given. */
    public function open(User $user, Group $group): string
    {
        $token = Str::random(48);
        $digest = hash('sha256', $token);

        Cache::put($this->key($group, $user, $digest), true, now()->addMinutes(self::WINDOW_MINUTES));
        Cache::put(
            $this->listKey($group),
            array_values(array_unique(array_merge(Cache::get($this->listKey($group), []), [$user->id . ':' . $digest]))),
            now()->addDay(),
        );

        return $token;
    }

    /** Is this token a live unlock for this person and this group? Using it keeps it alive. */
    public function isOpen(User $user, Group $group, ?string $token): bool
    {
        if (! $token || ! $this->has($group)) {
            return false;
        }

        $key = $this->key($group, $user, hash('sha256', $token));
        if (! Cache::has($key)) {
            return false;
        }

        Cache::put($key, true, now()->addMinutes(self::WINDOW_MINUTES));

        return true;
    }

    /** Every window anybody has open on this group, closed. */
    public function forgetAll(Group $group): void
    {
        foreach (Cache::pull($this->listKey($group), []) as $entry) {
            [$userId, $digest] = array_pad(explode(':', (string) $entry, 2), 2, '');
            Cache::forget("group-unlock:{$group->id}:{$userId}:{$digest}");
        }
    }

    private function key(Group $group, User $user, string $digest): string
    {
        return "group-unlock:{$group->id}:{$user->id}:{$digest}";
    }

    private function listKey(Group $group): string
    {
        return "group-unlock-list:{$group->id}";
    }
}
