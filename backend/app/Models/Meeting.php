<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Meeting extends Model
{
    use HasUuids;

    /** Presence grace: a participant silent for this long is treated as gone. */
    public const PRESENCE_TIMEOUT_SECONDS = 45;

    protected $fillable = [
        'host_id', 'code', 'title', 'type', 'is_screen', 'requires_approval', 'passcode', 'is_locked',
        'spotlight_uuid', 'status', 'scheduled_at', 'reminded_at', 'started_at', 'ended_at',
    ];

    protected $hidden = ['passcode'];

    protected function casts(): array
    {
        return [
            'is_screen' => 'boolean',
            'requires_approval' => 'boolean',
            'is_locked' => 'boolean',
            'scheduled_at' => 'datetime',
            'reminded_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Meetings are addressed by their shareable code. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** Meet-style join code: xxx-xxxx-xxx (unambiguous lowercase letters). */
    public static function generateCode(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz';
        $part = fn (int $len) => collect(range(1, $len))
            ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
            ->implode('');

        do {
            $code = $part(3) . '-' . $part(4) . '-' . $part(3);
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * Whether somebody without an account can get in.
     *
     * The password is the only switch: it is what a guest types instead of
     * signing in, so a meeting without one has nothing to check them against
     * and admits members only.
     */
    public function allowsGuests(): bool
    {
        return (bool) $this->passcode;
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function participants(): BelongsToMany
    {
        // Guests are hidden from ordinary user queries by a global scope on
        // User. The room is the one place they must be visible, so it asks
        // for them back explicitly.
        return $this->belongsToMany(User::class, 'meeting_participants')
            ->withoutGlobalScope('withoutMeetingGuests')
            ->withPivot([
                'status', 'display_name', 'role', 'mic_on', 'cam_on', 'hand_raised',
                'joined_at', 'left_at', 'last_seen_at',
            ])
            ->withTimestamps();
    }

    /** Everyone currently in the room (excluding one user when asked). */
    public function inRoom(?int $exceptUserId = null): \Illuminate\Support\Collection
    {
        return $this->participants()
            ->wherePivot('status', 'joined')
            // The avatar comes back with the row rather than one query per
            // person: this runs on every heartbeat, for everyone in the room.
            ->with('profile:user_id,avatar')
            ->when($exceptUserId, fn ($q) => $q->where('users.id', '!=', $exceptUserId))
            ->get();
    }

    /** The host and any co-host they promoted share the moderation controls. */
    public function canModerate(User $user): bool
    {
        if ($this->host_id === $user->id) {
            return true;
        }

        return $this->participants()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'cohost')
            ->exists();
    }

    public function roleFor(User $user): string
    {
        if ($this->host_id === $user->id) {
            return 'host';
        }

        return $this->participants()->where('users.id', $user->id)->first()?->pivot->role ?? 'participant';
    }
}
