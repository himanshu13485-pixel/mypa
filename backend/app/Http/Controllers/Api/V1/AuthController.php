<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AppSetting;
use App\Models\Connection;
use App\Models\LoginHistory;
use App\Models\TrustedDevice;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\AppIdService;
use App\Support\SignupGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, AppIdService $appIds): JsonResponse
    {
        /*
         * Before anything is created, and before the address is even read.
         *
         * A script that gets past this has still cost somebody a Turnstile
         * solve; one that does not never reaches the database, which is the
         * point — the cheapest request to serve is the one refused first.
         */
        SignupGuard::assertHuman($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Email-first identity: the account is confirmed by an emailed OTP.
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // Alphanumeric handle, no special characters (second login identity).
            'username' => ['required', 'string', 'min:4', 'max:20', 'regex:/^[a-zA-Z0-9]+$/'],
            'password' => ['required', 'confirmed',
                /*
                 * ->uncompromised() checks the password against the
                 * HaveIBeenPwned breach corpus, by k-anonymity: the
                 * first five characters of its hash go out, never the
                 * password. A password already in a public breach list
                 * is the first thing an attacker tries, and eight
                 * characters with a letter and a digit describes most
                 * of them. Laravel treats an unreachable API as a pass,
                 * so this cannot lock anybody out.
                 */
                PasswordRule::min(8)->letters()->numbers()->uncompromised()],
            // Mobile is optional, records-only (never a login or search identity).
            // A country code and then the number, which is what the form
            // now sends: one shape in the column instead of four.
            'mobile' => ['nullable', 'string', 'max:32', 'regex:/^[+][1-9][0-9]{6,16}$/'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'language' => ['nullable', 'string', 'max:8'],
            'account_type' => ['nullable', 'in:personal,business'],
            'referral_app_id' => ['nullable', 'string', 'max:32'],
            // The code from an invite link, carried through the sign-up.
            'invite_code' => ['nullable', 'string', 'max:24'],

            // The guard's own fields. Validated so a huge payload cannot be
            // smuggled in through them; read by SignupGuard, never stored.
            'company_website' => ['nullable', 'string', 'max:255'],
            'form_started_at' => ['nullable', 'numeric'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);

        $username = mb_strtolower($data['username']);

        if (User::whereRaw('LOWER(username) = ?', [$username])->exists()) {
            throw ValidationException::withMessages([
                'username' => ['This username is taken.'],
            ]);
        }

        /*
         * Whoever's link brought them here.
         *
         * Resolved before the account is made so a dud code is simply
         * nobody, rather than a failed registration: somebody who mistyped
         * the link should still end up with an account.
         */
        $inviter = ! empty($data['invite_code'])
            ? UserProfile::where('invite_code', $data['invite_code'])->first()?->user
            : null;

        if ($inviter && $inviter->status !== 'active') {
            $inviter = null;
        }

        $user = DB::transaction(function () use ($data, $appIds, $username) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $username,
                'email' => $data['email'],
                'mobile' => $data['mobile'] ?? null,
                'password' => $data['password'],
            ]);

            $user->profile()->create([
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
                'country' => $data['country'] ?? null,
                'timezone' => $data['timezone'] ?? 'Asia/Kolkata',
                'language' => $data['language'] ?? 'en',
                'account_type' => $data['account_type'] ?? 'personal',
                'referral_app_id' => $data['referral_app_id'] ?? null,
            ]);

            $user->settings()->create([]);

            $appIds->generateFor($user);

            $userRole = Role::where('slug', 'user')->first();
            if ($userRole) {
                $user->roles()->attach($userRole->id);
            }

            return $user;
        });

        /*
         * The two of them, joined up.
         *
         * Pending and from the newcomer, not accepted outright: the code
         * travels in a URL anybody can forward, so it is evidence that
         * somebody was invited, not proof of who by. One tap from the
         * inviter settles it, and that tap is the consent.
         */
        if ($inviter) {
            Connection::firstOrCreate(
                ['requester_id' => $user->id, 'addressee_id' => $inviter->id],
                ['status' => 'pending', 'message' => 'Joined Netvork from your invite link.'],
            );
        }

        // Account confirmation: a 6-digit OTP is emailed to the address.
        app(\App\Services\MobileOtpService::class)->issueEmail($user, $user->email);

        // User activity trail: registrations show up in the admin Activity tab.
        \App\Models\AuditLog::record($user, 'user.registered', $user, ['username' => $user->username]);

        $token = $user->createToken($request->input('device_name', 'web'))->plainTextToken;

        $this->recordLogin($request, $user);

        return response()->json([
            'message' => 'Registration successful. Enter the verification code we emailed to ' . $user->email . '.',
            'data' => new UserResource($user->load(['profile', 'settings', 'appId', 'roles'])),
            'token' => $token,
            'email_verification_pending' => true,
        ], 201);
    }

    /** Re-send the account-confirmation code to the user's own email. */
    public function resendEmailOtp(Request $request, \App\Services\MobileOtpService $otps): JsonResponse
    {
        abort_if($request->user()->email_verified_at !== null, 409, 'Email is already verified.');
        abort_if($request->user()->email === null, 422, 'No email on this account.');

        $otps->issueEmail($request->user(), $request->user()->email);

        return response()->json(['message' => 'A new code has been emailed to you.']);
    }

    /**
     * Username helper for the signup form: suggests a unique handle derived
     * from the full name (numeric suffix on collision), or checks availability.
     */
    public function suggestUsername(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:32'],
        ]);

        // Availability check for a manually typed username.
        if (! empty($data['username'])) {
            $candidate = mb_strtolower($data['username']);
            $valid = (bool) preg_match('/^[a-z0-9]{4,20}$/', $candidate);
            $available = $valid && ! User::whereRaw('LOWER(username) = ?', [$candidate])->exists();

            return response()->json(['data' => ['available' => $available, 'valid' => $valid]]);
        }

        // Derive a base handle from the name: letters/digits only, lowercase.
        $base = mb_strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $data['name'] ?? ''));
        if (mb_strlen($base) < 4) {
            $base = $base !== '' ? str_pad($base, 4, (string) random_int(10, 99)) : 'user' . random_int(100, 999);
        }
        $base = mb_substr($base, 0, 20);

        $candidate = $base;
        $suffix = 0;
        while (User::whereRaw('LOWER(username) = ?', [$candidate])->exists()) {
            $suffix++;
            $tail = (string) $suffix;
            $candidate = mb_substr($base, 0, 20 - mb_strlen($tail)) . $tail;
            if ($suffix > 500) { // pathological fallback
                $candidate = mb_substr($base, 0, 14) . random_int(100000, 999999);
                break;
            }
        }

        return response()->json(['data' => ['suggestion' => $candidate, 'available' => true]]);
    }

    /** Verify the mobile number with the in-app OTP. */
    public function verifyMobile(Request $request, \App\Services\MobileOtpService $otps): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:8']]);

        $otp = $otps->verify($request->user(), $data['code']);

        // fresh(): the in-memory auth model can be stale relative to approval
        // flows that changed the row; forceFill avoids dirty-check omissions.
        $request->user()->fresh()->forceFill([
            'mobile' => $otp->mobile, // supports change-of-number flows
            'mobile_verified_at' => now(),
        ])->save();

        return response()->json(['message' => 'Mobile number verified.']);
    }

    /** Re-issue the in-app OTP for the current mobile number. */
    public function resendMobileOtp(Request $request, \App\Services\MobileOtpService $otps): JsonResponse
    {
        abort_if($request->user()->mobile_verified_at !== null, 409, 'Mobile is already verified.');

        $otps->issue($request->user(), $request->user()->mobile);

        return response()->json(['message' => 'A new code has been sent to your notifications.']);
    }

    /** Verify a new/changed email address with the code sent to that inbox. */
    public function verifyEmailOtp(Request $request, \App\Services\MobileOtpService $otps): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:8']]);

        $otp = $otps->verify($request->user(), $data['code'], 'verify_email');

        $request->user()->fresh()->forceFill([
            'email' => $otp->mobile, // the pending address rides on the OTP row
            'email_verified_at' => now(),
        ])->save();

        return response()->json(['message' => 'Email address verified and active.']);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            // One field, three identities: mobile (+ISD), username, or email.
            'identifier' => ['required_without:email', 'string', 'max:255'],
            'email' => ['required_without:identifier', 'email'], // legacy clients
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $identifier = trim($credentials['identifier'] ?? $credentials['email']);
        $user = $this->resolveUser($identifier);

        if ($user && $user->password === null) {
            throw ValidationException::withMessages([
                'identifier' => ['This account has no password — use "Login with code" instead, or set a password in Settings.'],
            ]);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status === 'suspended') {
            throw ValidationException::withMessages([
                'identifier' => ['This account has been suspended. Contact support.'],
            ]);
        }

        $this->requireAnEmail($user);

        // The password says the right thing is known. A code says the right
        // person knows it — asked on a device that has never signed in
        // before, which is the moment a stolen password gets used.
        if ($this->needsSignInCode($request, $user)) {
            return $this->askForSignInCode($request, $user);
        }

        return $this->signIn($request, $user, $credentials['device_name'] ?? 'web');
    }

    /**
     * No e-mail, no sign-in.
     *
     * The address is what a sign-in code goes to, what a forgotten password
     * is recovered through, and how the account is told that somebody signed
     * in as them. An account without one cannot be secured or recovered —
     * it can only be lost — so the door stays shut until it has one.
     *
     * The message says what to do about it rather than only that it failed,
     * because the person reading it cannot fix this alone.
     */
    private function requireAnEmail(User $user): void
    {
        if ($user->email !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'identifier' => ['This account has no e-mail address, and sign-in now needs one — '
                . 'a code is sent there every time a new device is used. Ask your admin to add one to the account.'],
        ]);
    }

    /**
     * Does this sign-in need a code as well as the password?
     *
     * Three settings, because companies differ: 'off' is the old behaviour,
     * 'always' asks every time, and the default asks once per device and
     * then remembers it. The default is the one people actually live with —
     * a code on every login is a code people find a way around.
     */
    private function needsSignInCode(Request $request, User $user): bool
    {
        $mode = AppSetting::get('login_otp_mode');

        // No 'no e-mail' escape here any more: requireAnEmail() has already
        // turned such an account away, so a code always has somewhere to go.
        if ($mode === 'off') {
            return false;
        }
        if ($mode === 'always') {
            return true;
        }

        return TrustedDevice::findLive($user, $request->header('X-Device-Token')) === null;
    }

    /**
     * Send the code and say so — without saying anything a stranger could
     * use. The address is masked: somebody with a stolen password should
     * not learn the mailbox to attack next.
     */
    private function askForSignInCode(Request $request, User $user): JsonResponse
    {
        $otp = app(\App\Services\MobileOtpService::class)->issueSignInCode(
            $user,
            $request->input('device_name') ?: $this->describeDevice($request),
        );

        return response()->json([
            'message' => 'Enter the code we sent to ' . $this->maskEmail($user->email) . '.',
            'otp_required' => true,
            'sent_to' => $this->maskEmail($user->email),
            'expires_in_minutes' => (int) now()->diffInMinutes($otp->expires_at),
        ], 202);
    }

    /**
     * The second half of a password login: the code, and from here on this
     * device is trusted so the next sign-in is one step again.
     */
    public function verifySignInCode(Request $request, \App\Services\MobileOtpService $otps): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:8'],
            'device_name' => ['nullable', 'string', 'max:255'],
            // Ticked off on a shared machine, this device is not remembered.
            'remember_device' => ['nullable', 'boolean'],
        ]);

        $user = $this->resolveUser(trim($data['identifier']));
        if (! $user || $user->status === 'suspended') {
            throw ValidationException::withMessages(['code' => ['That code is not valid.']]);
        }

        $this->requireAnEmail($user);

        try {
            $otps->verify($user, $data['code'], 'login');
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['code' => ['That code is wrong or has expired.']]);
        }

        $deviceToken = null;
        if ($data['remember_device'] ?? true) {
            [, $deviceToken] = TrustedDevice::issueFor(
                $user,
                ($data['device_name'] ?? null) ?: $this->describeDevice($request),
                $request->ip(),
                (int) AppSetting::get('trusted_device_days'),
            );
        }

        return $this->signIn($request, $user, $data['device_name'] ?? 'web', $deviceToken);
    }

    /** Everything a completed sign-in does, however it was completed. */
    private function signIn(Request $request, User $user, string $deviceName, ?string $deviceToken = null): JsonResponse
    {
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken($deviceName)->plainTextToken;

        $this->recordLogin($request, $user);

        return response()->json(array_filter([
            'message' => 'Login successful.',
            'data' => new UserResource($user->load(['profile', 'settings', 'appId', 'roles'])),
            'token' => $token,
            // Handed over once. The browser keeps it; the server keeps only
            // a hash, so this is the single moment it exists in the clear.
            'device_token' => $deviceToken,
            'must_change_password' => (bool) $user->force_password_change,
        ], fn ($v) => $v !== null));
    }

    /** Something the recipient of the code will recognise as themselves. */
    private function describeDevice(Request $request): string
    {
        $agent = (string) $request->userAgent();
        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            preg_match('/iPhone|iPad|iOS/i', $agent) === 1 => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'an unrecognised device',
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'an app',
        };

        return $browser . ' on ' . $platform;
    }

    /** h***h@grapmail.com — enough to recognise, not enough to attack. */
    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $kept = mb_substr($name, 0, 1) . str_repeat('*', max(1, mb_strlen($name) - 2)) . mb_substr($name, -1);

        return $domain === '' ? $kept : $kept . '@' . $domain;
    }

    /**
     * Passwordless login, step 1: request a one-time code. Delivered app-to-app
     * (in-app inbox on any signed-in device; admins can view/relay it).
     */
    public function requestLoginOtp(Request $request, \App\Services\MobileOtpService $otps): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']]);

        $user = $this->resolveUser(trim($data['identifier']));

        // Per-account cap: at most 3 login codes per 15 minutes, regardless of
        // requesting IP — prevents inbox flooding of a targeted account.
        $withinCap = ! $user || \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'login')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count() < 3;

        if ($user && $user->status !== 'suspended' && $user->email !== null && $withinCap) {
            /*
             * Emailed, because the person asking is locked out.
             *
             * This used to call issue(), whose notification goes to the
             * database and the broadcast channel and nowhere else — the
             * in-app bell. You have to be signed in to read the bell, and
             * everybody who presses "Sign in with a code" is by definition
             * not signed in. The code was delivered to the one place its
             * reader could not reach.
             *
             * issueSignInCode() is the path the password login's second step
             * already used: it emails, through the employer's own mailbox for
             * company staff and the platform's for everybody else, and still
             * rings the bell for any device already signed in.
             */
            $otps->issueSignInCode($user);
        }

        // Uniform response — never leak whether the identifier exists or
        // whether the cap was hit.
        return response()->json([
            'message' => 'If the account exists, a sign-in code has been emailed to it.',
        ]);
    }

    /** Passwordless login, step 2: exchange the code for a session token. */
    public function loginWithOtp(Request $request, \App\Services\MobileOtpService $otps): JsonResponse
    {
        $credentials = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:8'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->resolveUser(trim($credentials['identifier']));

        if (! $user || $user->status === 'suspended') {
            throw ValidationException::withMessages([
                'identifier' => ['The provided credentials are incorrect.'],
            ]);
        }

        $otps->verify($user, $credentials['code'], 'login');

        // A successful OTP login also proves possession → mobile is verified.
        $user->forceFill(['mobile_verified_at' => $user->mobile_verified_at ?? now()])->save();
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken($credentials['device_name'] ?? 'web')->plainTextToken;
        $this->recordLogin($request, $user);

        return response()->json([
            'message' => 'Login successful.',
            'data' => new UserResource($user->load(['profile', 'settings', 'appId', 'roles'])),
            'token' => $token,
            'must_change_password' => (bool) $user->force_password_change,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        LoginHistory::where('user_id', $request->user()->id)
            ->whereNull('logged_out_at')
            ->latest('logged_in_at')
            ->first()
            ?->update(['logged_out_at' => now()]);

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Uniform response — do not leak whether the email exists.
        return response()->json([
            'message' => 'If that email address exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed',
                /*
                 * ->uncompromised() checks the password against the
                 * HaveIBeenPwned breach corpus, by k-anonymity: the
                 * first five characters of its hash go out, never the
                 * password. A password already in a public breach list
                 * is the first thing an attacker tries, and eight
                 * characters with a letter and a digit describes most
                 * of them. Laravel treats an unreachable API as a pass,
                 * so this cannot lock anybody out.
                 */
                PasswordRule::min(8)->letters()->numbers()->uncompromised()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => 'Password has been reset. Please log in.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        // First-time set (passwordless account) needs no current password.
        $rules = ['password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]];
        if ($user->password !== null) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        $data = $request->validate($rules);

        $user->update(['password' => $data['password'], 'force_password_change' => false]);

        // Revoke every other session/token (transient tokens have no id).
        $current = $user->currentAccessToken();
        $currentId = $current instanceof \Laravel\Sanctum\PersonalAccessToken ? $current->id : null;
        $user->tokens()
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.']);
    }

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return response()->json(['message' => 'Email verified.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()->id;

        return response()->json([
            'data' => $request->user()->tokens()->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at,
                'created_at' => $t->created_at,
                'is_current' => $t->id === $current,
            ]),
        ]);
    }

    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json(['message' => 'Session revoked.']);
    }

    public function loginHistory(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->loginHistories()->latest('logged_in_at')->paginate(20)
        );
    }

    /** Match an identifier against email or username (mobile is records-only). */
    protected function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::where('email', $identifier)->first();
        }

        return User::whereRaw('LOWER(username) = ?', [mb_strtolower($identifier)])->first();
    }

    protected function recordLogin(Request $request, User $user): void
    {
        LoginHistory::create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'device_name' => $request->input('device_name', 'web'),
            'logged_in_at' => now(),
        ]);
    }
}
