<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bringing a mailbox's new mail in from its server.
 *
 * Folder by folder, only what is newer than last time - IMAP numbers every
 * message in a folder, and the highest number seen is all that needs
 * remembering. The first sync takes the most recent hundred per folder, so
 * a mailbox with ten years in it does not spend an hour arriving.
 *
 * New mail in the inbox is also where the away message and forwarding
 * happen: this app is not the mail server, so they happen as the mail is
 * brought in rather than as it lands.
 */
class MailSync
{
    /**
     * How many passes in a row a mailbox may fail on something that fixes
     * itself before the failure is put on screen. Syncs run every few
     * minutes, so this is roughly a quarter of an hour of bad weather.
     */
    public const COMPLAIN_AFTER = 3;

    /** Folder names as the common providers spell them, lower-cased. */
    public const FOLDER_NAMES = [
        'sent' => ['sent', 'sent items', 'sent mail', 'sent messages', 'sent-mail', 'sentmail'],
        'drafts' => ['drafts', 'draft'],
        'spam' => ['spam', 'junk', 'junk e-mail', 'junk email', 'junk mail', 'bulk mail'],
        'trash' => ['trash', 'deleted items', 'deleted messages', 'bin', 'deleted'],
        'archive' => ['archive', 'archives'],
    ];

    public function __construct(private MailConnector $connector, private MailSender $sender, private MailGuard $guard)
    {
    }

    /**
     * Which server folder is which of ours.
     *
     * By name, because the flags servers are meant to mark them with are not
     * something the IMAP library hands back - and "[Gmail]/Sent Mail",
     * "INBOX.Sent" and "Sent Items" all end in a name worth recognising.
     *
     * @param  iterable<string>  $paths
     * @return array<string, string>  our folder => the server's path
     */
    public static function mapFolders(iterable $paths): array
    {
        $map = [];
        foreach ($paths as $path) {
            if (strcasecmp($path, 'INBOX') === 0) {
                $map['inbox'] = $path;
                continue;
            }
            $leaf = strtolower(trim((string) preg_replace('/^.*[\/.]/', '', $path)));
            foreach (self::FOLDER_NAMES as $ours => $names) {
                if (! isset($map[$ours]) && in_array($leaf, $names, true)) {
                    $map[$ours] = $path;
                }
            }
        }

        return $map;
    }

