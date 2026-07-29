<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends Model
{
    use HasUuids, SoftDeletes;

    public const TYPES = ['family', 'team', 'business', 'other'];
    public const ROLES = ['owner', 'admin', 'manager', 'member', 'viewer'];
    /** Roles allowed to manage members and edit the group. */
    public const MANAGER_ROLES = ['owner', 'admin'];

    protected $fillable = ['owner_id', 'name', 'type', 'description', 'icon', 'color'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot(['role', 'added_by'])
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function scopeWithMember(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn ($m) => $m->where('users.id', $user->id));
    }

    public function roleOf(User $user): ?string
    {
        return $this->members()->where('users.id', $user->id)->first()?->pivot->role;
    }

    public function canManage(User $user): bool
    {
        return in_array($this->roleOf($user), self::MANAGER_ROLES, true);
    }
}
