<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One office asset for life: handed out, taken back, sent for repair,
 * written off — the row stays, the events tell the story.
 */
class Asset extends Model
{
    use HasUuids;

    protected $table = 'crm_assets';

    public const STATUSES = ['in_stock', 'allocated', 'damaged'];

    public const CATEGORIES = [
        'Laptop', 'Desktop', 'Mobile', 'SIM / Number', 'Laptop Charger', 'Phone Charger',
        'Keyboard', 'Mouse', 'Headset', 'Monitor', 'Bag', 'ID Card', 'Other',
    ];

    protected $fillable = [
        'organization_id', 'category', 'name', 'model_no', 'color', 'serial_no',
        'details', 'status', 'allocated_to_member_id', 'allocated_at',
        'purchased_on', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allocated_at' => 'datetime',
            'purchased_on' => 'date',
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

    public function holder(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'allocated_to_member_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class, 'asset_id')->latest('id');
    }
}
