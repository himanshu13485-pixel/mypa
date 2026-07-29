<?php

namespace App\Notifications;

use App\Models\MobileOtp;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the NEW email address to prove ownership before it becomes active. */
class EmailOtpNotification extends Notification
{
    use Queueable;

    public function __construct(public MobileOtp $otp)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your My PA email verification code')
            ->line("Your verification code is: **{$this->otp->code}**")
            ->line('Enter it in My PA → Settings → Login identity to activate this email address.')
            ->line('The code expires in ' . (int) now()->diffInMinutes($this->otp->expires_at) . ' minutes. '
                . 'If you did not request this, you can ignore this email.');
    }
}
