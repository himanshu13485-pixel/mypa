<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'title', 'description', 'discount_type', 'discount_value',
        'max_discount_amount', 'min_order_amount', 'applicable_plans',
        'applicable_frequencies', 'starts_at', 'expires_at', 'max_uses',
        'per_user_limit', 'new_users_only', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'applicable_plans' => 'array',
            'applicable_frequencies' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'new_users_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }
}
