<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Target extends Model
{
    protected $table = 'crm_targets';

    /** What a desk is judged on: the money it bills, or the clients it brings in. */
    public const KINDS = ['sales', 'clients'];

    protected $fillable = [
        'organization_id', 'member_id', 'year', 'month', 'target_amount',
        'kind', 'client_target', 'note', 'created_by',
    ];

    protected $attributes = ['kind' => 'sales'];

    protected function casts(): array
    {
        return ['target_amount' => 'decimal:2', 'client_target' => 'integer'];
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
