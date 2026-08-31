<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dwr extends Model
{
    use HasUuids;

    protected $table = 'crm_dwrs';

    public const BANDS = ['outstanding', 'good', 'needs_improvement', 'pip'];

    protected $fillable = [
        'organization_id', 'member_id', 'work_date', 'note', 'score', 'band', 'created_by',
    ];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'score' => 'decimal:1'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(DwrEntry::class, 'dwr_id')->orderBy('sort');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The old CRM's four performance labels, from the weighted score. */
    public static function bandFor(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return match (true) {
            $score >= 90 => 'outstanding',
            $score >= 70 => 'good',
            $score >= 50 => 'needs_improvement',
            default => 'pip',
        };
    }
}