    /** @return array{fetched: int, error: ?string} */
    public function sync(MailAccount $account, int $firstRun = 100): array
    {
        if (! $account->canReceive()) {
            return ['fetched' => 0, 'error' => null];
        }

        /*
         * Room to put it.
         *
         * Checked before a run rather than mid-message, so a mailbox never
         * holds half of one. Nothing is deleted to make space - the person
         * is told, and either clears some out or is given more.
         */
        if ($account->member && MailAccess::isFull($account->member)) {
            $limit = MailAccess::storageFor($account->member);
            $account->update(['last_error' => "This mailbox is full ({$limit} MB). Empty the trash or ask your Admin for more room - nothing new is being fetched."]);

            return ['fetched' => 0, 'error' => 'Mailbox full'];
        }

        $fetched = 0;
        $arrived = [];

        try {
            $client = $this->connector->imap($account);
            $paths = [];
            foreach ($client->getFolders(false) as $folder) {
                if (! $folder->no_select) {
                    $paths[] = $folder->path;
                }
            }
            $map = self::mapFolders($paths);

            $state = (array) ($account->sync_state ?? []);
            $state['_folders'] = $map;

            foreach ($map as $ours => $path) {
                $folder = $client->getFolderByPath($path);
                if (! $folder) {
                    continue;
                }

                $last = (int) ($state['uids'][$path] ?? 0);
                $query = $folder->query()->leaveUnread()->setFetchBody(true)->softFail();
                $messages = $last > 0
                    ? $query->getByUidGreater($last)
                    : $query->all()->setFetchOrderDesc()->limit($firstRun)->get();

                $highest = $last;
                foreach ($messages as $remote) {
                    $uid = (int) $remote->uid;
                    if ($uid <= $last) {
                        continue;
                    }
                    $highest = max($highest, $uid);
                    $saved = $this->store($account, $ours, $path, $remote);
                    if ($saved && $saved->wasRecentlyCreated) {
                        $fetched++;
                        // Only mail that arrives after the first sync is "new" enough to answer.
                        if ($ours === 'inbox' && $last > 0) {
                            $arrived[] = $saved;
                        }
                    }
                }
                $state['uids'][$path] = $highest;

                /*
                 * Folder by folder, not once at the end.
                 *
                 * A run that is cut short - a timeout, a worker restart, a
                 * server that hangs up on the third folder - used to throw
                 * away everything it had read, and start the whole mailbox
                 * again next time. A big mailbox on a slow server could
                 * never finish. Now each folder's high-water mark is kept
                 * as soon as it is reached, so the next run carries on.
                 */
                $account->forceFill(['sync_state' => $state])->save();
            }

            $client->disconnect();

            $account->update([
                'sync_state' => $state, 'last_synced_at' => now(), 'last_error' => null,
                // One good sync ends the run and lets it be asked normally again.
                'sync_failures' => 0, 'sync_paused_until' => null,
            ]);
        } catch (Throwable $e) {
            /*
             * A mailbox does not go red over one bad minute.
             *
             * Most of what a sync trips over is weather - a name that will
             * not resolve, a port that times out once - and saying PROBLEM
             * for it teaches people that the badge means nothing. A refused
             * sign-in is different: it will still be refused in five
             * minutes, so it is said straight away.
             */
            $failures = (int) $account->sync_failures + 1;
            $settled = ! MailConnector::transient($e) || $failures >= self::COMPLAIN_AFTER;

            $account->update([
                'sync_failures' => $failures,
                'last_error' => $settled ? MailConnector::plain($e) : $account->last_error,
                /*
                 * And once it is being announced, it is also asked less
                 * often - the same count, read twice. A mailbox nobody can
                 * reach is not worth a slot on the queue every five
                 * minutes until a person changes something.
                 */
                'sync_paused_until' => MailAccount::restUntil($failures),
            ]);
            Log::warning('[mails] sync failed', [
                'account' => $account->id, 'error' => $e->getMessage(), 'in a row' => $failures,
            ]);

            return ['fetched' => $fetched, 'error' => MailConnector::plain($e)];
        }

        foreach ($arrived as $message) {
            $this->answer($account, $message);
        }

        return ['fetched' => $fetched, 'error' => null];
    }

