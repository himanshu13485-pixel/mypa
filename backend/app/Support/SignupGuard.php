<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Keeping scripts off the forms that make things.
 *
 * Three layers, because each catches what the others let through:
 *
 * 1. A honeypot — a field a person never sees and never fills, which an
 *    automated form-filler fills because it fills everything. Free, and it
 *    stops the crude majority.
 * 2. A clock — a form rendered and submitted inside a few seconds was not
 *    read by anybody. Also free, and it catches scripts that skip the
 *    honeypot because they only fill visible fields.
 * 3. Cloudflare Turnstile — the only one of the three that survives an
 *    attacker who has read the page and written for it specifically.
 *
 * The first two are always on. The third applies only once a key is
 * configured, so the app is protected today and better protected the moment
 * somebody pastes a key in — rather than being unprotected until then.
 *
 * Every refusal is the same message. Telling a script which layer caught it
 * is telling it what to fix.
 */
class SignupGuard
{
    /** What a person is told, whichever layer objected. */
    private const REFUSAL = 'We could not verify this request. Please reload the page and try again.';

    public static function assertHuman(Request $request, string $field = 'email'): void
    {
        self::assertHoneypotUntouched($request, $field);
        self::assertNotTooFast($request, $field);
        self::assertTurnstilePassed($request, $field);
    }

    /**
     * The field nobody can see.
     *
     * Named for something a form-filler would want to complete rather than
     * "honeypot": the whole trick is that it looks worth filling in.
     */
    private static function assertHoneypotUntouched(Request $request, string $field): void
    {
        if (filled($request->input('company_website'))) {
            self::refuse($field);
        }
    }

    /**
     * The clock.
     *
     * The client sends back when it rendered the form. A missing or unreadable
     * value is not treated as a failure — an old cached page or a client that
     * dropped the field would otherwise lock a real person out of signing up,
     * and the other two layers still apply.
     */
    private static function assertNotTooFast(Request $request, string $field): void
    {
        $started = $request->input('form_started_at');

        if (! is_numeric($started)) {
            return;
        }

        $seconds = (microtime(true) * 1000 - (float) $started) / 1000;
        $minimum = (int) config('mypa.signup_guard.min_seconds');

        /*
         * Only a suspiciously SHORT time is refused. A form left open for an
         * hour is somebody who got distracted, which is not an attack.
         */
        if ($seconds >= 0 && $seconds < $minimum) {
            self::refuse($field);
        }
    }

    private static function assertTurnstilePassed(Request $request, string $field): void
    {
        $secret = config('mypa.signup_guard.turnstile_secret');

        if (blank($secret)) {
            return;
        }

        $token = $request->input('turnstile_token');

        if (blank($token)) {
            self::refuse($field);
        }

        try {
            $response = Http::asForm()->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (\Throwable) {
            /*
             * Cloudflare being unreachable must not close the door.
             *
             * A verification service that is down would otherwise stop every
             * real sign-up while stopping no attacker who is already past the
             * other two layers. Fail open here, and loudly in the log.
             */
            report(new \RuntimeException('Turnstile verification unreachable; sign-up allowed through.'));

            return;
        }

        if (! ($response->json('success') === true)) {
            self::refuse($field);
        }
    }

    private static function refuse(string $field): never
    {
        throw ValidationException::withMessages([$field => [self::REFUSAL]]);
    }
}
