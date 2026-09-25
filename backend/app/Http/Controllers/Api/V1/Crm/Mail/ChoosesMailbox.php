<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Models\Crm\MailAccount;
use App\Models\Crm\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Which mailbox the screen is looking at.
 *
 * Every list in Mails reads `?account=` the same way: a mailbox's uuid, or
 * "all". Labels, addresses and filters belong to one mailbox, so they answer
 * for the chosen one - and for all of them together only when All mailboxes
 * is chosen, which is the one view where mixing them is the point.
 */
trait ChoosesMailbox
{
    /** @return Collection<int, MailAccount> */
    protected function mailboxes(Request $request, Member $me): Collection
    {
        $chosen = (string) $request->input('account', 'all');

        return MailAccount::for($me)
            ->when($chosen !== '' && $chosen !== 'all', fn ($q) => $q->where('uuid', $chosen))
            ->orderByDesc('is_default')
            ->get();
    }

    /** @return Collection<int, int> */
    protected function mailboxIds(Request $request, Member $me): Collection
    {
        return $this->mailboxes($request, $me)->pluck('id');
    }

    /**
     * The one mailbox being written to.
     *
     * Making a label or a filter needs a mailbox to make it in, so All
     * mailboxes is a reading view only and says so rather than guessing.
     */
    protected function oneMailbox(Request $request, Member $me, ?string $uuid = null): MailAccount
    {
        $chosen = $uuid ?? (string) $request->input('account', '');
        abort_if($chosen === '' || $chosen === 'all', 422, 'Choose which mailbox this belongs to first.');

        $account = MailAccount::for($me)->where('uuid', $chosen)->first();
        abort_unless($account, 404, 'That mailbox is not yours.');

        return $account;
    }
}
