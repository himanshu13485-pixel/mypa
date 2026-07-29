<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskReminder extends Model
{
    protected $fillable = [
        'task_id', 'user_id', 'remind_at', 'offset_minutes', 'channels',
        'repeat_until_acknowledged', 'snoozed_until', 'acknowledged_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'acknowledged_at' => 'datetime',
            'sent_at' => 'datetime',
            'channels' => 'array',
            'repeat_until_acknowledged' => 'boolean',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
