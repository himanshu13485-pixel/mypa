<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One chase against one invoice: an e-mail that went out, or a note. */
class PaymentReminder extends Model
{
    use HasUuids;

    protected $table = 'crm_payment_reminders';

    protected $attributes = ['channel' => 'email', 'status' => 'sent'];

    protected $fillable = [
        'organization_id', 'invoice_id', 'member_id', 'channel', 'is_auto', 'to_email',
        'subject', 'body', 'status', 'error', 'balance', 'next_follow_up', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_auto' => 'boolean',
            'balance' => 'decimal:2',
            'next_follow_up' => 'date',
            'sent_at' => 'datetime',
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

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
