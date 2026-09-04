<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Default STT: browser Web Speech API (no server audio processing).
        // Bind a Whisper/Google/Azure implementation here to enable server STT.
        $this->app->bind(
            \App\Services\Voice\SpeechToTextInterface::class,
            \App\Services\Voice\BrowserSpeechProvider::class,
        );

        // Payment gateway abstraction: Cashfree in real environments, the fake
        // gateway in the test suite (spec §34.23 — no real payment calls in tests).
        $this->app->singleton(
            \App\Services\Billing\PaymentGatewayInterface::class,
            fn () => $this->app->environment('testing')
                ? new \App\Services\Billing\FakePaymentGateway
                : new \App\Services\Billing\CashfreePaymentGateway,
        );
    }

    public function boot(): void
    {
        $this->registerRateLimiters();

        // Password reset links open in the SPA, which posts back to the API.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return config('mypa.frontend_url')
                . '/reset-password?token=' . $token
                . '&email=' . urlencode($user->getEmailForPasswordReset());
        });

        // Email verification stays a signed API URL.
        VerifyEmail::createUrlUsing(function (User $user) {
            return URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
            );
        });
    }

    /**
     * Named limiters, because inline ones all share a counter.
     *
     * `throttle:20,1` looks like it means "twenty of THESE a minute". It does
     * not. Laravel keys an inline throttle on the user alone, so every inline
     * throttle a request passes through reads and writes the same counter —
     * and the API group already spends that counter on 180 requests a minute
     * of ordinary traffic. A tighter inline limit inside it therefore trips
     * as soon as the shared count passes its own number, no matter what the
     * request was.
     *
     * The effect was a "Too Many Attempts" on the first click of Call from a
     * CRM screen, because the page's own polling had already spent twenty
     * requests. The same fault was sitting under the master key reset, which
     * would have refused after ten API calls of any kind in an hour, and
     * under resend-verification and join-group, which predate today.
     *
     * A named limiter is keyed on md5(name . key), so each of these gets a
     * counter of its own and the number in it means what it says.
     */
    protected function registerRateLimiters(): void
    {
        $perUser = fn (Request $request) => optional($request->user())->id ?: $request->ip();

        // Ring my own phone. A person clicks this a few times a minute at
        // most; a runaway client should not be able to buzz a pocket on a loop.
        RateLimiter::for('dial', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));

        // One send carries up to fifty private messages, so this is the one
        // worth holding down hardest.
        RateLimiter::for('broadcast', fn (Request $request) => Limit::perMinute(6)->by($perUser($request)));

        // Setting the key that opens every staff account in a company.
        RateLimiter::for('master-key', fn (Request $request) => Limit::perHour(6)->by($perUser($request)));

        // Working through a company's staff one reset at a time is the shape
        // an abuse of the master key takes.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perHour(10)->by($perUser($request)));

        // A verification e-mail somebody keeps asking for.
        RateLimiter::for('verify-email', fn (Request $request) => Limit::perMinute(6)->by($perUser($request)));

        // Guessing at invite tokens.
        RateLimiter::for('join-group', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));

        /*
         * The rest, which predate today and carried the same fault.
         *
         * Each was written as an inline throttle inside the API group, so
         * each was really "n requests of ANY kind", not n of its own. /me was
         * the worst of them: five a minute meant saving a profile failed on
         * any page that had been open long enough to poll a few times.
         */
        RateLimiter::for('verify-otp', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('resend-otp', fn (Request $request) => Limit::perMinute(5)->by($perUser($request)));
        RateLimiter::for('profile-update', fn (Request $request) => Limit::perMinute(5)->by($perUser($request)));
        RateLimiter::for('change-request', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('person-lookup', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('payment-verify', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('report-file', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
    }
}
