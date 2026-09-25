<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailFilter;
use App\Models\Crm\MailMessage;
use Illuminate\Support\Collection;

/**
 * Standing instructions, applied to mail as it arrives.
 *
 * Every active filter is asked about every arriving message, and every one
 * that matches has its say - so a mail can pick up two labels if two rules
 * fit it, which is how somebody thinking in categories expects it to work.
 */
class MailFilters
{
    /**
     * Run a mailbox's filters over one message.
     *
     * Returns how many matched, and leaves the message saved if any did.
     */
    public function apply(MailMessage $mail, ?Collection $filters = null): int
    {
        $filters ??= $this->forAccount($mail->mail_account_id);
        $matched = 0;
        $moved = false;

        foreach ($filters as $filter) {
            if (! $filter->matches($mail)) {
                continue;
            }

            $matched++;
            if ($filter->mail_label_id) {
                $mail->labels()->syncWithoutDetaching([$filter->mail_label_id]);
            }
            if ($filter->mark_read) {
                $mail->is_read = true;
            }
            if ($filter->star) {
                $mail->is_starred = true;
            }
            /*
             * Two instructions that can only apply while the mail is still
             * where it arrived. Filing an old message out of Archive back
             * into Inbox because a rule says "never spam" would be a filter
             * undoing somebody's own tidying.
             */
            if ($filter->never_spam && $mail->folder === 'spam') {
                $mail->folder = 'inbox';
                $moved = true;
            }
            if ($filter->skip_inbox && $mail->folder === 'inbox') {
                $mail->folder = 'archive';
                $moved = true;
            }

            $filter->forceFill([
                'matched_count' => $filter->matched_count + 1,
                'last_matched_at' => now(),
            ])->save();
        }

        if ($matched) {
            $mail->save();
        }

        return $moved || $matched ? $matched : 0;
    }

    /**
     * Run one filter back over mail already in the mailbox.
     *
     * Made for the moment a rule is written: the OTP label is no use until
     * the two hundred OTPs already sitting in the Inbox are wearing it.
     */
    public function backfill(MailFilter $filter, int $limit = 2000): int
    {
        if (! $filter->asks()) {
            return 0;
        }

        $touched = 0;
        MailMessage::where('mail_account_id', $filter->mail_account_id)
            ->whereNotIn('folder', ['drafts', 'scheduled', 'outbox'])
            ->orderByDesc('id')
            ->limit($limit)
            ->lazyById(200)
            ->each(function (MailMessage $mail) use ($filter, &$touched) {
                if ($this->apply($mail, collect([$filter]))) {
                    $touched++;
                }
            });

        return $touched;
    }

    /** @return Collection<int, MailFilter> */
    public function forAccount(?int $accountId): Collection
    {
        if (! $accountId) {
            return collect();
        }

        return MailFilter::where('mail_account_id', $accountId)->where('is_active', true)->get();
    }

    /** How many labels a mailbox may hold - see MailAccount::LABEL_CAP. */
    public function labelRoom(MailAccount $account): int
    {
        return MailAccount::LABEL_CAP - $account->labels()->count();
    }
}
