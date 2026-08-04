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
        'spotlight_uuid', 'status', 'scheduled_at', 'started_at', 'ended_at',
    ];

    protected $hidden = ['passcode'];

    protected function casts(): array
    {
        return [
            'is_screen' => 'boolean',
            'requires_approval' => 'boolean',
            'is_locked' => 'boolean',
            'scheduled_at' => 'datetime',
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

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants')
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
