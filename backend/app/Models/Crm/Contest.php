<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contest extends Model
{
    use HasUuids;

    protected $table = 'crm_contests';

    public const STATUSES = ['draft', 'published', 'closed'];

    protected $fillable = [
        'organization_id', 'title', 'description', 'starts_at', 'ends_at', 'status',
        'audience_department', 'audience_member_id', 'created_by',
    ];

    /** May this member play — is the contest aimed at them? */
    public function isFor(\App\Models\Crm\Member $member): bool
    {
        if ($this->audience_member_id !== null) {
            return $this->audience_member_id === $member->id;
        }
        if ($this->audience_department !== null) {
            return strcasecmp((string) $member->department, $this->audience_department) === 0;
        }

        return true;   // for all, by default
    }

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ContestQuestion::class, 'contest_id')->orderBy('sort');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** live | upcoming | ended | draft | closed — what the player experiences. */
    public function phase(): string
    {
        if ($this->status === 'draft') {
            return 'draft';
        }
        if ($this->status === 'closed' || $this->ends_at->isPast()) {
            return 'ended';
        }

        return $this->starts_at->isFuture() ? 'upcoming' : 'live';
    }
}
