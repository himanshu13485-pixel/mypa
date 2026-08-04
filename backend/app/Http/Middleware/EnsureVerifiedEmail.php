<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account is not usable until its email address has been proven.
 *
 * Registration has to hand back a token — verifying the address is itself an
 * authenticated call — but that token used to open every endpoint in the app.
 * Anyone could sign up with an address they do not own, ignore the OTP screen,
 * and follow any deep link (a meeting invite, say) straight into a working
 * account complete with an App ID that other people could then find and add.
 *
 * So the token issued at registration is deliberately a limited one: it can
 * verify the address, fetch the account it belongs to, and log out. Everything
 * else waits. The response carries a code so the client can send the user to
 * the verification screen instead of treating it as a generic refusal.
 */
class EnsureVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Accounts without an email at all (mobile-first legacy records) have
        // nothing to verify and are left alone.
        if ($user && $user->email && $user->email_verified_at === null) {
            return response()->json([
                'message' => 'Confirm your email address to continue. Enter the code we sent to ' . $user->email . '.',
                'code' => 'email_unverified',
                'email' => $user->email,
            ], 403);
        }

        return $next($request);
    }
}
