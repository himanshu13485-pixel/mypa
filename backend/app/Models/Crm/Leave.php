<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    use HasUuids;

    protected $table = 'crm_leaves';

    /**
     * A day, or half of one. There is no quarter day: the office does not
     * work in quarters, and a unit nobody can observe is a unit nobody can
     * argue about later.
     */
    public const DURATIONS = ['full' => 1.0, 'half' => 0.5];
    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    protected $attributes = ['status' => 'pending', 'duration' => 'full'];

    protected $fillable = [
        'organization_id', 'member_id', 'category', 'duration', 'date_from',
        'date_to', 'days', 'paid_days', 'unpaid_days', 'reason', 'status', 'decided_by', 'decided_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'days' => 'decimal:2',
            'paid_days' => 'decimal:2',
            'unpaid_days' => 'decimal:2',
            'decided_at' => 'datetime',
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

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'decided_by');
    }
}
