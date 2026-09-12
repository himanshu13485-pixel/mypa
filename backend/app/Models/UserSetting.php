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

    /**
     * Everything on, for anyone who has not said otherwise.
     *
     * This was already the behaviour, but only as a `?? true` repeated at
     * each of the four places that read a preference — which is the kind of
     * default that quietly stops being the default the first time somebody
     * writes `?? false` in a fifth place. Naming it once means the answer to
     * "is this on for a new account?" is in one readable line, and adding a
     * preference later means adding it here rather than remembering to.
     *
     * Deliberately on rather than off: a notification nobody asked for can
     * be turned off in a moment, and one that never arrived is invisible.
     */
    public const DEFAULT_NOTIFICATIONS = [
        'email' => true,
        'push' => true,
    ];

    /*
     * The table's defaults, stated on the model too.
     *
     * A column default is applied by the database on insert and never read
     * back, so a row made by firstOrCreate() or create([]) hands back null for
     * every one of these until something reloads it. That is not theoretical:
     * it is exactly how the booking page came to show the first option of each
     * dropdown instead of its real defaults, on the one load where it mattered.
     *
     * Nothing reads these three from the API yet, so this is insurance rather than a repair —
     * but the trap is invisible until somebody does, and by then it looks like
     * a bug in whatever they wrote.
     */
    protected $attributes = [
        'theme' => 'system',
        'compact_mode' => false,
        'default_task_view' => 'list',
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

    /**
     * Whether a notification channel is on for this person.
     *
     * Absent means on — the row is created empty at registration and only
     * gains keys when somebody changes something, so "not mentioned" and
     * "never touched" are the same state and both mean the default.
     */
    public function notificationValue(string $key): bool
    {
        return (bool) ($this->notification_preferences[$key]
            ?? self::DEFAULT_NOTIFICATIONS[$key]
            ?? true);
    }

    /**
     * Whether one menu's notifications may use one channel.
     *
     * Two answers, not one: somebody who stops the payment e-mails usually
     * still wants the bell to say a payment landed. Absent means on, like
     * every other preference here - a menu nobody has touched is a menu
     * nobody has switched off.
     *
     * The app-wide switch still wins. Turning e-mail off entirely and then
     * finding one menu still writing to you would be a setting that lied.
     */
    public function topicAllows(string $topic, string $channel): bool
    {
        if (! $this->notificationValue($channel === 'email' ? 'email' : 'push')) {
            return false;
        }

        $topics = $this->notification_preferences['topics'] ?? [];

        return (bool) ($topics[$topic][$channel] ?? true);
    }

    /** What every menu is set to, for the screen that shows them. */
    public function topicPreferences(): array
    {
        $saved = (array) ($this->notification_preferences['topics'] ?? []);

        return collect(\App\Support\NotificationTopics::TOPICS)
            ->map(fn ($_, string $key) => [
                'email' => (bool) ($saved[$key]['email'] ?? true),
                'app' => (bool) ($saved[$key]['app'] ?? true),
            ])
            ->all();
    }

    public function privacyValue(string $key): string
    {
        return $this->privacy[$key] ?? self::DEFAULT_PRIVACY[$key] ?? 'everyone';
    }
}
