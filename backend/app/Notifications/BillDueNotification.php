<?php

namespace App\Notifications;

use App\Models\Bill;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Bill $bill, public bool $alarm = false)
    {
    }

    public function via(object $notifiable): array
    {
        $via = ['database'];
        if (SocialNotification::wantsMail($notifiable)) {
            $via[] = 'mail';
        }
        if (SocialNotification::wantsPush($notifiable)) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
        }

        return $via;
    }

    protected function alarmMessage(): string
    {
        $time = $this->bill->due_time ? substr($this->bill->due_time, 0, 5) : '';

        return "Bill alarm: \u{201C}{$this->bill->name}\u{201D} is due today at {$time}"
            . ($this->bill->amount ? " ({$this->bill->currency} {$this->bill->amount})" : '') . '.';
    }

    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->alarm ? 'Bill alarm' : 'Bill reminder',
            'body' => $this->alarm
                ? $this->alarmMessage()
                : "\u{201C}{$this->bill->name}\u{201D} is due " . $this->bill->due_on->toFormattedDateString() . '.',
            'url' => '/bills',
        ];
    }

    protected function daysLeft(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->bill->due_on, false);
    }

    public function toDatabase(object $notifiable): array
    {
        $days = $this->daysLeft();

        if ($this->alarm) {
            return [
                'kind' => 'bill_due',
                'bill_uuid' => $this->bill->uuid,
                'bill_name' => $this->bill->name,
                'amount' => $this->bill->amount,
                'due_on' => $this->bill->due_on->toDateString(),
                'message' => $this->alarmMessage(),
                'actions' => ['open'],
            ];
        }

        return [
            'kind' => 'bill_due',
            'bill_uuid' => $this->bill->uuid,
            'bill_name' => $this->bill->name,
            'amount' => $this->bill->amount,
            'due_on' => $this->bill->due_on->toDateString(),
            'message' => $days < 0
                ? "Bill overdue: “{$this->bill->name}” was due " . $this->bill->due_on->toFormattedDateString() . '.'
                : ($days === 0
                    ? "Bill due today: “{$this->bill->name}”" . ($this->bill->amount ? " ({$this->bill->currency} {$this->bill->amount})" : '') . '.'
                    : "Bill due in {$days} day(s): “{$this->bill->name}”" . ($this->bill->amount ? " ({$this->bill->currency} {$this->bill->amount})" : '') . '.'),
            'actions' => ['open'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Bill reminder: ' . $this->bill->name)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line("Your bill “{$this->bill->name}” is due on " . $this->bill->due_on->toFormattedDateString() . '.')
            ->when($this->bill->amount, fn ($mail) => $mail->line("Amount: {$this->bill->currency} {$this->bill->amount}"))
            ->action('View bills', config('mypa.frontend_url') . '/bills');
    }
}
