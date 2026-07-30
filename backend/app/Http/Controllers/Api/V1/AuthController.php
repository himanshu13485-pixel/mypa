<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\LoginHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
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
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Mobile-first identity: ISD code + national number are required.
            'country_code' => ['required', 'string', 'regex:/^\+[0-9]{1,4}$/'],
            'mobile' => ['required', 'string', 'regex:/^[0-9]{6,14}$/'],
            // Alphanumeric handle, no special characters (login + search identity).
            'username' => ['required', 'string', 'min:4', 'max:20', 'regex:/^[a-zA-Z0-9]+$/'],
            // Email is optional — it can be added later from the profile.
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            // Passwordless signup: the account is secured by mobile OTP; a
            // password can optionally be set later from Settings.
            'password' => ['nullable', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:64'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            'language' => ['nullable', 'string', 'max:8'],
            'account_type' => ['nullable', 'in:personal,business'],
            'referral_app_id' => ['nullable', 'string', 'max:32'],
        ]);

        $fullMobile = $data['country_code'] . $data['mobile'];
        $username = mb_strtolower($data['username']);

        if (User::where('mobile', $fullMobile)->exists()) {
            throw ValidationException::withMessages([
                'mobile' => ['This mobile number is already registered.'],
            ]);
        }
        if (User::whereRaw('LOWER(username) = ?', [$username])->exists()) {
            throw ValidationException::withMessages([
                'username' => ['This username is taken.'],
            ]);
        }

        $user = DB::transaction(function () use ($data, $appIds, $fullMobile, $username) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $username,
                'email' => $data['email'] ?? null,
                'mobile' => $fullMobile,
                'country_code' => $data['country_code'],
                'password' => $data['password'] ?? null,
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

        if ($user->email) {
            $user->sendEmailVerificationNotification();
        }

        // App-to-app OTP: delivered as an in-app notification, visible in the
        // notification bell and to admins (no SMS network involved).
        app(\App\Services\MobileOtpService::class)->issue($user, $user->mobile);

        $token = $user->createToken($request->input('device_name', 'web'))->plainTextToken;

        $this->recordLogin($request, $user);

        return response()->json([
            'message' => 'Registration successful. Check your in-app notifications for the mobile verification code.',
            'data' => new UserResource($user->load(['profile', 'settings', 'appId', 'roles'])),
            'token' => $token,
            'mobile_verification_pending' => true,
        ], 201);
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

        if ($user && $user->status !== 'suspended' && $withinCap) {
            $otps->issue($user, $user->mobile, 'login');
        }

        // Uniform response — never leak whether the identifier exists or
        // whether the cap was hit.
        return response()->json([
            'message' => 'If the account exists, a login code has been sent to its app inbox.',
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
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
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

    /** Match an identifier against email, mobile (with/without +), or username. */
    protected function resolveUser(string $identifier): ?User
    {
        if (str_contains($identifier, '@')) {
            return User::where('email', $identifier)->first();
        }

        $digits = preg_replace('/[\s\-()]/', '', $identifier);
        if (preg_match('/^\+?[0-9]{6,16}$/', $digits)) {
            return User::where('mobile', $digits)
                ->orWhere('mobile', '+' . ltrim($digits, '+'))
                ->first();
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
