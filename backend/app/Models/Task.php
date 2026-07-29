<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id', 'category_id', 'parent_id', 'group_id', 'title', 'description', 'priority', 'status',
        'start_at', 'due_at', 'estimated_minutes', 'actual_minutes', 'progress',
        'location', 'contact_person', 'color', 'is_important', 'is_confidential',
        'is_favourite', 'is_pinned', 'repeat_config', 'completed_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'is_important' => 'boolean',
            'is_confidential' => 'boolean',
            'is_favourite' => 'boolean',
            'is_pinned' => 'boolean',
            'repeat_config' => 'array',
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

    // --- Relations -----------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignments')
            ->withPivot(['status', 'note', 'assigned_by'])
            ->withTimestamps();
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(TaskChecklist::class)->orderBy('sort_order');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(TaskReminder::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->whereNull('parent_id')->latest();
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(TaskActivityLog::class)->latest();
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    // --- Scopes --------------------------------------------------------------

    /** Tasks a user can see: own tasks + tasks assigned to them + their groups' tasks. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('assignees', fn ($a) => $a->where('users.id', $user->id))
                ->orWhereHas('group.members', fn ($m) => $m->where('users.id', $user->id));
        });
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled', 'archived']);
    }

    public function logActivity(?User $actor, string $action, array $changes = []): void
    {
        $this->activityLogs()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'changes' => $changes ?: null,
        ]);
    }
}
