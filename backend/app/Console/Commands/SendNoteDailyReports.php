<?php

namespace App\Console\Commands;

use App\Mail\NoteDailyReport;
use App\Models\Note;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * A note that changed yesterday, in this morning's post.
 *
 * Projects have sent their ledger this way from the start; notes - where
 * people keep the very thing they are about to forget - had no such letter.
 * A note somebody shares with three colleagues changes without them, and
 * nothing told anybody.
 *
 * Only notes that actually moved, because a daily letter that arrives on
 * days with nothing in it is a daily letter people stop opening.
 */
class SendNoteDailyReports extends Command
{
    protected $signature = 'mypa:note-daily-reports';

    protected $description = 'Email a daily copy of notes that changed (only on days they changed)';

    public function handle(): int
    {
        $sent = 0;

        Note::where('daily_report', true)
            ->with('user')
            ->chunkById(100, function ($notes) use (&$sent) {
                foreach ($notes as $note) {
                    $owner = $note->user;
                    if (! $owner?->email || ! $owner->email_verified_at) {
                        continue;
                    }

                    // Nothing since the last letter means no letter today.
                    $since = $note->last_reported_at;
                    if ($since && $note->updated_at <= $since) {
                        continue;
                    }

                    /*
                     * A password on a note is a password against e-mail too.
                     *
                     * Mail is the least private place a note can end up -
                     * it sits in an inbox, on a phone lock screen, in a
                     * backup - so a note somebody thought worth locking is
                     * not posted out in plain text. The letter says it
                     * changed and leaves the reading to the app.
                     */
                    Mail::to($owner->email)->queue(new NoteDailyReport($note, $note->isLocked()));
                    $note->updateQuietly(['last_reported_at' => now()]);
                    $sent++;
                }
            });

        $this->info("Note reports sent: {$sent}");

        return self::SUCCESS;
    }
}
