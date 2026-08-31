<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberKpi extends Model
{
    protected $table = 'crm_member_kpis';

    protected $fillable = ['member_id', 'parameter_id', 'weightage', 'daily_target', 'sort'];

    protected function casts(): array
    {
        return ['daily_target' => 'decimal:2'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(KpiParameter::class, 'parameter_id');
    }
}
