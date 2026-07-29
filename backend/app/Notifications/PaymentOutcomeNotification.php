<?php

namespace App\Notifications;

use App\Models\PaymentOrder;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentOutcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PaymentOrder $order,
        public string $outcome, // successful | failed
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
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
