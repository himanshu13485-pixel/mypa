<?php

namespace App\Console\Commands;

use App\Services\ServiceAccountService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Make an account for an application, in one step, with nothing to click.
 *
 * Doing it by hand meant registering as though the application were a person:
 * an inbox to receive the confirmation code, a password somebody has to keep,
 * a profile nobody will ever read. All of that is ceremony for a thing that
 * only needs an identity to send from and a token to send with.
 *
 * The admin panel does the same job from a screen. Both call the same service,
 * because an account made from a shell and one made from a browser should be
 * the same kind of thing.
 */
class CreateServiceAccount extends Command
{
    protected $signature = 'mypa:service-account:create
        {name : Display name, e.g. "Grapme Alerts" — this is what people see the messages from}
        {--username= : Handle to connect to; defaults to a slug of the name}
        {--email= : Only if you want one; a local placeholder is used otherwise}';

    protected $description = 'Create an account for an application and print its first token';

    public function handle(ServiceAccountService $accounts): int
    {
        try {
            ['user' => $user, 'token' => $token] = $accounts->create(
                trim($this->argument('name')),
                $this->option('username'),
                $this->option('email'),
            );
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Service account created: {$user->name}");
        $this->line("  Connect to it as : {$user->username}");
        $this->line('  App ID           : ' . $user->appId?->app_id);
        $this->newLine();
        $this->warn('Token — shown once, and not recoverable. Copy it now:');
        $this->line("  {$token}");
        $this->newLine();
        $this->line('Paste it at ' . rtrim(config('mypa.frontend_url'), '/') . '/service/sign-in to reach');
        $this->line('the panel for this account — more tokens, who is connected, what has been sent.');

        return self::SUCCESS;
    }
}
