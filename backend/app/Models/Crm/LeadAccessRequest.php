<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "This lead already exists — let me in on it", awaiting the Admin. */
class LeadAccessRequest extends Model
{
    use HasUuids;

    protected $table = 'crm_lead_access_requests';

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'organization_id', 'lead_id', 'member_id', 'note', 'status',
        'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
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
