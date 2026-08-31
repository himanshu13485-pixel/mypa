<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = ['event', 'meeting', 'appointment', 'birthday', 'anniversary', 'holiday'];

    protected $fillable = [
        'user_id', 'group_id', 'title', 'description', 'type', 'starts_at', 'ends_at',
        'all_day', 'location', 'meeting_link', 'color', 'repeat_config', 'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
            'repeat_config' => 'array',
            'reminded_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_participants')
            ->withPivot('status')
            ->withTimestamps();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** Events a user can see: own + invited to + their groups' events. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('participants', fn ($p) => $p->where('users.id', $user->id))
                ->orWhereHas('group.members', fn ($m) => $m->where('users.id', $user->id));
        });
    }
}
