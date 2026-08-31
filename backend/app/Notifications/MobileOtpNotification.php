<?php

namespace App\Notifications;

use App\Models\MobileOtp;
use App\Notifications\Concerns\BroadcastsTheStoredRow;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Delivered in-app only — the "app-to-app" OTP channel. Sent synchronously so
 *  the code is available immediately after registration. */
class MobileOtpNotification extends Notification
{
    use BroadcastsTheStoredRow;
    use Queueable;

    public function __construct(public MobileOtp $otp)
    {
    }

    public function via(object $notifiable): array
    {
        return SocialNotification::BELL;
    }

    public function toDatabase(object $notifiable): array
    {
        $action = $this->otp->purpose === 'login' ? 'login' : 'verification';

        return [
            'kind' => 'mobile_otp',
            'message' => "Your My PA {$action} code is {$this->otp->code}. It expires in "
                . (int) now()->diffInMinutes($this->otp->expires_at) . ' minutes.',
            'code' => $this->otp->code,
            'mobile' => $this->otp->mobile,
            'purpose' => $this->otp->purpose,
        ];
    }
}
