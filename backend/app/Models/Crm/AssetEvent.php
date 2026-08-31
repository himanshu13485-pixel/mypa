<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an asset's life: allocated, returned, damaged, repaired. */
class AssetEvent extends Model
{
    protected $table = 'crm_asset_events';

    protected $fillable = ['asset_id', 'action', 'member_id', 'note', 'created_by'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
