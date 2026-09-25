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
 *                    folder, and on to whatever else the company chose, and
 *                    empty what has sat in Trash and Spam long enough.
 */
class MailsTick extends Command
{
    /** How long deleted mail is kept before it goes for good. */
    public const KEEP_DELETED_DAYS = 30;

    protected $signature = 'mails:tick {what : dispatch, sync or backup}';

    protected $description = 'Send due mail (dispatch), bring mailboxes up to date (sync), or archive them (backup)';

    public function handle(): int
    {
        if ($this->argument('what') === 'backup') {
            $this->emptyOldRubbish();

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
                // A mailbox that has failed several times running is being
                // left alone for a while. It is still listed, still shows
                // its error, and still syncs the moment somebody presses
                // Refresh - it is simply not asked every five minutes.
                ->where(fn ($q) => $q->whereNull('sync_paused_until')->orWhere('sync_paused_until', '<=', now()))
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

        /*
         * Mail left mid-send.
         *
         * A worker that dies between claiming a message and sending it leaves
         * it as "sending", which nothing else would ever look at again. After
         * ten minutes it is presumed dropped and put back in the queue - the
         * message id it was given is kept, so a message that did go out is
         * not sent twice under a new one.
         */
        $stuck = MailMessage::where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'queued', 'error' => 'Sending was interrupted - trying again.']);
        if ($stuck > 0) {
            $this->warn("{$stuck} message(s) were left mid-send and have been queued again.");
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

    /**
     * Trash and Spam empty themselves after a month.
     *
     * The same month every mail program gives you, and for the same reason:
     * deleted mail is kept long enough to be got back by somebody who
     * deleted it in error, and not so long that a mailbox is mostly rubbish.
     * It goes from the server too, so the two sides agree.
     *
     * Archived mail is never touched. Archiving is what somebody does when
     * they want to keep a thing without looking at it.
     */
    private function emptyOldRubbish(): void
    {
        $cutoff = now()->subDays(self::KEEP_DELETED_DAYS);
        $gone = 0;

        MailMessage::whereIn('folder', ['trash', 'spam'])
            ->where('updated_at', '<', $cutoff)
            ->chunkById(200, function ($messages) use (&$gone) {
                foreach ($messages as $message) {
                    if ($message->remote_folder && $message->uid) {
                        \App\Jobs\MailRemoteChange::dispatch(
                            $message->mail_account_id, $message->remote_folder, (int) $message->uid, 'delete',
                        );
                    }
                    $message->delete();
                    $gone++;
                }
            });

        if ($gone > 0) {
            $this->info($gone . ' message(s) older than ' . self::KEEP_DELETED_DAYS . ' days emptied from Trash and Spam.');
        }
    }
}
