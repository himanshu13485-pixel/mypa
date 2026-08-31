<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This client already exists — may I have access?" Raised automatically
 * when somebody tries to add a client another portfolio already holds.
 */
class ClientAccessRequest extends Model
{
    use HasUuids;

    protected $table = 'crm_client_access_requests';

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'organization_id', 'client_id', 'member_id', 'note', 'status',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
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
