<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One "pay this online" link, raised against one document for one amount. */
class PaymentLink extends Model
{
    use HasUuids;

    protected $table = 'crm_payment_links';

    protected $attributes = ['provider' => 'cashfree', 'status' => 'active', 'currency' => 'INR'];

    protected $fillable = [
        'organization_id', 'invoice_id', 'provider', 'link_id', 'cf_link_id',
        'link_url', 'amount', 'amount_paid', 'currency', 'purpose', 'status',
        'expires_at', 'paid_at', 'last_event', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_event' => 'array',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Still worth sending to a client. */
    public function isOpen(): bool
    {
        return $this->status === 'active'
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
