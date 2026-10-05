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
        $stopped = [];

        foreach ($accounts as $account) {
            $this->line($account->email);

            try {
                $client = $connector->imap($account);
            } catch (Throwable $e) {
                $this->warn('   could not be reached: '.MailConnector::plain($e));

                continue;
            }

            $folders = MailMessage::where('mail_account_id', $account->id)
                ->whereNotNull('remote_folder')->whereNotNull('uid')
                ->distinct()->pluck('remote_folder');

            /*
             * A host that hangs up costs its own mailbox, not the run.
             *
             * One server dropping the connection used to end everything -
             * the command died where it stood, and whoever started it had
             * no idea how far it had got or which mailboxes were still
             * untouched. Each mailbox is now its own attempt, and the one
             * that failed is named at the end.
             */
            try {
                foreach ($folders as $path) {
                    $folder = $client->getFolderByPath($path);
                    if (! $folder) {
                        continue;
                    }

                    $ours = MailMessage::where('mail_account_id', $account->id)
                        ->where('remote_folder', $path)->whereNotNull('uid')
                        ->when($days, fn ($q) => $q->where('date', '>=', now()->subDays($days)))
                        ->orderBy('uid')
                        ->get(['id', 'uid', 'date']);

                    if ($ours->isEmpty()) {
                        continue;
                    }

                    /*
                     * One request for the whole folder, not one per message.
                     *
                     * Asked one at a time this took minutes a mailbox and fell
                     * over whenever a mail host hung up part way through - and
                     * it was never necessary: IMAP hands back a batch of
                     * headers in a single command, which is exactly what the
                     * five-minute sync has always done. Headers only; the
                     * bodies are what make a sync slow and nothing here reads
                     * them.
                     *
                     * From the lowest uid we hold, so mail older than this
                     * window is never asked for.
                     */
                    try {
                        $remote = $folder->query()->leaveUnread()->setFetchBody(false)->softFail()
                            ->getByUidGreater(max(0, (int) $ours->first()->uid - 1));
                    } catch (Throwable $e) {
                        $this->warn('   '.$path.': '.MailConnector::plain($e));

                        continue;
                    }

                    $dates = [];
                    foreach ($remote as $one) {
                        $dates[(int) $one->uid] = $one->date?->first()?->toDate();
                    }

                    $here = 0;
                    foreach ($ours as $message) {
                        $raw = $dates[(int) $message->uid] ?? null;
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
                        $here++;
                        if (! $pretend) {
                            $message->forceFill(['date' => $right])->saveQuietly();
                        }
                    }

                    $this->line('   '.$path.' — '.$ours->count().' checked, '.$here.' put right');
                }
            } catch (Throwable $e) {
                $stopped[] = $account->email;
                $this->warn('   stopped part way: '.MailConnector::plain($e));
            }

            try {
                $client->disconnect();
            } catch (Throwable) {
                // Already gone, which is how we got here.
            }
        }

        $this->info(($pretend ? 'Would correct ' : 'Corrected ').$fixed.' message(s); '
            .$same.' were already right; '.$gone.' are no longer on the server.');

        if ($stopped) {
            // Named, because a run that was cut short in the middle of a
            // mailbox and a run that finished look identical otherwise.
            $this->warn('Run again for: '.implode(', ', $stopped));
        }

        return self::SUCCESS;
    }
}
