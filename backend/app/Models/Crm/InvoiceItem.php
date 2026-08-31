<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $table = 'crm_invoice_items';

    protected $fillable = [
        'invoice_id', 'membership', 'plan_name', 'description', 'custom_fields',
        'validity_from', 'validity_to', 'qty', 'unit_price', 'amount', 'amount_fx', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'validity_from' => 'date',
            'validity_to' => 'date',
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'amount_fx' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
