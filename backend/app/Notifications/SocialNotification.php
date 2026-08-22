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

    /**
     * $pushTag overrides how the device groups this alert. The default groups
     * by kind, which is right for the occasional share or invite: a second one
     * quietly replaces the first rather than stacking up. A chat message is
     * the opposite — every one of them is meant to arrive on its own, so the
     * caller passes a tag carrying the message's own id.
     */
    public function __construct(
        public string $kind,
        public string $message,
        public array $data = [],
        public ?string $actionPath = null,
        public ?string $pushTag = null,
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

        // A browser subscription or an installed Android app — either is a
        // device that can be pushed to. The channels sort out which is which:
        // each quietly skips a user with no devices of its kind.
        return ($prefs['push'] ?? true)
            && ($notifiable->pushSubscriptions()->exists() || $notifiable->fcmTokens()->exists());
    }

    /**
     * Kinds too frequent to email.
     *
     * Email is one message per notification with no way to collapse it, so a
     * busy chat would arrive as a busy inbox. These still reach the bell and
     * the device; they just do not land in the recipient's mail.
     */
    private const NEVER_MAIL = ['message'];

    public function via(object $notifiable): array
    {
        $mail = self::wantsMail($notifiable) && ! in_array($this->kind, self::NEVER_MAIL, true);
        $via = $mail ? ['database', 'mail'] : ['database'];

        if (self::wantsPush($notifiable)) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
            $via[] = \App\Notifications\Channels\FcmChannel::class;
        }

        return $via;
    }

    public function toPush(object $notifiable): array
    {
        return [
            'title' => 'My PA',
            'body' => $this->message,
            'tag' => $this->pushTag ?? 'social-' . $this->kind,
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
