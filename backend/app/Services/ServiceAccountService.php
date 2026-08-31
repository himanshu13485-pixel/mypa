<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Making an account for an application.
 *
 * Shared by the console command and the admin panel so the two cannot drift:
 * an account made from a screen and one made from a shell should be the same
 * kind of thing, and the list of what has to be true of it — verified, flagged,
 * given an App ID, holding no usable password — is exactly the list that is
 * easy to get half right in a second implementation.
 */
class ServiceAccountService
{
    /** @return array{user: User, token: string} */
    public function create(string $name, ?string $username = null, ?string $email = null): array
    {
        $name = trim($name);
        $username = Str::slug($username ?: $name, '-');
        $email = trim((string) $email) ?: "{$username}@service.local";

        if ($username === '') {
            throw ValidationException::withMessages(['name' => 'That name has no letters or digits to make a handle from.']);
        }

        if (User::where('username', $username)->orWhere('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'name' => "An account already exists as “{$username}”. Choose a different name.",
            ]);
        }

        $user = DB::transaction(function () use ($name, $username, $email) {
            $user = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                // Never used to sign in — the token is the credential. Random
                // rather than blank so there is nothing to guess into.
                'password' => Str::random(64),
            ]);

            $user->forceFill([
                'is_service_account' => true,
                // Confirmation exists to prove a human owns an address. There
                // is no human, and leaving it pending would lock the account
                // out of the endpoints it was made to call.
                'email_verified_at' => now(),
            ])->save();

            $user->profile()->create(['timezone' => config('app.timezone'), 'language' => 'en']);
            $user->settings()->create([]);
            app(AppIdService::class)->generateFor($user);

            if ($role = Role::where('slug', 'user')->first()) {
                $user->roles()->attach($role->id);
            }

            return $user;
        });

        return [
            'user' => $user->fresh('appId'),
            'token' => $this->issueToken($user, 'first token'),
        ];
    }

    /**
     * Issue a token and keep a readable copy of it.
     *
     * The one place tokens are minted, so the keeping cannot be forgotten in
     * one of three call sites. Encrypted with APP_KEY: an admin can read it
     * back, a database dump on its own cannot.
     */
    public function issueToken(User $user, string $name): string
    {
        $new = $user->createToken($name);

        // Only ever for an application. A person's token is replaceable by
        // signing in again, so storing one buys nothing and risks the rest.
        if ($user->is_service_account) {
            $new->accessToken->forceFill([
                'encrypted_value' => Crypt::encryptString($new->plainTextToken),
            ])->save();
        }

        return $new->plainTextToken;
    }

    /** The readable token, for one issued since this became possible. */
    public function revealToken(PersonalAccessToken $token): ?string
    {
        if (! $token->encrypted_value) {
            return null;
        }

        try {
            return Crypt::decryptString($token->encrypted_value);
        } catch (DecryptException) {
            // Encrypted under a different APP_KEY, or damaged. Nothing to show
            // and nothing to be done but issue a new one.
            return null;
        }
    }

    /** Everything an admin needs to tell a working integration from a dead one. */
    public function summarise(User $user): array
    {
        $sent = \App\Models\Message::where('user_id', $user->id);

        return [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'username' => $user->username,
            'app_id' => $user->appId?->app_id,
            'tokens' => $user->tokens()->count(),
            'connections' => \App\Models\Connection::where('status', 'accepted')
                ->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('addressee_id', $user->id))
                ->count(),
            'messages_sent' => (clone $sent)->count(),
            'last_sent_at' => (clone $sent)->latest('id')->value('created_at'),
            'created_at' => $user->created_at,
        ];
    }
}
