<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Mark an account as one an application signs in as, rather than a person.
 *
 * A console command rather than a screen: this is set once when an integration
 * is first wired up, by whoever has the server, and putting it in the admin UI
 * would make it something that can be switched on by mistake — on an account
 * that belongs to somebody.
 *
 * The only effect is that connection requests to it are accepted the moment
 * they arrive, because nobody is going to accept them for it.
 */
class MakeServiceAccount extends Command
{
    protected $signature = 'mypa:service-account {identifier : App ID, username or email} {--off : Turn it back into an ordinary account}';

    protected $description = 'Mark an account as a service account (auto-accepts connection requests)';

    public function handle(): int
    {
        $id = trim($this->argument('identifier'));

        $user = User::with('appId')
            ->where(fn ($q) => $q->whereHas('appId', fn ($a) => $a->where('app_id', $id))
                ->orWhere('username', $id)
                ->orWhere('email', $id))
            ->first();

        if (! $user) {
            $this->error("No account found for “{$id}”.");

            return self::FAILURE;
        }

        $on = ! $this->option('off');

        if ((bool) $user->is_service_account === $on) {
            $this->info("{$user->name} is already " . ($on ? 'a service account.' : 'an ordinary account.'));

            return self::SUCCESS;
        }

        // Not fillable, deliberately — nothing a request can reach should be
        // able to set this.
        $user->forceFill(['is_service_account' => $on])->save();

        $this->info(($on ? 'Service account: ' : 'Ordinary account: ')
            . "{$user->name} ({$user->appId?->app_id})");

        if ($on) {
            $this->line('Connection requests to it are now accepted as they arrive.');
        }

        return self::SUCCESS;
    }
}
