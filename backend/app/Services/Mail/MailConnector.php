<?php

namespace App\Services\Mail;

use App\Models\Crm\MailAccount;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * The two wires a mailbox has: IMAP to read it, SMTP to send from it.
 *
 * Everything that talks to a real mail server goes through here, so tests
 * can put a stand-in in its place and nothing else has to know.
 *
 * IMAP is spoken by webklex/php-imap over its own sockets - PHP 8.4 no
 * longer ships the imap extension, and the server this runs on does not
 * have it.
 */
class MailConnector
{
    public function imap(MailAccount $account): Client
    {
        $encryption = match ($account->imap_encryption) {
            'ssl' => 'ssl',
            'tls', 'starttls' => 'starttls',
            default => false,
        };

        $client = (new ClientManager())->make([
            'host' => $account->imap_host,
            'port' => $account->imap_port ?: 993,
            'encryption' => $encryption,
            // Strict unless this mailbox was told to accept the certificate
            // its shared host presents. See the note on verify_cert.
            'validate_cert' => $account->verify_cert === null ? true : (bool) $account->verify_cert,
            'username' => $account->imap_username ?: $account->email,
            'password' => (string) $account->imap_password,
            'protocol' => 'imap',
            'timeout' => 30,
        ]);

        $client->connect();

        return $client;
    }

    public function smtp(MailAccount $account): Mailer
    {
        $encryption = $account->smtp_encryption ?: 'tls';

        return Mail::build(array_filter([
            'transport' => 'smtp',
            'host' => $account->smtp_host,
            'port' => $account->smtp_port ?: 587,
            // 465 is spoken over TLS from the first byte; 587 upgrades itself.
            'scheme' => $encryption === 'ssl' ? 'smtps' : null,
            'encryption' => $encryption === 'none' ? null : 'tls',
            'username' => $account->smtp_username ?: $account->email,
            'password' => (string) $account->smtp_password,
            'timeout' => 30,
            'verify_peer' => $account->verify_cert === null ? true : (bool) $account->verify_cert,
        ], fn ($v) => $v !== null));
    }

    /** Log in to the reading side and count the folders - proof it works. */
    public function testImap(MailAccount $account): array
    {
        if (! $account->canReceive()) {
            return ['ok' => false, 'message' => 'The incoming (IMAP) server and password are needed to read mail.'];
        }

        try {
            $client = $this->imap($account);
            $count = $client->getFolders(false)->count();
            $client->disconnect();

            return ['ok' => true, 'message' => "Signed in to {$account->imap_host} - {$count} folders found."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => self::explain($e, $account->imap_host, (int) $account->imap_port, 'incoming')];
        }
    }