    /** One server message, into our table - or its flags, if we have it already. */
    public function store(MailAccount $account, string $folder, string $path, $remote): ?MailMessage
    {
        $flags = collect($remote->getFlags()->toArray())->map(fn ($f) => strtolower(trim((string) $f, '\\')));

        $existing = MailMessage::where('mail_account_id', $account->id)
            ->where('remote_folder', $path)->where('uid', (int) $remote->uid)->first();
        if ($existing) {
            $existing->update(['is_read' => $flags->contains('seen'), 'is_starred' => $flags->contains('flagged')]);

            return $existing;
        }

        $messageId = self::first($remote->message_id);

        /*
         * A mail we moved ourselves, arriving in its new folder.
         *
         * Moving a mail on the server gives it a new number in the folder it
         * lands in, so the next sync of that folder meets it as a stranger -
         * and would file a second copy beside the one already moved here. A
         * row with the same Message-ID, already in this folder but still
         * pointing at its old one, is that mail: it takes on the new address
         * instead of being duplicated.
         */
        if ($messageId) {
            /*
             * With the angle brackets and without.
             *
             * We write our own as <id@domain>; a server hands the same one
             * back bare. Comparing the two as strings said "not the same
             * mail", so a message sent from here was filed a second time the
             * moment its copy in Sent was read back.
             */
            $known = self::messageIdForms($messageId);

            $moved = MailMessage::where('mail_account_id', $account->id)
                ->whereIn('message_id', $known)
                ->where('folder', $folder)
                ->where(fn ($q) => $q->whereNull('remote_folder')->orWhere('remote_folder', '!=', $path))
                ->first();
            if ($moved) {
                $moved->update(['remote_folder' => $path, 'uid' => (int) $remote->uid]);

                return $moved;
            }
        }

        $html = $remote->hasHTMLBody() ? MailHtml::sanitize($remote->getHTMLBody()) : null;
        $text = $remote->hasTextBody() ? $remote->getTextBody() : null;
        $subject = MailHtml::header(self::first($remote->subject));
        $inReplyTo = self::first($remote->in_reply_to);
        $references = self::joined($remote->references);
        $from = self::addresses($remote->from)[0] ?? ['email' => null, 'name' => null];

        try {
            $date = $remote->date?->toDate();
        } catch (Throwable) {
            $date = null;
        }

        /*
         * What is wrong with this one?
         *
         * Weighed before it is filed, so a message that is pretending to be
         * somebody else lands in Spam with its reasons rather than in the
         * inbox looking ordinary. Mail we sent, and mail the server already
         * filed in Spam or Junk, is not weighed again.
         */
        $verdict = ['score' => 0, 'reasons' => [], 'spam' => false];
        if ($folder === 'inbox') {
            $verdict = $this->guard->weigh([
                'subject' => (string) $subject,
                'from_email' => (string) ($from['email'] ?? ''),
                'from_name' => (string) ($from['name'] ?? ''),
                'reply_to' => self::addresses($remote->reply_to)[0]['email'] ?? null,
                'html' => (string) $html,
                'text' => (string) $text,
                'attachments' => collect($remote->getAttachments())->map(fn ($a) => (string) ($a->filename ?: $a->name))->all(),
                'headers' => (string) ($remote->getHeader()?->raw ?? ''),
                'flagged_spam' => $flags->contains('junk'),
            ], $account);

            if ($verdict['spam']) {
                $folder = 'spam';
            }
        }

        return DB::transaction(function () use ($account, $folder, $path, $remote, $flags, $html, $text, $subject, $messageId, $inReplyTo, $references, $from, $date, $verdict) {
            $message = MailMessage::create([
                'organization_id' => $account->organization_id,
                'mail_account_id' => $account->id,
                'folder' => $folder,
                'remote_folder' => $path,
                'uid' => (int) $remote->uid,
                'message_id' => $messageId ? mb_substr($messageId, 0, 500) : null,
                'in_reply_to' => $inReplyTo ? mb_substr($inReplyTo, 0, 500) : null,
                'reference_ids' => $references,
                'thread_key' => MailMessage::threadKeyFor($references, $inReplyTo, $messageId, $subject),
                'from_name' => $from['name'] ? mb_substr($from['name'], 0, 250) : null,
                'from_email' => $from['email'] ? mb_substr($from['email'], 0, 250) : null,
                'to' => self::addresses($remote->to),
                'cc' => self::addresses($remote->cc),
                'reply_to' => self::addresses($remote->reply_to)[0]['email'] ?? null,
                'subject' => $subject,
                'snippet' => MailHtml::snippet($text, $html),
                'body_html' => $html,
                'body_text' => $text,
                'has_attachments' => $remote->hasAttachments(),
                'is_read' => $flags->contains('seen') || $folder === 'sent' || $folder === 'drafts',
                'is_starred' => $flags->contains('flagged'),
                'date' => $date ? Carbon::instance($date) : now(),
                'size' => (int) ($remote->size ?? 0) ?: null,
                'spam_score' => (int) $verdict['score'],
                'spam_reasons' => $verdict['reasons'] ?: null,
            ]);

            /*
             * Who wrote to us, kept with the name they signed.
             *
             * Only what arrives in the inbox, and only what is not suspect:
             * our own copy in Sent would record us as our own correspondent,
             * and spam is not an acquaintance.
             */
            if ($folder === 'inbox' && $account->member && ($from['email'] ?? null) && (int) $verdict['score'] < 30) {
                \App\Models\Crm\MailContact::remember($account->member, $from['email'], $from['name'] ?? null, false);
            }

            foreach ($remote->getAttachments() as $attachment) {
                $message->attachments()->create([
                    'filename' => mb_substr((string) ($attachment->filename ?: $attachment->name ?: 'attachment'), 0, 250),
                    'mime' => $attachment->content_type ?: null,
                    'size' => (int) ($attachment->size ?? 0) ?: null,
                    'content_id' => $attachment->id ? mb_substr((string) $attachment->id, 0, 250) : null,
                    'is_inline' => strtolower((string) $attachment->disposition) === 'inline',
                    'part' => (string) $attachment->part_number,
                ]);
            }

            return $message;
        });
    }

