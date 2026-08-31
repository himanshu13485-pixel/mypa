<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceUpdateRequest extends Model
{
    use HasUuids;

    protected $table = 'crm_invoice_update_requests';

    /** Header fields a request may propose to change. */
    public const EDITABLE = [
        'invoice_date', 'due_date', 'terms_of_payment', 'client_category',
        'pricing_tier', 'subscription_type', 'dispatch_status',
        'payment_status', 'notes',
    ];

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'organization_id', 'invoice_id', 'changes', 'reason', 'requested_by',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'decided_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'decided_by');
    }
}
