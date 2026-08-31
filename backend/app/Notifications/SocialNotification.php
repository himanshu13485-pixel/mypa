<?php

namespace App\Notifications;

use App\Notifications\Concerns\BroadcastsTheStoredRow;
use App\Support\Alerts;
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
    use BroadcastsTheStoredRow;
    use Queueable;

    /**
     * $pushTag overrides how the device groups this alert. The default groups
     * by kind, which is right for the occasional share or invite: a second one
     * quietly replaces the first rather than stacking up. A chat message is
     * the opposite — every one of them is meant to arrive on its own, so the
     * caller passes a tag carrying the message's own id.
     *
     * $pushTitle overrides the bold first line on the device. Most callers
     * should leave it alone: the kind already implies a title through
     * Alerts, and a title chosen per call site is how a hundred notifications
     * end up saying a hundred slightly different things.
     */
    public function __construct(
        public string $kind,
        public string $message,
        public array $data = [],
        public ?string $actionPath = null,
        public ?string $pushTag = null,
        public ?string $pushTitle = null,
    ) {
    }

    /**
     * What every notification in this app gets, before preferences.
     *
     * 'database' is the row the bell reads. 'broadcast' is the live event
     * that tells an already-open tab the row exists — without it the bell
     * only found out on its next poll, so a notification could sit unseen
     * on screen for half a minute, and considerably longer in a background
     * tab where browsers throttle timers. The phone would buzz and the
     * website would sit there, which is precisely how the app came to feel
     * slower than it is.
     *
     * Both are cheap and neither can fail the request: the row is a local
     * insert, and the broadcast is queued.
     */
    public const BELL = ['database', 'broadcast'];

    public static function wantsMail(object $notifiable): bool
    {
        return $notifiable->email !== null
            && $notifiable->email_verified_at !== null
            && ($notifiable->settings?->notificationValue('email') ?? true);
    }

    /** Push goes out whenever the user has subscribed a device (pref 'push' can disable). */
    public static function wantsPush(object $notifiable): bool
    {
        // A browser subscription or an installed Android app — either is a
        // device that can be pushed to. The channels sort out which is which:
        // each quietly skips a user with no devices of its kind.
        return ($notifiable->settings?->notificationValue('push') ?? true)
            && ($notifiable->pushSubscriptions()->exists() || $notifiable->fcmTokens()->exists());
    }

    /**
     * Kinds too frequent to email.
     *
     * Email is one message per notification with no way to collapse it, so a
     * busy chat would arrive as a busy inbox. These still reach the bell and
     * the device; they just do not land in the recipient's mail.
     *
     * The list grew when every action in the app started notifying. A task
     * edited by a colleague, an expense added to a shared ledger and a
     * checklist item ticked are all worth a glance on the phone and none of
     * them is worth an email — a shared project with a busy afternoon would
     * otherwise arrive as forty of them.
     */
    private const NEVER_MAIL = [
        'message',
        'missed_call',
        'task_updated',
        'task_completed',
        'task_comment',
        'expense_added',
        'expense_updated',
        'expense_deleted',
        'event_response',
    ];

    public function via(object $notifiable): array
    {
        $mail = self::wantsMail($notifiable) && ! in_array($this->kind, self::NEVER_MAIL, true);
        $via = $mail ? [...self::BELL, 'mail'] : self::BELL;

        if (self::wantsPush($notifiable)) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
            $via[] = \App\Notifications\Channels\FcmChannel::class;
        }

        return $via;
    }

    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->pushTitle ?? Alerts::titleOf($this->kind),
            'body' => $this->message,
            'tag' => $this->pushTag ?? 'social-' . $this->kind,
            'url' => $this->actionPath ?? '/',
            // Carried so the device can tell a bill from a chat before it has
            // shown either: the Android shell picks the channel from it, and
            // the service worker picks the vibration pattern.
            'kind' => $this->kind,
            'channel' => Alerts::channelOf($this->kind),
        ];
    }

    /** How urgently, and for how long — decided by what kind of alert this is. */
    public function pushOptions(): array
    {
        return Alerts::optionsOf($this->kind);
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
