<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    protected $fillable = [
        'gateway', 'event_id', 'event_type', 'dedupe_hash', 'payload',
        'signature_valid', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
