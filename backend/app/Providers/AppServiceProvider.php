<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
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
    }

    public function boot(): void
    {
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
}
