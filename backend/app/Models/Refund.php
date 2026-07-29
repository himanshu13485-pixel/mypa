<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasUuids;

    protected $fillable = [
        'payment_id', 'gateway_refund_id', 'amount', 'reason', 'status',
        'requested_by', 'gateway_response', 'processed_at',
    ];

    protected $hidden = ['gateway_response'];

    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
