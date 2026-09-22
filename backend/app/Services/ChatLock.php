<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The password behind locked and hidden chats.
 *
 * Entering it once opens all of them for a quarter of an hour, and every
 * request that touches one of them keeps that window open - reading a
 * locked chat for twenty minutes should not be interrupted at fifteen.
 * Leaving them alone lets it close.
 *
 * What proves it was entered is a random token held only in the app's
 * memory, never in storage: reloading the page, or closing the app, locks
 * everything again. That is the point. The lock is for the phone left on a
 * desk, and a lock that survives a refresh does not protect against the
 * person who picks it up.
 *
 * Checked on the server, not only on the screen. A chat that only looked
 * locked would give its messages to anybody who opened the network tab.
 */
class ChatLock
{
    /** How long one unlock lasts without being used. */
    public const WINDOW_MINUTES = 15;

    public const HEADER = 'X-Chat-Unlock';

    public function has(User $user): bool
    {
        return filled($user->chat_lock_hash);
    }

    public function matches(User $user, ?string $password): bool
    {
        return $this->has($user) && is_string($password) && Hash::check($password, $user->chat_lock_hash);
    }

    public function set(User $user, string $password): void
    {
        $user->forceFill([
            'chat_lock_hash' => Hash::make($password),
            'chat_lock_set_at' => now(),
        ])->save();

        // A new password ends every window the old one opened.
        $this->forget($user);
    }

    /**
     * Take the password away - and with it, every lock and every hiding
     * place, since nothing would be left that could open them.
     */
    public function remove(User $user): void
    {
        $user->forceFill(['chat_lock_hash' => null, 'chat_lock_set_at' => null])->save();

        \Illuminate\Support\Facades\DB::table('conversation_members')
            ->where('user_id', $user->id)
            ->update(['locked_at' => null, 'hidden_at' => null]);

        $this->forget($user);
    }

    /** Issue the proof that the password was just entered. */
    public function open(User $user): string
    {
        $token = Str::random(48);
        $digest = hash('sha256', $token);
        Cache::put($this->key($user, $digest), true, now()->addMinutes(self::WINDOW_MINUTES));
        Cache::put($this->listKey($user), array_merge(Cache::get($this->listKey($user), []), [$digest]), now()->addDay());

        return $token;
    }

    /**
     * Is this token a live unlock for this person? Using it keeps it alive.
     */
    public function isOpen(User $user, ?string $token): bool
    {
        if (! $token || ! $this->has($user)) {
            return false;
        }

        $key = $this->key($user, hash('sha256', $token));
        if (! Cache::has($key)) {
            return false;
        }

        Cache::put($key, true, now()->addMinutes(self::WINDOW_MINUTES));

        return true;
    }

    /** Is this chat, for this person, behind the password? */
    public function sealed(User $user, Conversation $conversation): bool
    {
        $mine = \Illuminate\Support\Facades\DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first(['locked_at', 'hidden_at']);

        return $mine && ($mine->locked_at !== null || $mine->hidden_at !== null);
    }

    /** Every window this person has open, closed. */
    public function forget(User $user): void
    {
        foreach (Cache::pull($this->listKey($user), []) as $digest) {
            Cache::forget($this->key($user, $digest));
        }
    }

    /**
     * The conversation ids this person has locked or hidden, for the reads
     * that have to leave them out while the password has not been given.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function sealedIds(User $user): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('conversation_members')
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNotNull('locked_at')->orWhereNotNull('hidden_at'))
            ->pluck('conversation_id');
    }

    /** @return \Illuminate\Support\Collection<int, int> */
    public function hiddenIds(User $user): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('conversation_members')
            ->where('user_id', $user->id)
            ->whereNotNull('hidden_at')
            ->pluck('conversation_id');
    }

    /** Keyed by the token's hash, so the cache never holds a token itself. */
    private function key(User $user, string $digest): string
    {
        return 'chat-unlock:' . $user->id . ':' . $digest;
    }

    private function listKey(User $user): string
    {
        return 'chat-unlock-list:' . $user->id;
    }
}
