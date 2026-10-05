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
                                            {--account= : One mailbox, by its email address}';

    protected $description = 'Re-read message dates from the mail server, for mail filed before dates were converted';

    public function handle(MailConnector $connector): int
    {
        $pretend = (bool) $this->option('pretend');

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
                 * Headers only. The date is in them, the body is what makes
                 * a sync slow, and nothing here needs reading.
                 */
                try {
                    $remote = $folder->query()->leaveUnread()->setFetchBody(false)->softFail()->all()->get();
                } catch (Throwable $e) {
                    $this->warn('   ' . $path . ': ' . MailConnector::plain($e));
                    continue;
                }

                $dates = [];
                foreach ($remote as $one) {
                    $dates[(int) $one->uid] = $one->date?->first()?->toDate();
                }

                MailMessage::where('mail_account_id', $account->id)
                    ->where('remote_folder', $path)->whereNotNull('uid')
                    ->chunkById(200, function ($messages) use ($dates, $pretend, &$fixed, &$gone, &$same) {
                        foreach ($messages as $message) {
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
                            if (! $pretend) {
                                $message->forceFill(['date' => $right])->saveQuietly();
                            }
                        }
                    });
            }

            $client->disconnect();
        }

        $this->info(($pretend ? 'Would correct ' : 'Corrected ') . $fixed . ' message(s); '
            . $same . ' were already right; ' . $gone . ' are no longer on the server.');

        return self::SUCCESS;
    }
}
