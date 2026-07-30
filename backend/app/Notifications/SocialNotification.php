<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Cross-user activity notifications (connection requests/acceptance, task
 * assignments, group invites, shares). Email rides along only when the
 * recipient's address is VERIFIED and their email preference is on.
 */
class SocialNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,
        public string $message,
        public array $data = [],
        public ?string $actionPath = null,
    ) {
    }

    public static function wantsMail(object $notifiable): bool
    {
        $prefs = $notifiable->settings?->notification_preferences ?? [];

        return $notifiable->email !== null
            && $notifiable->email_verified_at !== null
            && ($prefs['email'] ?? true);
    }

    /** Push goes out whenever the user has subscribed a device (pref 'push' can disable). */
    public static function wantsPush(object $notifiable): bool
    {
        $prefs = $notifiable->settings?->notification_preferences ?? [];

        return ($prefs['push'] ?? true)
            && $notifiable->pushSubscriptions()->exists();
    }

    public function via(object $notifiable): array
    {
        $via = self::wantsMail($notifiable) ? ['database', 'mail'] : ['database'];

        if (self::wantsPush($notifiable)) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
        }

        return $via;
    }

    public function toPush(object $notifiable): array
    {
        return [
            'title' => 'My PA',
            'body' => $this->message,
            'tag' => 'social-' . $this->kind,
            'url' => $this->actionPath ?? '/',
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        return array_merge($this->data, [
            'kind' => $this->kind,
            'message' => $this->message,
            'action_path' => $this->actionPath,
        ]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('My PA — ' . str($this->kind)->replace('_', ' ')->title())
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($this->message);

        if ($this->actionPath) {
            $mail->action('Open My PA', config('mypa.frontend_url') . $this->actionPath);
        }

        return $mail;
    }
}
