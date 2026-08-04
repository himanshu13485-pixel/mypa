<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Call extends Model
{
    use HasUuids;

    protected $fillable = [
        'conversation_id', 'caller_id', 'type', 'status',
        'started_at', 'answered_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    /** A participant silent for this long is treated as gone. */
    public const PRESENCE_TIMEOUT_SECONDS = 45;

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'call_participants')
            ->withPivot(['status', 'joined_at', 'left_at', 'last_seen_at'])
            ->withTimestamps();
    }

    /** Everyone currently in the call (optionally excluding one person). */
    public function inCall(?int $exceptUserId = null): \Illuminate\Support\Collection
    {
        return $this->participants()
            ->wherePivot('status', 'joined')
            ->when($exceptUserId, fn ($q) => $q->where('users.id', '!=', $exceptUserId))
            ->get();
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['ringing', 'ongoing'], true);
    }

    public function durationSeconds(): ?int
    {
        if (! $this->answered_at || ! $this->ended_at) {
            return null;
        }

        return (int) $this->answered_at->diffInSeconds($this->ended_at);
    }
}
