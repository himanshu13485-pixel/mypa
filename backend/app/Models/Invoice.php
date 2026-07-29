<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_number', 'user_id', 'payment_id', 'plan_name', 'billing_frequency',
        'period_starts_on', 'period_ends_on', 'base_amount', 'discount_amount',
        'tax_amount', 'total_amount', 'currency', 'tax_label', 'tax_percent_bp',
        'billing_snapshot', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'billing_snapshot' => 'array',
            'issued_at' => 'datetime',
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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
