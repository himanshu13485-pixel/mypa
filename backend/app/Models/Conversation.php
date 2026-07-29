<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasUuids;

    protected $fillable = ['type', 'group_id', 'name', 'created_by', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
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

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_members')
            ->withPivot(['last_read_at', 'muted_at', 'archived_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn ($m) => $m->where('users.id', $user->id));
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('users.id', $user->id)->exists();
    }

    /** The other participant of a direct conversation. */
    public function otherMember(User $me): ?User
    {
        if ($this->type !== 'direct') {
            return null;
        }

        return $this->members->firstWhere(fn ($u) => $u->id !== $me->id)
            ?? $this->members()->where('users.id', '!=', $me->id)->first();
    }

    /** Find or create the direct conversation between two users. */
    public static function directBetween(User $a, User $b): self
    {
        $existing = self::where('type', 'direct')
            ->whereHas('members', fn ($m) => $m->where('users.id', $a->id))
            ->whereHas('members', fn ($m) => $m->where('users.id', $b->id))
            ->first();

        if ($existing) {
            return $existing;
        }

        $conversation = self::create(['type' => 'direct', 'created_by' => $a->id]);
        $conversation->members()->attach([$a->id, $b->id]);

        return $conversation;
    }
}
