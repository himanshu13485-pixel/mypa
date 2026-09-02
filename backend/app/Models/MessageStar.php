<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person keeping one message.
 *
 * Private by design: starring is how somebody files away an address or a
 * number they will need again, and it would stop being useful the moment the
 * other side could see they had done it. Pinning is the public one.
 */
class MessageStar extends Model
{
    protected $fillable = ['message_id', 'user_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
