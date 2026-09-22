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
            'validate_cert' => true,
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
            return ['ok' => false, 'message' => 'Could not read the mailbox: ' . self::plain($e)];
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
            return ['ok' => false, 'message' => 'Could not sign in to send: ' . self::plain($e)];
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

        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 300);
    }
}
