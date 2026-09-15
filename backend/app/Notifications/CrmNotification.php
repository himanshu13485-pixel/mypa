<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * CRM workflow notifications: a request was filed for you to decide, or
 * your request was decided. Rides the same channels as the personal app —
 * the bell always, mail only for verified addresses that want it, push for
 * subscribed devices — so approvers hear about work without opening the CRM.
 */
class CrmNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $kind,        // crm_approval | crm_leave | crm_task | crm_invoice_update
        public string $message,
        public ?string $actionPath = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        // The menu this came from decides whether it is written or pushed;
        // the bell row is kept either way, as the record of what happened.
        $topic = \App\Support\NotificationTopics::of($this->kind);

        $via = SocialNotification::wantsMail($notifiable) && \App\Support\NotificationTopics::allows($notifiable, $topic, 'email')
            ? ['database', 'mail']
            : ['database'];

        if (SocialNotification::wantsPush($notifiable) && \App\Support\NotificationTopics::allows($notifiable, $topic, 'app')) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
        }

        return $via;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'message' => $this->message,
            'action_path' => $this->actionPath,
        ];
    }

    /**
     * The company this is about - the one the person works for. CRM mail is
     * the company writing to its own staff, so it carries the company's name;
     * Netvork only when there is no company to name.
     */
    private function brand(object $notifiable): string
    {
        return ($notifiable instanceof \App\Models\User
            ? \App\Services\Crm\CompanyMailer::brandFor($notifiable)
            : null) ?? 'Netvork CRM';
    }

    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->brand($notifiable),
            'body' => $this->message,
            'tag' => $this->kind,
            'url' => $this->actionPath ?? '/crm',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = $this->brand($notifiable);

        $mail = (new MailMessage)
            ->subject($brand . ' — ' . str($this->kind)->replace(['crm_', '_'], ['', ' '])->title())
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($this->message)
            ->salutation(new \Illuminate\Support\HtmlString('Regards,<br>' . e($brand)));

        if ($this->actionPath) {
            $mail->action('Open CRM', config('mypa.frontend_url') . $this->actionPath);
        }

        return $mail;
    }
}
