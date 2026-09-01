<?php

namespace App\Services\Crm;

use Illuminate\Support\Facades\Mail;

/**
 * Answering "will this mailbox actually work?" before somebody finds out
 * the hard way, at the moment an invoice or a sign-in code fails to arrive.
 *
 * Four questions, each asked of the thing itself rather than of the form:
 * can we log in to send, does a real message get out, can we log in to
 * read, and will the internet believe mail from this address is ours.
 * Every one of them either succeeds or comes back with the server's own
 * complaint — a wrong password should read as "authentication failed",
 * not as "something went wrong".
 */
class MailboxDoctor
{
    /** Can we open the SMTP session and authenticate? */
    public function testSmtp(array $sender): array
    {
        try {
            $transport = $this->mailerFor($sender)->getSymfonyTransport();

            // start() connects, does EHLO/STARTTLS and authenticates; a bad
            // host, port, certificate or password all fail here, loudly.
            if (method_exists($transport, 'start')) {
                $transport->start();
                if (method_exists($transport, 'stop')) {
                    $transport->stop();
                }
            }

            return $this->ok('Connected and signed in to ' . ($sender['smtp_host'] ?? 'the mail server') . '.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /** Put a real message through it, to an address the admin names. */
    public function sendTest(array $sender, string $to, string $companyName): array
    {
        try {
            $from = $sender['from_address'] ?? null;
            if (! $from) {
                return $this->fail('This mailbox has no from-address yet.');
            }

            $this->mailerFor($sender)->html(
                '<p>This is a test from the Netvork CRM.</p>'
                . '<p>If you are reading it, ' . e($companyName) . '&rsquo;s mailbox can send: '
                . 'invoices, dues follow-ups and staff sign-in codes will go out from '
                . '<strong>' . e($from) . '</strong>.</p>',
                function ($m) use ($to, $from, $sender, $companyName) {
                    $m->to($to)
                        ->from($from, $sender['from_name'] ?? $companyName)
                        ->subject('Netvork CRM — mailbox test for ' . $companyName);
                },
            );

            return $this->ok('Sent to ' . $to . '. If it does not arrive, check the spam folder and the DNS results.');
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Can we log in to the inbox?
     *
     * Spoken to over a plain socket rather than through PHP's IMAP
     * extension, which is not installed everywhere and would make this
     * feature depend on the server it happens to run on.
     */
    public function testImap(array $sender): array
    {
        $host = $sender['imap_host'] ?? null;
        if (! $host) {
            return $this->fail('No IMAP host is set, so there is nothing to test.');
        }

        $port = (int) ($sender['imap_port'] ?? 993);
        $encryption = $sender['imap_encryption'] ?? 'ssl';
        $user = ($sender['imap_username'] ?? null) ?: ($sender['from_address'] ?? '');
        $pass = $this->secret($sender['imap_password'] ?? null)
            ?: $this->secret($sender['smtp_password'] ?? null);

        if ($user === '' || $pass === null || $pass === '') {
            return $this->fail('No inbox password is set for this mailbox.');
        }

        $context = stream_context_create(['ssl' => [
            'verify_peer' => ! ($sender['imap_allow_self_signed'] ?? false),
            'verify_peer_name' => ! ($sender['imap_allow_self_signed'] ?? false),
            'allow_self_signed' => (bool) ($sender['imap_allow_self_signed'] ?? false),
        ]]);

        $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $stream = @stream_socket_client($address, $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $context);

        if (! $stream) {
            return $this->fail('Could not reach ' . $host . ':' . $port . ' — ' . ($errstr ?: 'no answer') . '.');
        }

        try {
            stream_set_timeout($stream, 10);
            $greeting = (string) fgets($stream, 1024);
            if (! str_contains($greeting, 'OK')) {
                return $this->fail('The server answered: ' . trim($greeting));
            }

            fwrite($stream, 'a1 LOGIN "' . addcslashes($user, '"\\') . '" "' . addcslashes($pass, '"\\') . '"' . "\r\n");

            $response = '';
            while (($line = fgets($stream, 2048)) !== false) {
                $response .= $line;
                if (str_starts_with($line, 'a1 ')) {
                    break;
                }
            }

            fwrite($stream, "a2 LOGOUT\r\n");

            return str_contains($response, 'a1 OK')
                ? $this->ok('Signed in to the inbox at ' . $host . '.')
                : $this->fail('The inbox refused the login: ' . trim($response));
        } finally {
            fclose($stream);
        }
    }

    /**
     * Will the receiving world believe this mail is ours?
     *
     * SPF says which servers may send for the domain, DKIM signs each
     * message, and DMARC tells everyone else what to do when either fails.
     * Missing any of them is why a correctly-sent invoice lands in spam,
     * and none of it is visible from inside the app — so it is looked up.
     */
    public function checkDns(array $sender): array
    {
        $address = $sender['from_address'] ?? '';
        $domain = str_contains($address, '@') ? mb_strtolower(explode('@', $address, 2)[1]) : '';

        if ($domain === '') {
            return ['ok' => false, 'message' => 'Set the from-address first.', 'checks' => []];
        }

        $spf = $this->findTxt($domain, 'v=spf1');
        $dmarc = $this->findTxt('_dmarc.' . $domain, 'v=DMARC1');
        [$dkimSelector, $dkim] = $this->findDkim($domain);

        $checks = [
            ['key' => 'SPF', 'pass' => $spf !== null, 'detail' => $spf
                ?: 'No SPF record on ' . $domain . '. Add one naming the servers allowed to send.'],
            ['key' => 'DKIM', 'pass' => $dkim !== null, 'detail' => $dkim
                ? 'Signing key found at ' . $dkimSelector . '._domainkey.' . $domain
                : 'No DKIM key found at the usual selectors. Your mail provider gives you the record to add.'],
            ['key' => 'DMARC', 'pass' => $dmarc !== null, 'detail' => $dmarc
                ?: 'No DMARC record on _dmarc.' . $domain . '. Start with p=none to watch before enforcing.'],
        ];

        $passed = collect($checks)->where('pass', true)->count();

        return [
            'ok' => $passed === count($checks),
            'domain' => $domain,
            'score' => (int) round($passed / count($checks) * 100),
            'checks' => $checks,
            'message' => $passed === count($checks)
                ? 'SPF, DKIM and DMARC are all in place for ' . $domain . '.'
                : $passed . ' of ' . count($checks) . ' in place — the rest is why mail can land in spam.',
        ];
    }

    /** The mailer this mailbox describes, decrypted at the last moment. */
    private function mailerFor(array $sender): \Illuminate\Mail\Mailer
    {
        $kind = $sender['mailer'] ?? 'none';

        if ($kind === 'ses') {
            return Mail::build([
                'transport' => 'ses',
                'key' => $this->secret($sender['ses_key'] ?? null),
                'secret' => $this->secret($sender['ses_secret'] ?? null),
                'region' => $sender['ses_region'] ?? 'ap-south-1',
            ]);
        }

        $encryption = $sender['smtp_encryption'] ?? 'tls';

        return Mail::build(array_filter([
            'transport' => 'smtp',
            'host' => $sender['smtp_host'] ?? null,
            'port' => (int) ($sender['smtp_port'] ?? 587),
            'scheme' => $encryption === 'ssl' ? 'smtps' : null,
            'encryption' => $encryption === 'none' ? null : 'tls',
            'username' => ($sender['smtp_username'] ?? null) ?: ($sender['from_address'] ?? null),
            'password' => $this->secret($sender['smtp_password'] ?? null),
        ], fn ($v) => $v !== null));
    }

    /** Stored secrets are encrypted; one that predates that is plain. */
    private function secret(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }

    private function findTxt(string $host, string $needle): ?string
    {
        foreach (@dns_get_record($host, DNS_TXT) ?: [] as $record) {
            $txt = $record['txt'] ?? implode('', $record['entries'] ?? []);
            if (stripos($txt, $needle) === 0) {
                return $txt;
            }
        }

        return null;
    }

    /**
     * DKIM lives at a selector the provider chose, and there is no way to
     * ask a domain which selectors exist — so the common ones are tried.
     * A miss means "not found at these", not "definitely absent".
     */
    private function findDkim(string $domain): array
    {
        $selectors = ['default', 'google', 'selector1', 'selector2', 's1', 's2',
            'mail', 'dkim', 'k1', 'smtp', 'amazonses', 'zoho', 'mandrill', 'sendgrid'];

        foreach ($selectors as $selector) {
            $host = $selector . '._domainkey.' . $domain;
            if ($this->findTxt($host, 'v=DKIM1') !== null || @dns_get_record($host, DNS_CNAME)) {
                return [$selector, 'found'];
            }
        }

        return [null, null];
    }

    private function ok(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
