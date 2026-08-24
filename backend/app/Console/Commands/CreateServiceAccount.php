<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Make an account for an application, in one step, with nothing to click.
 *
 * Doing it by hand meant registering as though the application were a person:
 * an inbox to receive the confirmation code, a password somebody has to keep,
 * a profile nobody will ever read. All of that is ceremony for a thing that
 * only needs an identity to send from and a token to send with.
 *
 * The email is marked confirmed here rather than mailed, deliberately. The
 * confirmation step exists to prove a human owns an address; there is no human
 * and often no real address, and leaving it unconfirmed would block the
 * account from the very endpoints it exists to call.
 */
class CreateServiceAccount extends Command
{
    protected $signature = 'mypa:service-account:create
        {name : Display name, e.g. "Grapme Alerts" — this is what people see the messages from}
        {--username= : Handle to connect to; defaults to a slug of the name}
        {--email= : Only if you want one; a local placeholder is used otherwise}';

    protected $description = 'Create an account for an application and print its first token';

    public function handle(AppIdService $appIds): int
    {
        $name = trim($this->argument('name'));
        $username = Str::slug($this->option('username') ?: $name, '-');
        $email = trim((string) $this->option('email')) ?: "{$username}@service.local";

        if (User::where('username', $username)->orWhere('email', $email)->exists()) {
            $this->error("An account already exists with that username or email ({$username} / {$email}).");

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $username, $email, $appIds) {
            $user = User::create([
                'name' => $name,
                'username' => $username,
                'email' => $email,
                // Never used to sign in — the token is the credential. Random
                // rather than empty so nothing can be guessed into it.
                'password' => Str::random(64),
            ]);

            $user->forceFill([
                'is_service_account' => true,
                'email_verified_at' => now(),
            ])->save();

            $user->profile()->create(['timezone' => config('app.timezone'), 'language' => 'en']);
            $user->settings()->create([]);
            $appIds->generateFor($user);

            if ($role = Role::where('slug', 'user')->first()) {
                $user->roles()->attach($role->id);
            }

            return $user;
        });

        $token = $user->createToken('first token')->plainTextToken;

        $this->newLine();
        $this->info("Service account created: {$name}");
        $this->line("  Connect to it as : {$username}");
        $this->line('  App ID           : ' . $user->fresh()->appId?->app_id);
        $this->newLine();
        $this->warn('Token — shown once, and not recoverable. Copy it now:');
        $this->line("  {$token}");
        $this->newLine();
        $this->line('Paste it at ' . rtrim(config('mypa.frontend_url'), '/') . '/service/sign-in to reach');
        $this->line('the panel for this account — more tokens, who is connected, what has been sent.');

        return self::SUCCESS;
    }
}
