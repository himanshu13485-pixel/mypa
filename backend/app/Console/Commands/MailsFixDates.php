<?php

namespace App\Console\Commands;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * The mail already filed under the wrong hour, put right.
 *
 * Dates arriving from senders abroad were written as the sender's wall
 * clock and read back as ours, so a mail from London at nine in the
 * morning sat in the list as nine rather than half past two. That is
 * fixed for mail arriving from now on; this is for the mail already here.
 *
 * It cannot be worked out from what was stored - the offset was the thing
 * thrown away - so each date is read again from the mail server, which
 * still has it. Only messages still on the server can be corrected; one
 * deleted there keeps the hour it was given, and says so in the count at
 * the end rather than being silently skipped.
 *
 * Run it with --pretend first if you would rather see the damage before
 * anything is written:
 *
 *   php artisan mails:fix-dates --pretend
 */
class MailsFixDates extends Command
{
    protected $signature = 'mails:fix-dates {--pretend : Say what would change, change nothing}
                                            {--account= : One mailbox, by its email address}
                                            {--days= : Only mail this many days old or newer}';

    protected $description = 'Re-read message dates from the mail server, for mail filed before dates were converted';

    public function handle(MailConnector $connector): int
    {
        $pretend = (bool) $this->option('pretend');
        // Recent mail is what anybody is actually reading; the archive can
        // wait, or be done overnight in its own run.
        $days = (int) $this->option('days') ?: null;

        $accounts = MailAccount::where('status', 'active')->whereNotNull('imap_host')
            ->when($this->option('account'), fn ($q, $email) => $q->where('email', $email))
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No mailbox to look at.');

            return self::SUCCESS;
        }

        $fixed = $gone = $same = 0;

        foreach ($accounts as $account) {
            $this->line($account->email);

            try {
                $client = $connector->imap($account);
            } catch (Throwable $e) {
                $this->warn('   could not be reached: ' . MailConnector::plain($e));
                continue;
            }

            $folders = MailMessage::where('mail_account_id', $account->id)
                ->whereNotNull('remote_folder')->whereNotNull('uid')
                ->distinct()->pluck('remote_folder');

            foreach ($folders as $path) {
                $folder = $client->getFolderByPath($path);
                if (! $folder) {
                    continue;
                }

                /*
                 * Only the messages we actually hold, and only the headers.
                 *
                 * Asking the folder for everything meant waiting on mail
                 * this app has never seen, with nothing on screen while it
                 * went - which reads as a hang rather than as work. The
                 * uids are already here, so they are what is asked for.
                 */
                $ours = MailMessage::where('mail_account_id', $account->id)
                    ->where('remote_folder', $path)->whereNotNull('uid')
                    ->when($days, fn ($q) => $q->where('date', '>=', now()->subDays($days)))
                    ->orderBy('id')
                    ->get(['id', 'uid', 'date']);

                if ($ours->isEmpty()) {
                    continue;
                }

                $this->line('   ' . $path . ' — ' . $ours->count() . ' to check');
                $bar = $this->output->createProgressBar($ours->count());

                foreach ($ours as $message) {
                    $bar->advance();

                    try {
                        $remote = $folder->query()->leaveUnread()->setFetchBody(false)->softFail()
                            ->getMessageByUid((int) $message->uid);
                    } catch (Throwable) {
                        $remote = null;
                    }

                    $raw = $remote?->date?->first()?->toDate();
                    if (! $raw) {
                        $gone++;
                        continue;
                    }

                    $right = MailSync::localise($raw);
                    if ($message->date && $right->equalTo($message->date)) {
                        $same++;
                        continue;
                    }

                    $fixed++;
                    if (! $pretend) {
                        $message->forceFill(['date' => $right])->saveQuietly();
                    }
                }

                $bar->finish();
                $this->newLine();
            }

            $client->disconnect();
        }

        $this->info(($pretend ? 'Would correct ' : 'Corrected ') . $fixed . ' message(s); '
            . $same . ' were already right; ' . $gone . ' are no longer on the server.');

        return self::SUCCESS;
    }
}
