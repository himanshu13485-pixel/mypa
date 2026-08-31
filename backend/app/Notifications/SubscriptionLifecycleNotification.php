<?php

namespace App\Notifications;

use App\Models\Subscription;
use App\Notifications\Concerns\BroadcastsTheStoredRow;
use App\Support\Alerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionLifecycleNotification extends Notification implements ShouldQueue
{
    use BroadcastsTheStoredRow;
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public string $event, // renewal_reminder | expired
        public ?int $daysLeft = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        $via = SocialNotification::wantsMail($notifiable)
            ? [...SocialNotification::BELL, 'mail']
            : SocialNotification::BELL;

        if (SocialNotification::wantsPush($notifiable)) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
            $via[] = \App\Notifications\Channels\FcmChannel::class;
        }

        return $via;
    }

    public function toPush(object $notifiable): array
    {
        $kind = 'subscription_' . $this->event;

        return [
            'title' => $this->event === 'expired' ? 'Plan expired' : 'Plan expiring',
            'body' => $this->message(),
            // One per subscription: a reminder at seven days out and
            // another at one are the same fact getting closer, so the
            // newer one should replace the older rather than sit under it.
            'tag' => 'subscription-' . $this->subscription->id,
            'url' => '/pricing',
            'kind' => $kind,
            'channel' => Alerts::channelOf($kind),
        ];
    }

    public function pushOptions(): array
    {
        return Alerts::optionsOf('subscription_' . $this->event);
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
