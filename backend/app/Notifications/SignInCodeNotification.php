<?php

namespace App\Notifications;

use App\Models\AppSetting;
use App\Models\MobileOtp;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The code that finishes a sign-in.
 *
 * Deliberately not queued. A person is sitting at a login form waiting for
 * this; a queue worker that is down or busy turns "check your email" into
 * "you cannot get in", which is the worst failure this feature can have.
 *
 * It travels by every route the person already has open: e-mail always,
 * because that is the address the account is anchored to, and the bell and
 * the phone as well, because somebody already signed in on their phone gets
 * the code as a notification and never has to leave the login screen.
 *
 * Who it comes from depends on whose user this is. Somebody who signed up on
 * Netvork hears from the platform, which is what this class sends. Somebody a
 * company has taken on as an employee hears from that company's own mailbox
 * instead — sent before this, through the company's own server, and flagged
 * here so the same code does not arrive twice.
 */
class SignInCodeNotification extends Notification
{
    public function __construct(
        public MobileOtp $otp,
        public ?string $deviceName = null,
        /** The employer's mailbox already sent it; do not send it twice. */
        public bool $mailAlreadySent = false,
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = $this->mailAlreadySent ? ['database', 'broadcast'] : ['mail', 'database', 'broadcast'];

        if (SocialNotification::wantsPush($notifiable)) {
            // Whichever kinds of device this person has; each channel skips
            // a user with none of its own.
            $channels[] = \App\Notifications\Channels\WebPushChannel::class;
            $channels[] = \App\Notifications\Channels\FcmChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) now()->diffInMinutes($this->otp->expires_at);
        $from = trim((string) AppSetting::get('platform_mail_from'));
        $fromName = trim((string) AppSetting::get('platform_mail_name'));

        $mail = (new MailMessage)
            ->subject('Your Netvork sign-in code: ' . $this->otp->code)
            ->greeting('Signing in to Netvork')
            ->line("Your sign-in code is: **{$this->otp->code}**")
            ->line($this->deviceName
                ? 'It was asked for by: ' . $this->deviceName
                : 'It was asked for on a device that has not signed in before.')
            ->line("The code expires in {$minutes} minutes.")
            ->line('If this was not you, do not share the code — and change your password, because '
                . 'somebody knows it.');

        // Blank in Admin → Settings means the server's own MAIL_FROM, which
        // is what every other message already goes out as.
        return $from === '' ? $mail : $mail->from($from, $fromName ?: config('app.name'));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'sign_in_code',
            'message' => "Your Netvork sign-in code is {$this->otp->code}."
                . ($this->deviceName ? ' Asked for by: ' . $this->deviceName . '.' : ''),
            'code' => $this->otp->code,
            'purpose' => 'login',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * On the phone the code IS the notification — reading it should be
     * enough, without opening anything. Tagged so a second attempt
     * replaces the first rather than leaving two codes on the lock screen.
     */
    public function toPush(object $notifiable): array
    {
        return [
            'title' => 'Sign-in code: ' . $this->otp->code,
            'body' => $this->deviceName
                ? 'Asked for by: ' . $this->deviceName
                : 'Someone is signing in to your Netvork account.',
            'tag' => 'sign-in-code',
            'url' => '/',
            'kind' => 'sign_in_code',
            'channel' => 'alerts',
        ];
    }

    /** A code is worth waking the screen for, and it expires. */
    public function pushOptions(): array
    {
        return ['TTL' => 600, 'urgency' => 'high'];
    }
}
