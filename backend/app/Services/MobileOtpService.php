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

        // An employee hears from their employer; everybody else hears from
        // the platform. Sent here rather than through the notification's
        // mail channel because it has to leave through the company's own
        // server, not merely wear its address — an address without the
        // server behind it fails SPF and lands in spam.
        $throughEmployer = false;
        if ($mailbox = \App\Services\Crm\CompanyMailer::forStaff($user)) {
            try {
                $mailbox['mailer']->html(
                    $this->signInCodeHtml($otp, $deviceName),
                    function ($m) use ($user, $mailbox, $otp) {
                        $m->to($user->email)
                            ->from($mailbox['address'], $mailbox['name'])
                            ->subject('Your sign-in code: ' . $otp->code);
                    },
                );
                $throughEmployer = true;
            } catch (\Throwable) {
                // A misconfigured company mailbox must never be the reason
                // somebody cannot sign in. The platform sends it instead.
                $throughEmployer = false;
            }
        }

        $user->notify(new \App\Notifications\SignInCodeNotification($otp, $deviceName, $throughEmployer));

        return $otp;
    }

    /** The same words the platform's own version of this mail carries. */
    private function signInCodeHtml(MobileOtp $otp, ?string $deviceName): string
    {
        $minutes = (int) now()->diffInMinutes($otp->expires_at);
        $lines = [
            'Your sign-in code is: <strong>' . e($otp->code) . '</strong>',
            $deviceName
                ? 'It was asked for by: ' . e($deviceName)
                : 'It was asked for on a device that has not signed in before.',
            'The code expires in ' . $minutes . ' minutes.',
            'If this was not you, do not share the code — and change your password, '
                . 'because somebody knows it.',
        ];

        return '<p>' . implode('</p><p>', $lines) . '</p>';
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

    /**
     * The one code that always works, on a local machine only.
     *
     * Issuing still runs exactly as it does everywhere — a row is created,
     * a notification goes out — the only thing this shortcuts is DELIVERY,
     * which on a dev machine usually means mail is not configured, or the
     * bell nobody is watching. Without it, nobody can get past a sign-in
     * code screen until mail works, which blocks every other kind of work.
     *
     * Gated on app()->environment('local') rather than an .env flag: an env
     * var is one copy-paste of a production .env away from being true where
     * it must never be, and 'local' is what Laravel itself calls the
     * environment this process is running in — nothing this reads can make
     * it true by mistake.
     *
     * A real, unexpired, unconsumed row still has to exist for this user and
     * purpose. That is not caution for its own sake: verify_mobile and
     * verify_email read the pending value off that row afterwards ($otp->
     * mobile carries the new number or address), so skipping the row would
     * verify a mobile change into no mobile at all. This only ever skips the
     * comparison against $otp->code, never the lookup.
     */
    private const LOCAL_BYPASS_CODE = '123456';

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

        $bypassed = app()->environment('local') && trim($code) === self::LOCAL_BYPASS_CODE;

        if (! $bypassed && ! hash_equals($otp->code, trim($code))) {
            $otp->increment('attempts');
            throw ValidationException::withMessages([
                'code' => ['Incorrect code. ' . max(0, 5 - $otp->attempts) . ' attempt(s) left.'],
            ]);
        }

        $otp->update(['consumed_at' => now()]);

        return $otp;
    }
}
