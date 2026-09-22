<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;

/**
 * What is wrong with this message?
 *
 * Not a spam filter in the machine-learning sense - the mail server this
 * reads from already has one, and its verdict is respected when it gives
 * one. This is the layer that catches what plain spam filters miss and what
 * actually costs companies money: mail that is pretending to be somebody
 * else.
 *
 * Each check adds to a score. Every one of them is a thing a person would
 * notice if they looked closely, written down so that nobody has to:
 *
 *   the sending server failed the domain's own checks (SPF, DKIM, DMARC)
 *   the name on the From line contains a different address to the real one
 *   replies are set to go somewhere else entirely
 *   a link's text says one address and its href goes to another
 *   links to bare IP addresses, or to look-alike domains in punycode
 *   attachments that are programs
 *   the language of a scam: passwords expiring, accounts closing, urgency
 *
 * Nothing is deleted, ever. Past 60 the message lands in Spam with its
 * reasons attached; past 30 it stays in the inbox with its links disarmed
 * and a note saying why.
 */
class MailGuard
{
    /** At or above this, the message goes to Spam rather than the inbox. */
    public const SPAM_AT = 60;

    /** At or above this, the message is shown with its links held back. */
    public const SUSPICIOUS_AT = 30;

    /** Files that are programs, whatever they are called. */
    private const DANGEROUS = ['exe', 'scr', 'bat', 'cmd', 'com', 'pif', 'vbs', 'js', 'jse', 'wsf', 'msi', 'jar', 'ps1', 'lnk', 'hta', 'reg'];

    /** Shorteners hide where a link really goes, which is the point of them. */
    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly', 'shorturl.at'];

    /**
     * Weigh one arriving message.
     *
     * @param  array{subject?:string,from_email?:string,from_name?:string,reply_to?:string,html?:string,text?:string,attachments?:array<int,string>,headers?:string,flagged_spam?:bool}  $mail
     * @return array{score:int,reasons:array<int,string>,spam:bool,suspicious:bool}
     */
    public function weigh(array $mail, ?MailAccount $account = null): array
    {
        $score = 0;
        $reasons = [];

        $from = strtolower(trim((string) ($mail['from_email'] ?? '')));
        $fromDomain = MailDns::domainOf($from);
        $name = (string) ($mail['from_name'] ?? '');
        $subject = (string) ($mail['subject'] ?? '');
        $html = (string) ($mail['html'] ?? '');
        $text = (string) ($mail['text'] ?? '');
        $headers = strtolower((string) ($mail['headers'] ?? ''));

        // The mail server's own verdict, where it gave one.
        if (! empty($mail['flagged_spam'])) {
            $score += 50;
            $reasons[] = 'Your mail server marked it as spam.';
        }

        // What the sending domain's own records said about it.
        if ($headers !== '') {
            foreach ([['spf=fail', 'SPF'], ['dkim=fail', 'DKIM'], ['dmarc=fail', 'DMARC']] as [$needle, $label]) {
                if (str_contains($headers, $needle)) {
                    $score += $label === 'DMARC' ? 35 : 25;
                    $reasons[] = "It failed the sender's {$label} check - the server that sent it is not one the domain allows.";
                }
            }
        }

        // "Netvork Support <billing@random-domain.test>" - the oldest trick
        // there is, and still the one that works.
        if (preg_match('/[\w.+-]+@[\w.-]+\.\w+/', $name, $m)) {
            $shown = strtolower($m[0]);
            if ($shown !== $from) {
                $score += 40;
                $reasons[] = "The sender's name shows {$shown}, but the mail actually came from {$from}.";
            }
        }

        // Replies quietly going somewhere else.
        $replyTo = strtolower(trim((string) ($mail['reply_to'] ?? '')));
        if ($replyTo && $fromDomain && MailDns::domainOf($replyTo) !== $fromDomain) {
            $score += 20;
            $reasons[] = "Replies would go to {$replyTo}, which is not the address it came from.";
        }

        // Links that do not go where they say they go.
        foreach ($this->links($html) as [$href, $label]) {
            $target = MailDns::domainOf('x@' . (string) parse_url($href, PHP_URL_HOST));
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));

