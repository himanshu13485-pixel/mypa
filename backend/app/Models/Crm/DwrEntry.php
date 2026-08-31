<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DwrEntry extends Model
{
    protected $table = 'crm_dwr_entries';

    protected $fillable = [
        'dwr_id', 'parameter_id', 'name', 'unit', 'weightage', 'target', 'value', 'sort',
    ];

    protected function casts(): array
    {
        return ['target' => 'decimal:2', 'value' => 'decimal:2'];
    }

    public function dwr(): BelongsTo
    {
        return $this->belongsTo(Dwr::class, 'dwr_id');
    }

    /** 0..1 achievement of this entry against its snapshotted target. */
    public function achievement(): float
    {
        if ($this->unit === 'boolean') {
            return (float) $this->value >= 1 ? 1.0 : 0.0;
        }
        if ((float) $this->target <= 0) {
            return (float) $this->value > 0 ? 1.0 : 0.0;
        }

        return min(1.0, (float) $this->value / (float) $this->target);
    }
}
