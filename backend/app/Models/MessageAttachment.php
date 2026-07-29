<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageAttachment extends Model
{
    protected $fillable = ['message_id', 'name', 'path', 'mime_type', 'size', 'duration_seconds'];

    protected $hidden = ['path'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
