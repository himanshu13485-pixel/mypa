<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailAttachment;
use App\Models\Crm\MailBackupRun;
use App\Models\Crm\MailMessage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;
use Webklex\PHPIMAP\Message as ImapMessage;
use ZipArchive;

/**
 * The mail, kept as files.
 *
 * The live copy of every message is a row in crm_mail_messages, which is
 * where the screens read it from - but a database row is not a backup
 * anybody can use. This writes the same mail out as ordinary .eml files:
 * one file per message, RFC 5322, exactly what Outlook, Thunderbird, Apple
 * Mail and every other program in the world opens.
 *
 * Each mailbox gets a folder named after it:
 *
 *   storage/app/private/mail-archive/<COMPANY>/<mailbox>/<year>/<month>/<file>.eml
 *   storage/app/private/mail-archive/<COMPANY>/<mailbox>/index.jsonl
 *
 * index.jsonl holds one JSON line per message - uuid, folder, date, sender,
 * subject, file name, size - so an archive can be listed, checked or
 * restored without opening thousands of files.
 *
 * Nothing here talks to a cloud: this makes the local copy, and MailVault
 * takes that copy wherever else the company wants it.
 */
class MailArchive
{
    public const ROOT = 'mail-archive';

    /** The archive folder for one mailbox, relative to the private disk. */
    public function folder(MailAccount $account): string
    {
        $company = Str::upper(Str::slug((string) ($account->organization?->code ?: $account->organization_id)));
        // The dots matter: "accounts-at-grapout-test", not "grapouttest".
        $box = Str::slug(str_replace(['@', '.'], ['-at-', '-'], (string) $account->email));

        return self::ROOT . '/' . ($company ?: 'COMPANY') . '/' . ($box ?: 'mailbox-' . $account->id);
    }

    /** Where that folder is on this machine - what somebody backing up the server needs. */
    public function absolutePath(MailAccount $account): string
    {
        return Storage::disk('local')->path($this->folder($account));
    }

    /** What is in the archive right now: files, bytes, and when it last grew. */
    public function status(MailAccount $account): array
    {
        $disk = Storage::disk('local');
        $folder = $this->folder($account);
        $files = collect($disk->allFiles($folder))->filter(fn ($f) => str_ends_with($f, '.eml'));

        return [
            'folder' => $folder,
            'absolute' => $this->absolutePath($account),
            'files' => $files->count(),
            'bytes' => (int) $files->sum(fn ($f) => $disk->size($f)),
            'stored' => MailMessage::where('mail_account_id', $account->id)->count(),
            'last_backup_at' => ($account->backup['last_run_at'] ?? null),
        ];
    }

