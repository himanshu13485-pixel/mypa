<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One warning already given about a work order running out.
 *
 * The row exists so the next morning's sweep knows not to say it again. Its
 * uniqueness — item, offset, audience, channel — is the whole rule: the same
 * work order may be mentioned three times, to two audiences, on two
 * channels, and never twice for the same reason.
 */
class RenewalReminder extends Model
{
    protected $table = 'crm_renewal_reminders';

    protected $fillable = [
        'organization_id', 'invoice_id', 'invoice_item_id', 'offset_days',
        'audience', 'channel', 'to_email', 'status', 'error', 'expires_on',
    ];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }
}
