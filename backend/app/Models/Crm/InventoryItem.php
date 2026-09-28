<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing a company sells: a product it counts, or a service it does not.
 *
 * It belongs to an issuing company rather than to the organisation, because
 * a domestic arm and an export arm sell different things out of different
 * rooms, and a count that spanned both would be a count of neither.
 *
 * A service has no quantity at all - null, not zero. Nobody has three
 * consultations left in a drawer, and a zero on the screen would read as
 * "out of stock" for something that can never run out.
 */
class InventoryItem extends Model
{
    use HasUuids;

    protected $table = 'crm_inventory_items';

    public const KINDS = ['product', 'service'];

    protected $fillable = [
        'organization_id', 'issuing_company_id', 'name', 'code', 'kind', 'unit',
        'description', 'unit_price', 'tax_rate', 'quantity', 'reorder_at',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:3',
            'quantity' => 'decimal:3',
            'reorder_at' => 'decimal:3',
            'is_active' => 'boolean',
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

    public function issuingCompany(): BelongsTo
    {
        return $this->belongsTo(IssuingCompany::class, 'issuing_company_id');
    }

    public function moves(): HasMany
    {
        return $this->hasMany(InventoryMove::class, 'inventory_item_id');
    }

    /** A service is not counted; only a product has a number worth reading. */
    public function isCounted(): bool
    {
        return $this->kind === 'product';
    }

    /**
     * Low enough to be worth saying so.
     *
     * Only where somebody set a level to compare against - an item with no
     * reorder level is not "fine", it is simply not being watched, and
     * saying otherwise would be inventing an opinion nobody asked for.
     */
    public function isLow(): bool
    {
        return $this->isCounted()
            && $this->reorder_at !== null
            && (float) $this->quantity <= (float) $this->reorder_at;
    }
}
