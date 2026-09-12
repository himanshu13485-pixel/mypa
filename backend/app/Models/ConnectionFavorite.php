<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person starring another. One-sided on purpose: Asha keeping Bala at the
 * top of her list says nothing about where Asha sits in Bala's.
 */
class ConnectionFavorite extends Model
{
    protected $fillable = ['user_id', 'favorite_user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function favorite(): BelongsTo
    {
        return $this->belongsTo(User::class, 'favorite_user_id');
    }
}
