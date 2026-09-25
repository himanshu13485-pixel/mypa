<?php

namespace App\Jobs;

use App\Models\Crm\MailAccount;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One mailbox, brought up to date.
 *
 * Unique per mailbox: pressing Refresh while the five-minute sync is
 * already running for it queues nothing more, instead of two syncs racing
 * each other over the same folders.
 *
 * On the "mail" queue, not the one everything else uses. Talking to
 * somebody else's IMAP server takes seconds at best, and a worker does one
 * job at a time: on the shared queue a slow mail host held up outgoing
 * mail, push notifications and chat until it finally answered.
 */
class SyncMailAccount implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /*
     * Lower than it was, and safe to be: a run that is cut off no longer
     * loses its place - each folder's position is kept as it is reached -
     * so one unreachable mailbox stops holding the mail queue for a full
     * five minutes before the worker is killed and started again.
     */
    public int $timeout = 150;

    public int $uniqueFor = 300;

    public function __construct(public int $accountId)
    {
        $this->onQueue('mail');
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

    /**
     * The job died outright - nearly always the timeout above.
     *
     * A failure that is caught counts itself, in MailSync. One that kills
     * the job never reaches that code, and a mailbox that times out every
     * time is precisely the one worth asking less often, so it is counted
     * here as well.
     */
    public function failed(?Throwable $e): void
    {
        $account = MailAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        $account->update(['last_error' => $e ? MailConnector::plain($e) : 'This mailbox took too long to answer.']);
        $account->noteSyncFailure();
    }
}
