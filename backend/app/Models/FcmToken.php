<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One installed Android app that can be rung. See the migration for why. */
class FcmToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
