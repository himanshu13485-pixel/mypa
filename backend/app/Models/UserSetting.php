<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    public const DEFAULT_PRIVACY = [
        'who_can_find_me' => 'everyone',        // everyone | connections | nobody
        'who_can_connect' => 'everyone',
        'who_can_message' => 'connections',
        'who_can_call' => 'connections',
        'profile_photo_visibility' => 'everyone',
        'online_status_visibility' => 'connections',
        'last_seen_visibility' => 'connections',
    ];

    protected $fillable = [
        'user_id', 'theme', 'compact_mode', 'default_task_view',
        'dashboard_layout', 'notification_preferences', 'privacy',
    ];

    protected function casts(): array
    {
        return [
            'compact_mode' => 'boolean',
            'dashboard_layout' => 'array',
            'notification_preferences' => 'array',
            'privacy' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function privacyValue(string $key): string
    {
        return $this->privacy[$key] ?? self::DEFAULT_PRIVACY[$key] ?? 'everyone';
    }
}
