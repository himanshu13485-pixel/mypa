<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id', 'group_id', 'title', 'body', 'type', 'checklist',
        'color', 'is_pinned', 'password_hash',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'is_pinned' => 'boolean',
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

    public function versions(): HasMany
    {
        return $this->hasMany(NoteVersion::class)->latest();
    }

    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'note_users')
            ->withPivot('permission')
            ->withTimestamps();
    }

    public function isLocked(): bool
    {
        return $this->password_hash !== null;
    }

    /** Notes a user can see: own + shared with them + their groups' notes. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $user->id))
                ->orWhereHas('group.members', fn ($m) => $m->where('users.id', $user->id));
        });
    }

    public function canEdit(User $user): bool
    {
        if ($this->user_id === $user->id) {
            return true;
        }

        return $this->sharedWith()->where('users.id', $user->id)
            ->wherePivot('permission', 'edit')->exists();
    }
}
