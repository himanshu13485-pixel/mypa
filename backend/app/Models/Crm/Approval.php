<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasUuids;

    protected $table = 'crm_approvals';

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'organization_id', 'type', 'scope', 'client_id', 'approval_date', 'issuing_company_id',
        'invoice_id', 'amount', 'details', 'requested_by', 'status',
        'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'approval_date' => 'date',
            'amount' => 'decimal:2',
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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'decided_by');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function issuingCompany(): BelongsTo
    {
        return $this->belongsTo(IssuingCompany::class, 'issuing_company_id');
    }
}
