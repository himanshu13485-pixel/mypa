<?php

namespace App\Jobs;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailBackupRun;
use App\Services\Mail\MailArchive;
use App\Services\Mail\MailVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One mailbox archived: to its folder on this server first, and from there
 * to wherever else the company chose.
 *
 * Unique per mailbox, so a nightly run and somebody pressing "Back up now"
 * cannot write the same files twice over each other.
 */
class BackupMailAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public int $accountId, public bool $full = false, public ?int $runId = null)
    {
        // Reads a whole mailbox over IMAP: the mail queue, never the one
        // that outgoing mail and notifications wait on.
        $this->onQueue('mail');
    }

    public function uniqueId(): string
    {
        return 'mail-backup-' . $this->accountId;
    }

    public function handle(MailArchive $archive, MailVault $vault): void
    {
        $account = MailAccount::with('organization')->find($this->accountId);
        if (! $account) {
            return;
        }

        $run = $this->runId ? MailBackupRun::find($this->runId) : MailBackupRun::create([
            'organization_id' => $account->organization_id,
            'mail_account_id' => $account->id,
            'kind' => 'backup',
            'destination' => 'server',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $result = $archive->backup($account, $this->full, $run);
            $pushed = $vault->push($account->fresh(), $archive);

            $run?->forceFill([
                'status' => 'done',
                'destination' => $pushed['skipped'] ? 'server' : (string) (($account->fresh()->backup['remote']['driver'] ?? 'server')),
                'messages' => $result['messages'],
                'bytes' => $result['bytes'],
                'path' => $result['folder'],
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $settings = (array) ($account->fresh()->backup ?? []);
            $account->forceFill(['backup' => array_merge($settings, ['last_error' => \Illuminate\Support\Str::limit($e->getMessage(), 300)])])->save();

            $run?->forceFill(['status' => 'failed', 'error' => \Illuminate\Support\Str::limit($e->getMessage(), 1000), 'finished_at' => now()])->save();
        }
    }
}
