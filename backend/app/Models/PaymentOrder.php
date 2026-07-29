<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_number', 'user_id', 'plan_id', 'billing_frequency',
        'base_amount', 'discount_amount', 'tax_amount', 'total_amount', 'currency',
        'coupon_id', 'status', 'gateway_order_id', 'payment_session_id',
        'idempotency_key', 'customer_snapshot', 'gateway_response',
        'expires_at', 'paid_at',
    ];

    protected $hidden = ['gateway_response', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'customer_snapshot' => 'array',
            'gateway_response' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
