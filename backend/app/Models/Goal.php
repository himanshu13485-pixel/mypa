<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goal extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = ['personal', 'family', 'work', 'health', 'financial'];

    protected $fillable = [
        'user_id', 'group_id', 'title', 'description', 'type', 'target_date',
        'status', 'progress', 'motivation', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'completed_at' => 'datetime',
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

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(GoalMilestone::class)->orderBy('sort_order');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('group.members', fn ($m) => $m->where('users.id', $user->id));
        });
    }

    /** Progress derived from milestones when any exist, else the manual value. */
    public function computedProgress(): int
    {
        $total = $this->milestones->count();
        if ($total === 0) {
            return (int) $this->progress;
        }

        return (int) round($this->milestones->where('is_done', true)->count() / $total * 100);
    }
}
