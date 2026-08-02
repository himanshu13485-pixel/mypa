<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'name', 'purpose', 'base_currency', 'notes', 'is_archived',
        'daily_report', 'report_format', 'last_reported_at',
        'password_hash', 'reset_code_hash', 'reset_code_expires_at',
    ];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'daily_report' => 'boolean', 'last_reported_at' => 'datetime', 'reset_code_expires_at' => 'datetime'];
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

    public function entries(): HasMany
    {
        return $this->hasMany(ProjectEntry::class);
    }

    public function sharedWith(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_shares')
            ->withPivot('permission')
            ->withTimestamps();
    }

    public function permissionFor(User $user): ?string
    {
        if ($this->user_id === $user->id) {
            return 'owner';
        }

        return $this->sharedWith()->where('users.id', $user->id)->first()?->pivot->permission;
    }
}
