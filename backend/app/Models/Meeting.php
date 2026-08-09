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

    /**
     * Was this meeting ever meant to happen at a particular time?
     *
     * The status column defaults to 'scheduled' the moment a row exists, which
     * made every meeting anyone created — including one press of the instant
     * "New meeting" button that was then backed out of — sit in the list
     * labelled Scheduled, as if a time had been set for it. Nothing had been
     * scheduled at all. This tells the two apart so the list can say which.
     */
    public function wasNeverStarted(): bool
    {
        return $this->status === 'scheduled' && $this->scheduled_at === null && $this->started_at === null;
    }

    /**
     * A meeting nobody used and nobody will: made by the instant button, given
     * no title, no time, no password, and never joined. The reaper deletes
     * these after a day so the list is what you meant to keep, not a log of
     * every button press. Anything with a title or a time is deliberate and is
     * never touched.
     */
    public function scopeAbandoned($query, \Illuminate\Support\Carbon $before)
    {
        return $query->where('status', 'scheduled')
            ->whereNull('scheduled_at')
            ->whereNull('started_at')
            ->whereNull('title')
            ->whereNull('passcode')
            ->where('created_at', '<', $before)
            ->whereDoesntHave('participants');
    }

    public function host(): BelongsTo
    {
        /*
         * Asks for hidden users back, as the participants relation does.
         *
         * Nothing should make a guest the host any more — both hand-overs
         * refuse it — but meetings that already had one became unreadable
         * rather than merely odd: the relation resolved to null behind the
         * global scope and every read of that meeting died on it, taking the
         * whole meetings list with it. A host is worth resolving whoever it
         * turned out to be.
         */
        return $this->belongsTo(User::class, 'host_id')
            ->withoutGlobalScope('withoutMeetingGuests');
    }

    /**
     * Files shared in the meeting chat. The rows cascade when the meeting goes;
     * the bytes on disk do not, so deleting a meeting has to remove them itself.
     */
    public function files(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MeetingFile::class);
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

        $role = $this->participants()->where('users.id', $user->id)->first()?->pivot->role ?? 'participant';

        /*
         * host_id is the only thing that makes a host.
         *
         * A participant row can still read 'host' from before the meeting
         * changed hands, and taking its word for it put two hosts in the room:
         * one because the meeting says so, one because an old row does. The
         * roster has to agree with this — see MeetingController::rosterEntry.
         */
        return $role === 'host' ? 'cohost' : $role;
    }
}
