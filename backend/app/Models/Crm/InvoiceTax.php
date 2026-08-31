<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One money line as it stood on one document: a company may charge two taxes
 * or five, so these are rows rather than columns. The label and kind are
 * snapshots — renaming the line later never rewrites old paperwork.
 */
class InvoiceTax extends Model
{
    protected $table = 'crm_invoice_taxes';

    protected $fillable = ['invoice_id', 'key', 'label', 'kind', 'basis', 'rate', 'amount', 'sort'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:3', 'amount' => 'decimal:2'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
