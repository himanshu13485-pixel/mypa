<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
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

        /*
         * Work mail arrives from work.
         *
         * Registered over the framework's own mail driver rather than named
         * in each notification's via(), so everything the app sends to a
         * company's employee leaves from that company's mailbox - and a
         * notification added next year is routed correctly without anybody
         * remembering this line exists.
         */
        Notification::extend('mail', fn ($app) => new \App\Notifications\Channels\CompanyMailChannel(
            $app->make(\Illuminate\Contracts\Mail\Factory::class),
            $app->make(\Illuminate\Mail\Markdown::class),
        ));

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

        // A forward reaches twenty chats now, so it is held the way a
        // broadcast is - loosely enough for a second round to the chats the
        // first could not fit, and no looser.
        RateLimiter::for('forward', fn (Request $request) => Limit::perMinute(12)->by($perUser($request)));

        // Mails: sending, signing in to mail servers, and AI drafts - each
        // on its own counter, so none refuses early for the others' use.
        RateLimiter::for('mail-compose', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));
        RateLimiter::for('mail-connect', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));
        RateLimiter::for('mail-ai', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));

        /*
         * The chat password, tried.
         *
         * Named, like every limit here, because an unnamed "throttle:6,1"
         * keys on the person alone - so it counted every other request they
         * made against the same six, and somebody who had simply been using
         * the app for a minute was refused at the password screen.
         *
         * Six guesses a minute forgives a slipped thumb and is useless for
         * working through PINs. A reset code costs an e-mail, so three per
         * ten minutes.
         */
        RateLimiter::for('chat-lock', fn (Request $request) => Limit::perMinute(6)->by($perUser($request)));
        RateLimiter::for('chat-lock-mail', fn (Request $request) => Limit::perMinutes(10, 3)->by($perUser($request)));

        /*
         * A group's password, tried - and the same lesson twice.
         *
         * This one shipped on an unnamed "throttle:6,1", which is exactly
         * what the note above says not to do: it shared its six with every
         * other request the person made, so somebody who had been using the
         * app for a minute was refused before their first real guess.
         *
         * Counted per person per group, so one member fumbling their own
         * group cannot lock their colleagues out of a different one - and
         * refused in words that say how long to wait, rather than Laravel's
         * bare "Too Many Attempts." at somebody who has done nothing wrong.
         */
        RateLimiter::for('group-lock', fn (Request $request) => Limit::perMinute(8)
            ->by($perUser($request) . '|' . $request->route('group'))
            ->response(function (Request $request, array $headers) {
                $seconds = (int) ($headers['Retry-After'] ?? 60);

                return response()->json([
                    'message' => "Too many tries at the group password. Wait {$seconds} second"
                        . ($seconds === 1 ? '' : 's') . ' and try again.',
                ], 429, $headers);
            }));

        // Searching every chat is a real query, and a box people type into.
        RateLimiter::for('message-search', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));

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

        /*
         * And the same fault again on the public side, where it is worse.
         *
         * Everything above is keyed on the person. Out here nobody has
         * signed in, so an inline throttle keys on the address alone - and
         * every public route then shared one counter per visitor, with the
         * tightest number on any of them deciding when all of them stopped
         * answering.
         *
         * It showed as "Too many attempts" on somebody's first ever
         * registration. The sign-up form asks for a username suggestion
         * while you type your name; those lookups, on the same counter, had
         * already spent the register route's three a minute before the
         * person pressed the button. Behind a NAT or a proxy they would have
         * been spending strangers' allowance as well.
         */
        RateLimiter::for('public-auth', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));

        /*
         * Signing up, on a counter of its own at last.
         *
         * Five rather than three, because a rejected form still counts: a
         * username already taken, then a password too short, then a
         * mistyped confirmation is an ordinary first minute on a sign-up
         * page, and at three the next try - the one that would have worked -
         * was refused. Five is still 7,200 accounts a day from one address,
         * which is not a door worth walking through.
         */
        RateLimiter::for('sign-up', fn (Request $request) => Limit::perMinute(5)->by($perUser($request)));
        RateLimiter::for('username-check', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('invite-peek', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('email-verify-link', fn (Request $request) => Limit::perMinute(6)->by($perUser($request)));

        // The rest of the open door, each holding the number it always had.
        RateLimiter::for('public-plans', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('file-link', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));
        RateLimiter::for('client-errors', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));
        RateLimiter::for('gateway-webhook', fn (Request $request) => Limit::perMinute(120)->by($perUser($request)));
        RateLimiter::for('push-rotate', fn (Request $request) => Limit::perMinute(20)->by($perUser($request)));

        // Meetings and booking links: the two doors with no account behind
        // them. Reading stays looser than writing, as it was.
        RateLimiter::for('meeting-peek', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('meeting-join', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('guest-signal', fn (Request $request) => Limit::perMinute(240)->by($perUser($request)));
        RateLimiter::for('guest-chat', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));
        RateLimiter::for('booking-page', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));
        RateLimiter::for('booking-slots', fn (Request $request) => Limit::perMinute(60)->by($perUser($request)));
        RateLimiter::for('booking-create', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
        RateLimiter::for('booking-view', fn (Request $request) => Limit::perMinute(30)->by($perUser($request)));
        RateLimiter::for('booking-change', fn (Request $request) => Limit::perMinute(10)->by($perUser($request)));
    }
}
