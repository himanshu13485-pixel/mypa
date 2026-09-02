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

    /** What a group's link does when somebody follows it. */
    public const INVITE_MODES = ['open', 'request'];

    protected $fillable = [
        'owner_id', 'name', 'type', 'description', 'icon', 'color', 'only_admins_post',
        'invite_token', 'invite_mode',
    ];

    /*
     * The table's default, said again here.
     *
     * A column default is applied by the database on insert and never read
     * back into a model that was built in memory, so a group made and asked
     * about in the same request would answer null — and null is neither of
     * the two modes.
     */
    protected $attributes = [
        'invite_mode' => 'request',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return ['only_admins_post' => 'boolean'];
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

    public function joinRequests(): HasMany
    {
        return $this->hasMany(GroupJoinRequest::class);
    }

    /**
     * A fresh link, replacing whatever was there.
     *
     * Rotating is how a link is taken back: the old one stops resolving the
     * moment this runs, which is the only honest meaning of revoking a URL
     * that has already been forwarded to people you cannot name.
     */
    public function rotateInviteToken(): string
    {
        do {
            $token = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(24));
        } while (static::where('invite_token', $token)->exists());

        $this->update(['invite_token' => $token]);

        return $token;
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