            if (preg_match('~^https?://\d{1,3}(\.\d{1,3}){3}~i', $href)) {
                $score += 30;
                $reasons[] = 'A link points at a bare IP address rather than a name.';
            }
            if (str_starts_with($host, 'xn--') || str_contains($host, '.xn--')) {
                $score += 30;
                $reasons[] = 'A link uses a look-alike domain written in punycode.';
            }
            if (in_array($host, self::SHORTENERS, true)) {
                $score += 15;
                $reasons[] = 'A link is shortened, so where it really goes is hidden.';
            }
            // The label reads like an address, and it is not this one.
            if (preg_match('~^(?:https?://)?((?:[\w-]+\.)+[a-z]{2,})~i', trim($label), $lm)) {
                $said = strtolower($lm[1]);
                if ($host !== '' && $said !== $host && ! str_ends_with($host, '.' . $said) && ! str_ends_with($said, '.' . $host)) {
                    $score += 35;
                    $reasons[] = "A link says {$said} but goes to {$host}.";
                }
            }
            unset($target);
        }

        // Attachments that are programs.
        foreach ((array) ($mail['attachments'] ?? []) as $filename) {
            $extension = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
            if (in_array($extension, self::DANGEROUS, true)) {
                $score += 45;
                $reasons[] = "It carries {$filename}, which is a program rather than a document.";
            }
            if (preg_match('/\.(pdf|docx?|xlsx?|jpg|png)\.(exe|js|scr|bat|vbs)$/i', (string) $filename)) {
                $score += 20;
                $reasons[] = "{$filename} is disguised to look like a document.";
            }
        }

        // The language of a scam. Weak on its own, telling alongside anything else.
        $body = mb_strtolower($subject . ' ' . strip_tags($html) . ' ' . $text);
        $phrases = [
            'verify your account', 'confirm your password', 'your password will expire', 'account will be closed',
            'unusual sign-in', 'unusual login', 'click here immediately', 'within 24 hours', 'wire transfer',
            'update your payment', 'suspended your account', 'confirm your identity', 'gift card',
        ];
        $hits = collect($phrases)->filter(fn ($p) => str_contains($body, $p))->values();
        if ($hits->isNotEmpty()) {
            $score += min(30, 10 * $hits->count());
            $reasons[] = 'It uses the language of a scam: "' . $hits->first() . '".';
        }

        // A mailbox writing to itself from outside is somebody pretending.
        if ($account && $from === strtolower($account->email) && ! empty($mail['headers']) && ! str_contains($headers, 'dkim=pass')) {
            $score += 35;
            $reasons[] = 'It claims to come from this mailbox itself, which the sending server could not prove.';
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'reasons' => array_values(array_unique($reasons)),
            'spam' => $score >= self::SPAM_AT,
            'suspicious' => $score >= self::SUSPICIOUS_AT,
        ];
    }

    /**
     * Take the links out of a message, so they can be looked at.
     *
     * @return array<int, array{0:string,1:string}>  href and the text on it
     */
    private function links(string $html): array
    {
        if ($html === '' || ! str_contains($html, '<a')) {
            return [];
        }

        preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->filter(fn ($m) => str_starts_with(strtolower(trim($m[1])), 'http'))
            ->map(fn ($m) => [trim($m[1]), trim(strip_tags($m[2]))])
            ->take(50)
            ->values()->all();
    }

    /**
     * The same message with its links disarmed.
     *
     * The address stays visible as text, so somebody who knows the sender can
     * still read where it would have gone - but nothing is one careless click
     * away. Used for what is suspicious rather than certain, where hiding the
     * mail would be worse than showing it carefully.
     */
    public static function disarm(?string $html): string
    {
        if (! $html) {
            return '';
        }

        return (string) preg_replace_callback(
            '~<a\b([^>]*)href=["\']([^"\']+)["\']([^>]*)>~i',
            fn ($m) => '<span class="mail-disarmed" title="Link held back: ' . e($m[2]) . '" style="color:#b45309;text-decoration:underline dotted">',
            (string) preg_replace('~</a>~i', '</span>', $html)
        );
    }
}
