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
        $via = SocialNotification::wantsMail($notifiable) ? ['database', 'mail'] : ['database'];

        if (SocialNotification::wantsPush($notifiable)) {
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

    public function toPush(object $notifiable): array
    {
        return [
            'title' => 'Netvork CRM',
            'body' => $this->message,
            'tag' => $this->kind,
            'url' => $this->actionPath ?? '/crm',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Netvork CRM — ' . str($this->kind)->replace(['crm_', '_'], ['', ' '])->title())
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($this->message);

        if ($this->actionPath) {
            $mail->action('Open Netvork CRM', config('mypa.frontend_url') . $this->actionPath);
        }

        return $mail;
    }
}
