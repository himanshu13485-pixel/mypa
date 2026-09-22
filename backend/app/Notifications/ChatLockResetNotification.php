<?php

namespace App\Notifications;

use App\Models\MobileOtp;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The way back into locked chats when their password is forgotten.
 *
 * To the account's own e-mail address, because that is the one thing the
 * person at the keyboard has to prove: whoever can read that inbox is the
 * owner, and whoever merely picked up the phone is not.
 */
class ChatLockResetNotification extends Notification
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
            ->subject('Your Netvork chat password reset code')
            ->line("Your code to reset your chat password is: **{$this->otp->code}**")
            ->line('Enter it in Netvork, on the "Forgot chat password" screen, and choose a new password.')
            ->line('The code expires in ' . max(1, (int) now()->diffInMinutes($this->otp->expires_at)) . ' minutes.')
            ->line('If you did not ask for this, somebody has your phone or your sign-in open. '
                . 'Your locked chats are still locked - but change your account password.');
    }
}