    /** Log in to the sending side without sending anything. */
    public function testSmtp(MailAccount $account): array
    {
        if (! $account->canSend()) {
            return ['ok' => false, 'message' => 'The outgoing (SMTP) server and password are needed to send mail.'];
        }

        try {
            $transport = $this->smtp($account)->getSymfonyTransport();
            if ($transport instanceof EsmtpTransport) {
                $transport->start();
                $transport->stop();
            }

            return ['ok' => true, 'message' => "Signed in to {$account->smtp_host} - ready to send."];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => self::explain($e, $account->smtp_host, (int) $account->smtp_port, 'outgoing')];
        }
    }

    /**
     * A server's error, minus the stack of wrappers around it.
     *
     * "Authentication failed" is the useful part; the class names and file
     * paths that come with it are not for the person reading the screen.
     */
    public static function plain(Throwable $e): string
    {
        $message = $e->getMessage();
        while (($e = $e->getPrevious()) !== null) {
            if ($e->getMessage() !== '') {
                $message = $e->getMessage();
            }
        }

        /*
         * PHP's socket warnings arrive with a link to the manual inside them
         * - literal HTML, in a message that ends up on screen. Out it goes,
         * along with the function name nobody reading this screen knows.
         */
        $message = (string) preg_replace('/\s*\[<a href=[^\]]*\]/i', '', $message);
        $message = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = ltrim((string) preg_replace('/(stream_socket_client|fsockopen)\(\):?/i', '', $message), ": 	");

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $message)), 0, 300);
    }

    /**
     * Whether this failure is weather rather than a fault.
     *
     * A name that does not resolve for ten seconds, a port that times out
     * once, a connection dropped mid-fetch: the mailbox is configured
     * correctly and the next pass will very likely work. A refused sign-in
     * or a rejected certificate is the opposite - nothing will change until
     * somebody changes it - and deserves to be said at once.
     */
    public static function transient(Throwable $e): bool
    {
        $lower = mb_strtolower(self::plain($e));

        foreach ([
            'getaddrinfo', 'name or service not known', 'temporary failure in name resolution',
            'no such host', 'could not resolve', 'try again',
            'timed out', 'timeout', 'did not properly respond',
            'connection refused', 'connection reset', 'network is unreachable',
            'broken pipe', 'connection closed', 'unexpectedly closed',
        ] as $weather) {
            if (str_contains($lower, $weather)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The server's complaint, and what to do about it.
     *
     * A mail server that will not answer says so in the language of sockets.
     * These are the four things that are actually wrong when it does, in the
     * order they happen: the name does not exist, nothing answers on that
     * port, the certificate is wrong, or the sign-in was refused.
     */
    public static function explain(Throwable $e, ?string $host, int $port, string $side): string
    {
        $raw = self::plain($e);
        $lower = mb_strtolower($raw);
        $where = $side === 'incoming' ? 'Could not read the mailbox' : 'Could not sign in to send';
        $hint = null;

        if (str_contains($lower, 'getaddrinfo') || str_contains($lower, 'no such host') || str_contains($lower, 'name or service not known')) {
            $hint = "There is no server called \"{$host}\" - check the spelling with whoever hosts the mailbox.";
            // The one everybody gets wrong: Amazon SES is email-smtp, not smtp.
            if ($host && preg_match('/^smtp\.([a-z0-9-]+)\.amazonaws\.com$/i', $host, $m)) {
                $hint .= " Amazon SES is \"email-smtp.{$m[1]}.amazonaws.com\", not \"smtp.\".";
            }
        } elseif (str_contains($lower, 'did not properly respond') || str_contains($lower, 'timed out') || str_contains($lower, 'timeout') || str_contains($lower, 'connection refused')) {
            $hint = "Nothing answered on {$host}:{$port}. Either that is not the right server for this mailbox - many hosts read mail at mail.yourdomain.com rather than the domain itself - or the port is blocked between this server and it.";
        } elseif (str_contains($lower, 'certificate') || (str_contains($lower, 'ssl') && str_contains($lower, 'verify'))) {
            // Shared hosting answers for a thousand domains with one
            // certificate, so the name on it is the host to connect to.
            preg_match('/CN\s*=\s*[`\'"]?([^\'"`\s,]+)/i', $raw, $cn);
            $named = $cn[1] ?? null;
            // A wildcard is not a host anybody can connect to - the hosting
            // company's own server name is the one that matches it, and
            // cPanel shows it on the mailbox's setup page.
            $wildcard = $named && str_starts_with($named, '*.');
            $hint = "The server's certificate is not for {$host}"
                . ($named ? ", it is for {$named}" : '')
                . '. Either connect to the name the certificate covers'
                . ($wildcard ? ' - your host\'s own server name under ' . substr((string) $named, 2) . ', which cPanel shows on the mailbox setup page'
                    : ($named ? " - try {$named} as the server" : ''))
                . ", or tick \"Accept this server's certificate\" on the mailbox if your host has no better name to offer.";
        } elseif (str_contains($lower, 'authenticat') || str_contains($lower, 'login') || str_contains($lower, 'credential') || str_contains($lower, '535')) {
            $hint = 'The server took the connection but refused the sign-in. Gmail, Outlook and Yahoo need an app password rather than the everyday one, and Amazon SES needs its own SMTP credentials - not your AWS keys.';
        }

        return $where . ': ' . $raw . ($hint ? ' — ' . $hint : '');
    }
}