    /**
     * Copy everything not copied yet into the mailbox's folder.
     *
     * Messages are taken in the order they arrived here, and the id of the
     * last one written is remembered, so a nightly run costs whatever came
     * in that day rather than the whole history. `$full` starts again from
     * the beginning, which is what a restored or moved server needs.
     */
    public function backup(MailAccount $account, bool $full = false, ?MailBackupRun $run = null): array
    {
        $disk = Storage::disk('local');
        $folder = $this->folder($account);
        $settings = (array) ($account->backup ?? []);
        $cursor = $full ? 0 : (int) ($settings['cursor'] ?? 0);

        $written = 0;
        $bytes = 0;
        $lines = [];
        $last = $cursor;

        MailMessage::where('mail_account_id', $account->id)
            ->where('id', '>', $cursor)
            ->whereNotIn('folder', ['drafts', 'outbox', 'scheduled'])
            ->with('attachments')
            ->orderBy('id')
            ->chunk(100, function ($messages) use (&$written, &$bytes, &$lines, &$last, $disk, $folder, $account) {
                foreach ($messages as $message) {
                    $last = max($last, $message->id);
                    try {
                        $eml = $this->eml($message, $account);
                    } catch (Throwable) {
                        // One unreadable message must not stop the archive.
                        continue;
                    }
                    $path = $folder . '/' . $this->fileFor($message);
                    $disk->put($path, $eml);
                    $written++;
                    $bytes += strlen($eml);
                    $lines[] = json_encode([
                        'uuid' => $message->uuid,
                        'file' => Str::after($path, $folder . '/'),
                        'folder' => $message->folder,
                        'date' => $message->date?->toIso8601String(),
                        'from' => $message->from_email,
                        'to' => collect((array) $message->to)->pluck('email')->all(),
                        'subject' => $message->subject,
                        'message_id' => $message->message_id,
                        'bytes' => strlen($eml),
                        'sha256' => hash('sha256', $eml),
                        'archived_at' => now()->toIso8601String(),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            });

        if ($lines) {
            $index = $folder . '/index.jsonl';
            $disk->append($index, implode("\n", $lines));
        }

        $account->forceFill([
            'backup' => array_merge($settings, [
                'cursor' => $last,
                'last_run_at' => now()->toIso8601String(),
                'last_error' => null,
            ]),
        ])->save();

        $run?->forceFill(['messages' => $written, 'bytes' => $bytes, 'path' => $folder])->save();

        return ['messages' => $written, 'bytes' => $bytes, 'folder' => $folder];
    }

    /** One message as a file name that sorts by date and cannot collide. */
    public function fileFor(MailMessage $message): string
    {
        $when = $message->date ?: $message->created_at ?: now();
        $subject = Str::limit(Str::slug((string) $message->subject) ?: 'no-subject', 60, '');

        return $when->format('Y') . '/' . $when->format('m') . '/'
            . $when->format('d-His') . '-' . $subject . '-' . Str::substr($message->uuid, 0, 8) . '.eml';
    }

    /**
     * One stored message as RFC 5322 text.
     *
     * Built from what we hold rather than fetched from the mail server, so
     * an archive keeps working after the mailbox is disconnected - which is
     * exactly when an archive matters. Attachments saved here go in whole;
     * ones that only ever lived on the mail server are named in a header so
     * nothing silently looks complete when it is not.
     */
    public function eml(MailMessage $message, ?MailAccount $account = null): string
    {
        $account ??= $message->account;
        $email = new Email();

        $from = $message->from_email ?: ($account?->email ?: 'unknown@localhost');
        $email->from(new Address($from, (string) ($message->from_name ?: '')));

        foreach (['to' => 'to', 'cc' => 'cc', 'bcc' => 'bcc'] as $field => $method) {
            $people = collect((array) $message->{$field})
                ->filter(fn ($a) => filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL))
                ->map(fn ($a) => new Address($a['email'], (string) ($a['name'] ?? '')))
                ->values()->all();
            if ($people) {
                $email->{$method}(...$people);
            }
        }

        $email->subject((string) ($message->subject ?: '(no subject)'));
        $email->date(($message->date ?: $message->created_at ?: now())->toDateTimeImmutable());

        if ($message->body_html) {
            $email->html($message->body_html);
        }
        $email->text($message->body_text ?: strip_tags((string) $message->body_html));

        $headers = $email->getHeaders();
        if ($message->message_id) {
            $headers->addIdHeader('Message-ID', trim($message->message_id, '<>'));
        }
        if ($message->in_reply_to) {
            $headers->addTextHeader('In-Reply-To', $message->in_reply_to);
        }
        if ($message->reference_ids) {
            $headers->addTextHeader('References', $message->reference_ids);
        }
        // Where it sat in Mails, so a restore can put it back in the same place.
        $headers->addTextHeader('X-Netvork-Folder', (string) $message->folder);
        $headers->addTextHeader('X-Netvork-Uuid', (string) $message->uuid);

        $missing = [];
        foreach ($message->attachments as $attachment) {
            $body = $this->attachmentBody($attachment);
            if ($body === null) {
                $missing[] = $attachment->filename;

                continue;
            }
            $email->attach($body, $attachment->filename, $attachment->mime ?: 'application/octet-stream');
        }
        if ($missing) {
            $headers->addTextHeader('X-Netvork-Attachments-Missing', implode(', ', $missing));
        }

        return $email->toString();
    }

    /** An attachment's bytes, when they are ours to give. */
    private function attachmentBody(MailAttachment $attachment): ?string
    {
        if (! $attachment->path) {
            return null;
        }
        $disk = Storage::disk('local');

        return $disk->exists($attachment->path) ? (string) $disk->get($attachment->path) : null;
    }

    /**
     * The archive as one zip file, ready to download.
     *
     * Backs up first, so an export is never yesterday's picture.
     */
    public function exportZip(MailAccount $account, ?string $onlyFolder = null): string
    {
        $this->backup($account);

        $disk = Storage::disk('local');
        $source = $this->folder($account);
        $name = 'mail-export/' . $account->uuid . '-' . now()->format('Ymd-His') . '.zip';
        $target = $disk->path($name);
        @mkdir(dirname($target), 0775, true);

        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the export file.');
        }

        // The index first, so whoever opens the zip can read what is in it.
        if ($disk->exists($source . '/index.jsonl')) {
            $zip->addFromString('index.jsonl', (string) $disk->get($source . '/index.jsonl'));
        }
        $zip->addFromString('README.txt', $this->readme($account));

        $keep = $onlyFolder ? $this->filesInFolder($account, $onlyFolder) : null;
        foreach ($disk->allFiles($source) as $file) {
            if (! str_ends_with($file, '.eml')) {
                continue;
            }
            $relative = Str::after($file, $source . '/');
            if ($keep !== null && ! in_array($relative, $keep, true)) {
                continue;
            }
            $zip->addFromString($relative, (string) $disk->get($file));
        }
        $zip->close();

        return $name;
    }

    /** Which archived files belong to one Mails folder, read from the index. */
    private function filesInFolder(MailAccount $account, string $folder): array
    {
        $disk = Storage::disk('local');
        $index = $this->folder($account) . '/index.jsonl';
        if (! $disk->exists($index)) {
            return [];
        }

        return collect(preg_split('/\R/', (string) $disk->get($index)))
            ->filter()
            ->map(fn ($line) => json_decode($line, true))
            ->filter(fn ($row) => is_array($row) && ($row['folder'] ?? null) === $folder)
            ->pluck('file')->filter()->values()->all();
    }

    private function readme(MailAccount $account): string
    {
        return implode("\n", [
            'Netvork Mails export - ' . $account->email,
            'Taken ' . now()->toDayDateTimeString(),
            '',
            'Every .eml file is one message in the ordinary internet mail format (RFC 5322).',
            'Open one by double-clicking it, or import the lot into Outlook, Thunderbird,',
            'Apple Mail or any other mail program.',
            '',
            'index.jsonl lists every message, one JSON object per line:',
            'uuid, file, folder, date, from, to, subject, message_id, bytes, sha256.',
            '',
            'To put these back into Netvork Mails, use Mails > Settings > Backup > Import.',
        ]);
    }

    /**
     * Read mail back in from .eml files, a .mbox, or a zip of either.
     *
     * Everything lands in the mailbox it is imported into. A message already
     * held - same Message-ID - is left alone, so importing the same file
     * twice costs nothing and changes nothing.
     */
    public function import(MailAccount $account, string $absolutePath, string $intoFolder = 'archive'): array
    {
        $raws = $this->rawMessagesIn($absolutePath);
        $added = 0;
        $skipped = 0;

        foreach ($raws as $raw) {
            try {
                $parsed = ImapMessage::fromString($raw);
            } catch (Throwable) {
                $skipped++;

                continue;
            }

            // Mail servers are not of one mind about the angle brackets, so a
            // message already here is recognised with them and without.
            $messageId = trim((string) $parsed->message_id, '<> ');
            $known = array_filter([$messageId, $messageId ? '<' . $messageId . '>' : null]);
            if ($messageId && MailMessage::where('mail_account_id', $account->id)->whereIn('message_id', $known)->exists()) {
                $skipped++;

                continue;
            }

            $addresses = function ($field) use ($parsed) {
                return collect($parsed->{$field}?->all() ?? [])
                    ->map(fn ($a) => ['email' => (string) $a->mail, 'name' => $a->personal ? (string) $a->personal : null])
                    ->filter(fn ($a) => $a['email'] !== '')->values()->all();
            };

            $html = (string) $parsed->getHTMLBody();
            $text = (string) $parsed->getTextBody();
            $from = $parsed->from?->first();

            $message = MailMessage::create([
                'organization_id' => $account->organization_id,
                'mail_account_id' => $account->id,
                'folder' => $this->folderHeader($raw) ?: $intoFolder,
                'message_id' => $messageId ? '<' . $messageId . '>' : null,
                'in_reply_to' => (string) $parsed->in_reply_to ?: null,
                'thread_key' => MailMessage::threadKeyFor((string) $parsed->references, (string) $parsed->in_reply_to, $messageId, (string) $parsed->subject),
                'from_name' => $from?->personal ? (string) $from->personal : null,
                'from_email' => $from?->mail ? (string) $from->mail : null,
                'to' => $addresses('to'),
                'cc' => $addresses('cc'),
                'bcc' => [],
                'subject' => (string) $parsed->subject ?: null,
                'body_html' => $html ?: null,
                'body_text' => $text ?: null,
                'snippet' => MailHtml::snippet($text, $html),
                'is_read' => true,
                'date' => $parsed->date?->first()?->toDate() ?: now(),
                'size' => strlen($raw),
            ]);

            foreach ($parsed->getAttachments() as $attachment) {
                $name = mb_substr((string) ($attachment->filename ?: $attachment->name ?: 'attachment'), 0, 200);
                $path = 'mail-imported/' . $message->uuid . '/' . Str::random(8) . '-' . preg_replace('/[^\w.\- ]+/u', '_', $name);
                Storage::disk('local')->put($path, (string) $attachment->getContent());
                $message->attachments()->create([
                    'filename' => $name,
                    'mime' => $attachment->content_type,
                    'size' => (int) $attachment->size ?: null,
                    'path' => $path,
                ]);
            }
            if ($message->attachments()->exists()) {
                $message->update(['has_attachments' => true]);
            }

            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** The folder a message came from, when our own header says so. */
    private function folderHeader(string $raw): ?string
    {
        if (preg_match('/^X-Netvork-Folder:\s*([a-z]+)/mi', $raw, $m)) {
            $folder = strtolower(trim($m[1]));

            return in_array($folder, MailMessage::FOLDERS, true) ? $folder : null;
        }

        return null;
    }

    /** Every raw message inside a file, whatever kind of file it is. */
    private function rawMessagesIn(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'zip') {
            $out = [];
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return [];
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (! preg_match('/\.(eml|mbox)$/i', $name)) {
                    continue;
                }
                $content = (string) $zip->getFromIndex($i);
                $out = array_merge($out, str_ends_with(strtolower($name), '.mbox') ? $this->splitMbox($content) : [$content]);
            }
            $zip->close();

            return $out;
        }

        $content = (string) file_get_contents($path);

        return $extension === 'mbox' ? $this->splitMbox($content) : [$content];
    }

    /** An mbox is many messages in one file, each starting at a "From " line. */
    private function splitMbox(string $content): array
    {
        $parts = preg_split('/^From .*\R/m', $content) ?: [];

        return collect($parts)->map(fn ($p) => trim($p))->filter()->values()->all();
    }
}
