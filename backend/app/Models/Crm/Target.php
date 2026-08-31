<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Target extends Model
{
    protected $table = 'crm_targets';

    protected $fillable = [
        'organization_id', 'member_id', 'year', 'month', 'target_amount', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['target_amount' => 'decimal:2'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
