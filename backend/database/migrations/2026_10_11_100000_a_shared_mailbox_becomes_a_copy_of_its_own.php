<?php

use App\Models\Crm\MailAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sharing becomes giving somebody their own.
 *
 * A mailbox everybody could see was one row with a list of names on it, and
 * it read that way on screen: somebody else's mailbox, lent out, with the
 * borrower unable to change a thing about it. What a company actually means
 * by "give sales@ to Priya" is that Priya has sales@ - set up for her by
 * her Admin, hers to correct, and behaving exactly like the mailbox she
 * added herself.
 *
 * So every share already made becomes a mailbox of that person's own,
 * pointing at the same server with the same sign-in, and the list of
 * borrowers empties.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_mail_account_member')) {
            return;
        }

        foreach (DB::table('crm_mail_account_member')->get() as $share) {
            $source = MailAccount::find($share->mail_account_id);
            if (! $source) {
                continue;
            }

            // Already has one of their own pointing at the same address.
            $held = MailAccount::where('member_id', $share->member_id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $source->email)])
                ->exists();
            if ($held) {
                continue;
            }

            $copy = $source->replicate(['uuid', 'sync_state', 'last_synced_at', 'last_error', 'sent_today', 'cap_date', 'detached_at']);
            $copy->fill([
                'member_id' => $share->member_id,
                'created_by_member_id' => $source->member_id,
                'is_default' => ! MailAccount::where('member_id', $share->member_id)->exists(),
                'is_shared' => false,
                'status' => 'active',
                // Their signature on the share, if they wrote one, is theirs.
                'signature_html' => $share->signature_html ?: null,
            ]);
            $copy->save();
        }

        DB::table('crm_mail_account_member')->delete();
        // Nothing is shared any more; the flag went with it.
        MailAccount::where('is_shared', true)->update(['is_shared' => false]);
    }

    public function down(): void
    {
        // The copies are ordinary mailboxes now, and taking somebody's
        // mailbox away is not something a rollback should decide.
    }
};
