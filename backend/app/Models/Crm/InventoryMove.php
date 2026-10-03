<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a count, and what caused it.
 *
 * The number on the item is the running total; these are the reasons it
 * reads the way it does. Kept because a count with no history is a number
 * nobody can argue with and nobody can trust: "we are four short" needs an
 * answer, and the answer is in here.
 *
 * Signed, in the direction stock actually moves: out of the room is
 * negative, back into it positive.
 */
class InventoryMove extends Model
{
    protected $table = 'crm_inventory_moves';

    /** Why the count changed. An invoice does it by itself; the rest are typed. */
    public const REASONS = ['invoice', 'purchase', 'adjustment', 'return', 'damage'];

    protected $fillable = [
        'organization_id', 'inventory_item_id', 'invoice_id',
        'qty', 'reason', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
