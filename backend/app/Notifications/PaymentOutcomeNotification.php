<?php

namespace App\Notifications;

use App\Models\PaymentOrder;
use App\Notifications\Concerns\BroadcastsTheStoredRow;
use App\Support\Alerts;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentOutcomeNotification extends Notification implements ShouldQueue
{
    use BroadcastsTheStoredRow;
    use Queueable;

    public function __construct(
        public PaymentOrder $order,
        public string $outcome, // successful | failed
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

    /**
     * Worth a buzz, both ways.
     *
     * A success is the answer to "did that go through?" — the question
     * every payment leaves behind, and the reason people sat refreshing
     * the billing page. A failure is more urgent still: nothing was taken,
     * but nothing was bought either, and the only way to find that out was
     * to notice the plan had not changed.
     */
    public function toPush(object $notifiable): array
    {
        $amount = '₹' . Money::toDecimalString($this->order->total_amount);
        $kind = 'payment_' . $this->outcome;

        return [
            'title' => $this->outcome === 'successful' ? 'Payment received' : 'Payment failed',
            'body' => $this->outcome === 'successful'
                ? "{$amount} received — your {$this->order->plan->name} plan is active."
                : "Your payment for the {$this->order->plan->name} plan did not complete.",
            'tag' => 'payment-' . $this->order->uuid,
            'url' => $this->outcome === 'successful' ? '/settings' : '/pricing',
            'kind' => $kind,
            'channel' => Alerts::channelOf($kind),
        ];
    }

    public function pushOptions(): array
    {
        return Alerts::optionsOf('payment_' . $this->outcome);
    }

    public function toDatabase(object $notifiable): array
    {
        $amount = '₹' . Money::toDecimalString($this->order->total_amount);

        return [
            'kind' => 'payment_' . $this->outcome,
            'order_uuid' => $this->order->uuid,
            'plan' => $this->order->plan->name,
            'message' => $this->outcome === 'successful'
                ? "Payment of {$amount} received — your {$this->order->plan->name} plan is now active."
                : "Payment for the {$this->order->plan->name} plan did not complete. No money was deducted permanently — please try again.",
            'actions' => ['open'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = '₹' . Money::toDecimalString($this->order->total_amount);

        if ($this->outcome === 'successful') {
            return (new MailMessage)
                ->subject('Payment received — ' . $this->order->plan->name . ' plan active')
                ->greeting('Hello ' . $notifiable->name . ',')
                ->line("We received your payment of {$amount}.")
                ->line("Your {$this->order->plan->name} plan ({$this->order->billing_frequency}) is now active.")
                ->action('View subscription', config('mypa.frontend_url') . '/settings');
        }

        return (new MailMessage)
            ->subject('Payment failed')
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your payment for the {$this->order->plan->name} plan did not complete.")
            ->action('Try again', config('mypa.frontend_url') . '/pricing');
    }
}
