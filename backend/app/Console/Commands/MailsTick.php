<?php

namespace App\Console\Commands;

use App\Jobs\BackupMailAccount;
use App\Jobs\SendMailMessage;
use App\Jobs\SyncMailAccount;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use Illuminate\Console\Command;

/**
 * The clock Mails runs on.
 *
 *   mails:dispatch - every minute: send whatever is queued and due. The
 *                    delayed job normally does it to the second; this is the
 *                    net under it, for a queue that restarted mid-wait.
 *   mails:sync     - every few minutes: bring every mailbox up to date, one
 *                    queued job per mailbox so a slow server delays nobody else.
 *   mails:backup   - once a night: write each mailbox out to its archive
 *                    folder, and on to whatever else the company chose.
 */
class MailsTick extends Command
{
    protected $signature = 'mails:tick {what : dispatch, sync or backup}';

    protected $description = 'Send due mail (dispatch), bring mailboxes up to date (sync), or archive them (backup)';

    public function handle(): int
    {
        if ($this->argument('what') === 'backup') {
            $count = 0;
            MailAccount::whereHas('member.organization', fn ($o) => $o->where('mails_enabled', true)->where('status', 'active'))
                ->pluck('id')
                ->each(function (int $id) use (&$count) {
                    BackupMailAccount::dispatch($id);
                    $count++;
                });
            $this->info("{$count} mailbox(es) queued for backup.");

            return self::SUCCESS;
        }

        if ($this->argument('what') === 'sync') {
            $count = 0;
            MailAccount::where('status', 'active')->whereNotNull('imap_host')
                ->whereHas('member', fn ($m) => $m->where('status', 'active'))
                ->whereHas('member.organization', fn ($o) => $o->where('mails_enabled', true)->where('status', 'active'))
                ->pluck('id')
                ->each(function (int $id) use (&$count) {
                    SyncMailAccount::dispatch($id);
                    $count++;
                });
            $this->info("{$count} mailbox(es) queued for sync.");

            return self::SUCCESS;
        }

        $due = MailMessage::where('status', 'queued')
            ->where(fn ($q) => $q->where('send_after', '<=', now())->orWhere(fn ($q) => $q->whereNull('send_after')->where('scheduled_for', '<=', now())))
            ->pluck('id');
        foreach ($due as $id) {
            SendMailMessage::dispatch($id);
        }
        $this->info(count($due) . ' due mail(s) dispatched.');

        return self::SUCCESS;
    }
}
