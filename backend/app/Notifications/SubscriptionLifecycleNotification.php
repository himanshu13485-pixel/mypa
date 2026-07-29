<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionLifecycleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public string $event, // renewal_reminder | expired
        public ?int $daysLeft = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    protected function message(): string
    {
        $plan = $this->subscription->plan->name;

        if ($this->event === 'expired') {
            return "Your {$plan} plan has expired. Your account is now on the Free plan — your data is safe, but plan limits apply again.";
        }

        return $this->daysLeft === 0
            ? "Your {$plan} plan expires today. Renew now to keep your benefits."
            : "Your {$plan} plan expires in {$this->daysLeft} day(s). Renew to avoid interruption.";
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'subscription_' . $this->event,
            'plan' => $this->subscription->plan->slug,
            'ends_at' => $this->subscription->ends_at?->toIso8601String(),
            'message' => $this->message(),
            'actions' => ['open'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->event === 'expired' ? 'Your My PA plan has expired' : 'Your My PA plan is expiring soon')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($this->message())
            ->action('Renew now', config('mypa.frontend_url') . '/pricing');
    }
}
