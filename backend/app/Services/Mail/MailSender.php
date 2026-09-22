<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use Illuminate\Mail\Message as LaravelMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mail going out, through the mailbox's own server.
 *
 * Replies carry In-Reply-To and References, so the other side's mail
 * program files them under the conversation they answer rather than
 * starting a new one - and so ours does too.
 */
class MailSender
{
    /*
     * Providers whose SMTP files a copy in Sent by itself. Copying it there
     * again over IMAP would put every sent mail in Sent twice.
     */
    private const SAVES_SENT_ITSELF = ['gmail', 'outlook'];

    public function __construct(private MailConnector $connector)
    {
    }

    public function send(MailMessage $message): void
    {
        $account = $message->account;
        if (! $account || ! $account->canSend()) {
            throw new RuntimeException('This mailbox is not set up to send - add its outgoing (SMTP) server in Mail settings.');
        }

        $message->message_id ??= '<' . Str::uuid()->toString() . '@' . self::domainOf($account->email) . '>';

        $sent = $this->deliver($account, [
            'to' => $message->to ?? [],
            'cc' => $message->cc ?? [],
            'bcc' => $message->bcc ?? [],
            'subject' => (string) $message->subject,
            'html' => (string) ($message->body_html ?? MailHtml::fromText((string) $message->body_text)),
            'text' => $message->body_text ?: MailHtml::snippet(null, $message->body_html),
            'message_id' => $message->message_id,
            'in_reply_to' => $message->in_reply_to,
            'references' => $message->reference_ids,
            'attachments' => $message->attachments()->whereNotNull('path')->get(),
        ]);

        $message->save();

        // A copy in the server's Sent folder, so the mail shows on the phone too.
        if ($sent && ! in_array($account->provider, self::SAVES_SENT_ITSELF, true) && $account->canReceive()) {
            $this->fileInSent($account, $sent);
        }
    }

    /** A copy of an arrived mail, to where the mailbox forwards. */
    public function forward(MailAccount $account, MailMessage $original, string $to): void
    {
        $this->deliver($account, [
            'to' => [['email' => $to]],
            'subject' => 'Fwd: ' . $original->subject,
            'html' => self::forwardedBlock($original),
            'text' => null,
        ]);
    }

    /** The away message, once, to somebody who wrote in. */
    public function autoReply(MailAccount $account, MailMessage $incoming, array $settings): void
    {
        $body = (string) ($settings['body'] ?? 'Thank you for your email. I am away and will reply when I return.');

        $this->deliver($account, [
            'to' => [['email' => $incoming->reply_to ?: $incoming->from_email, 'name' => $incoming->from_name]],
            'subject' => ($settings['subject'] ?? '') ?: ('Re: ' . $incoming->subject),
            'html' => MailHtml::fromText($body),
            'text' => $body,
            'in_reply_to' => $incoming->message_id,
            'references' => $incoming->message_id,
            // RFC 3834: say it is automatic, so the other side's auto-reply does not answer ours.
            'auto' => true,
        ]);
    }

    /** @return \Illuminate\Mail\SentMessage|null */
    private function deliver(MailAccount $account, array $mail)
    {
        $sender = $account->sender();

        return $this->connector->smtp($account)->send(
            ['html' => new HtmlString($mail['html']), 'text' => new HtmlString((string) ($mail['text'] ?? ''))],
            [],
            function (LaravelMessage $m) use ($mail, $sender, $account) {
                $m->from($sender['address'], $sender['name']);
                if ($account->reply_to) {
                    $m->replyTo($account->reply_to);
                }
                foreach (['to', 'cc', 'bcc'] as $kind) {
                    foreach ((array) ($mail[$kind] ?? []) as $who) {
                        $email = is_array($who) ? ($who['email'] ?? null) : $who;
                        if ($email) {
                            $m->{$kind}($email, is_array($who) ? ($who['name'] ?? null) : null);
                        }
                    }
                }
                $m->subject($mail['subject']);

                $headers = $m->getSymfonyMessage()->getHeaders();
                if (! empty($mail['message_id'])) {
                    $headers->remove('Message-ID');
                    $headers->addIdHeader('Message-ID', trim($mail['message_id'], '<> '));
                }
                if (! empty($mail['in_reply_to'])) {
                    $headers->addIdHeader('In-Reply-To', trim($mail['in_reply_to'], '<> '));
                }
                if (! empty($mail['references'])) {
                    preg_match_all('/<([^>]+)>/', $mail['references'], $ids);
                    $list = $ids[1] ?: [trim($mail['references'], '<> ')];
                    $headers->addIdHeader('References', array_values(array_filter($list)));
                }
                if (! empty($mail['auto'])) {
                    $headers->addTextHeader('Auto-Submitted', 'auto-replied');
                }

                foreach ($mail['attachments'] ?? [] as $file) {
                    $path = storage_path('app/' . ltrim($file->path, '/'));
                    if (is_file($path)) {
                        $m->attach($path, ['as' => $file->filename, 'mime' => $file->mime]);
                    }
                }
            },
        );
    }

    private function fileInSent(MailAccount $account, $sent): void
    {
        try {
            $path = $account->sync_state['_folders']['sent'] ?? null;
            if (! $path) {
                return;
            }
            $client = $this->connector->imap($account);
            $client->getFolderByPath($path)?->appendMessage($sent->toString(), ['\\Seen']);
            $client->disconnect();
        } catch (Throwable $e) {
            // It went; only the server-side copy is missing. Worth a line, not an error.
            Log::info('[mails] could not file a copy in Sent', ['account' => $account->id, 'error' => $e->getMessage()]);
        }
    }

    public static function domainOf(string $email): string
    {
        return Str::after($email, '@') ?: 'netvork.app';
    }

    /** The quoted block a forward or reply carries of the mail it is about. */
    public static function forwardedBlock(MailMessage $original): string
    {
        $from = e(trim(($original->from_name ?: '') . ' <' . $original->from_email . '>'));
        $date = e($original->date?->format('D, j M Y H:i') ?? '');
        $to = e(collect($original->to ?? [])->map(fn ($t) => $t['email'] ?? '')->filter()->implode(', '));

        return '<br><div style="border-left:3px solid #cbd5e1;padding-left:12px;color:#475569">'
            . '<p style="margin:0 0 8px">---------- Forwarded message ---------<br>'
            . "From: {$from}<br>Date: {$date}<br>Subject: " . e((string) $original->subject) . "<br>To: {$to}</p>"
            . ($original->body_html ?: MailHtml::fromText((string) $original->body_text))
            . '</div>';
    }
}
