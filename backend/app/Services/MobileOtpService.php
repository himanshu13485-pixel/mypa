<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\MobileOtp;
use App\Models\User;
use App\Notifications\MobileOtpNotification;
use Illuminate\Validation\ValidationException;

/**
 * App-to-app mobile verification: the OTP travels through the platform itself
 * (in-app notification to the user's session, viewable/resendable from the
 * admin app) — no SMS network involved.
 */
class MobileOtpService
{
    public function issue(User $user, string $mobile, string $purpose = 'verify_mobile'): MobileOtp
    {
        // Retire previous unconsumed codes for this purpose.
        MobileOtp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = MobileOtp::create([
            'user_id' => $user->id,
            'mobile' => $mobile,
            'code' => (string) random_int(100000, 999999),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes((int) AppSetting::get('otp_expiry_minutes')),
        ]);

        $user->notify(new MobileOtpNotification($otp));

        return $otp;
    }

    /**
     * The code that finishes a password sign-in.
     *
     * Sent everywhere the person already is — their e-mail, the bell, and
     * any phone they are already signed in on — because the one thing this
     * must not be is slow. Somebody is holding a login form open.
     */
    public function issueSignInCode(User $user, ?string $deviceName = null): MobileOtp
    {
        MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'login')
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = MobileOtp::create([
            'user_id' => $user->id,
            'mobile' => $user->email,
            'code' => (string) random_int(100000, 999999),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes((int) AppSetting::get('otp_expiry_minutes')),
        ]);

        $user->notify(new \App\Notifications\SignInCodeNotification($otp, $deviceName));

        return $otp;
    }

    /** Email variant: the code goes to the NEW address (proof of ownership). */
    public function issueEmail(User $user, string $email): MobileOtp
    {
        MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'verify_email')
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = MobileOtp::create([
            'user_id' => $user->id,
            'mobile' => $email, // pending address rides on the OTP row
            'code' => (string) random_int(100000, 999999),
            'purpose' => 'verify_email',
            'expires_at' => now()->addMinutes((int) AppSetting::get('otp_expiry_minutes')),
        ]);

        \Illuminate\Support\Facades\Notification::route('mail', $email)
            ->notify(new \App\Notifications\EmailOtpNotification($otp));

        return $otp;
    }

    public function verify(User $user, string $code, string $purpose = 'verify_mobile'): MobileOtp
    {
        $otp = MobileOtp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest()
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            throw ValidationException::withMessages([
                'code' => ['No active verification code. Request a new one.'],
            ]);
        }

        if (! hash_equals($otp->code, trim($code))) {
            $otp->increment('attempts');
            throw ValidationException::withMessages([
                'code' => ['Incorrect code. ' . max(0, 5 - $otp->attempts) . ' attempt(s) left.'],
            ]);
        }

        $otp->update(['consumed_at' => now()]);

        return $otp;
    }
}