    /**
     * The away message and the forward, for one mail that just arrived.
     *
     * Never to anybody who is a robot - a no-reply address, a mailing list,
     * or somebody's own auto-reply - which is how two away messages end up
     * answering each other until a mailbox fills.
     */
    private function answer(MailAccount $account, MailMessage $message): void
    {
        $from = strtolower((string) $message->from_email);
        $robot = $from === ''
            || $from === strtolower($account->email)
            || preg_match('/(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounce|notifications?@)/i', $from);

        // Only addresses that answered their code. An unverified one is a
        // typo until proved otherwise, and a typo that receives a company's
        // mail for a year is the reason this is checked at all.
        foreach (MailAccount::verifiedForwards($account) as $address) {
            if ($robot) {
                break;
            }
            try {
                $this->sender->forward($account, $message, $address);
            } catch (Throwable $e) {
                Log::info('[mails] forward failed', ['account' => $account->id, 'to' => $address, 'error' => $e->getMessage()]);
            }
        }

        $away = (array) ($account->auto_reply ?? []);
        if ($robot || empty($away['enabled']) || ! $account->canSend()) {
            return;
        }
        $today = now()->toDateString();
        if ((! empty($away['from']) && $today < $away['from']) || (! empty($away['until']) && $today > $away['until'])) {
            return;
        }

        // Each sender once every four days, however much they write.
        $recent = DB::table('crm_mail_auto_replies')
            ->where('mail_account_id', $account->id)->where('email', $from)
            ->where('last_sent_at', '>', now()->subDays(4))->exists();
        if ($recent) {
            return;
        }

        try {
            $this->sender->autoReply($account, $message, $away);
            DB::table('crm_mail_auto_replies')->updateOrInsert(
                ['mail_account_id' => $account->id, 'email' => $from],
                ['last_sent_at' => now()],
            );
        } catch (Throwable $e) {
            Log::info('[mails] auto-reply failed', ['account' => $account->id, 'error' => $e->getMessage()]);
        }
    }

    /** A Message-ID as it might have been written down: bare, and bracketed. */
    public static function messageIdForms(string $messageId): array
    {
        $bare = trim($messageId, '<> ');

        return array_values(array_unique(array_filter([
            mb_substr($messageId, 0, 500),
            mb_substr($bare, 0, 500),
            mb_substr('<' . $bare . '>', 0, 500),
        ])));
    }

    private static function first($attribute): ?string
    {
        try {
            $value = $attribute?->first();
            $value = is_string($value) ? trim($value) : ($value !== null ? trim((string) $value) : null);

            return $value === '' ? null : $value;
        } catch (Throwable) {
            return null;
        }
    }

    private static function joined($attribute): ?string
    {
        try {
            $all = array_map(fn ($v) => trim((string) $v), (array) ($attribute?->all() ?? []));
            $all = array_filter($all);

            return $all ? implode(' ', $all) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{email: ?string, name: ?string}> */
    private static function addresses($attribute): array
    {
        try {
            return collect((array) ($attribute?->all() ?? []))
                ->map(fn ($a) => ['email' => $a->mail ?? null, 'name' => ($a->personal ?? '') ?: null])
                ->filter(fn ($a) => filled($a['email']))
                ->values()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
