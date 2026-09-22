<?php

namespace App\Jobs;

use App\Models\Crm\MailAccount;
use App\Services\Mail\MailSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One mailbox, brought up to date.
 *
 * Unique per mailbox: pressing Refresh while the five-minute sync is
 * already running for it queues nothing more, instead of two syncs racing
 * each other over the same folders.
 */
class SyncMailAccount implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(public int $accountId)
    {
    }

    public function uniqueId(): string
    {
        return 'mail-sync-' . $this->accountId;
    }

    public function handle(MailSync $sync): void
    {
        $account = MailAccount::find($this->accountId);
        if ($account && $account->status === 'active') {
            $sync->sync($account);
        }
    }
}
