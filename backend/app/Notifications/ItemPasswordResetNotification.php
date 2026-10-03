<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The way back into a locked project or note when its password is gone.
 *
 * To the owner's own e-mail address, because that is the one thing whoever
 * is at the keyboard has to prove: somebody who can read that inbox is the
 * owner, and somebody who merely picked up the phone is not.
 *
 * One notification for both, because the sentence is the same and two
 * copies of it would drift apart.
 */
class ItemPasswordResetNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  'project' or 'note', as the reader would say it
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $code,
        public int $minutes,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your Netvork {$this->kind} password reset code")
            ->line("Your code to reset the password on the {$this->kind} \"{$this->name}\" is: **{$this->code}**")
            ->line("Enter it in Netvork, on that {$this->kind}'s Forgot password screen, and choose a new password.")
            ->line("The code expires in {$this->minutes} minutes.")
            ->line("If you did not ask for this, somebody has your sign-in open. The {$this->kind} is still locked - "
                . 'but change your account password.');
    }
}
