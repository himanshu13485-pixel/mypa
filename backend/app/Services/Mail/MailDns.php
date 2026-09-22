<?php

namespace App\Services\Mail;

/**
 * What the receiving world can check about a sending domain.
 *
 * Three records decide whether mail from a domain lands in the inbox or the
 * spam folder, and none of them lives in this application - they live in
 * the domain's DNS:
 *
 *   SPF    names the servers allowed to send as the domain
 *   DKIM   publishes the key their signatures are checked against
 *   DMARC  tells the receiver what to do when the first two fail
 *
 * This reads them and scores them, so somebody setting a mailbox up learns
 * what is missing here rather than from a week of mail going to junk.
 *
 * The lookup itself is one method, so a test can answer with fixed records
 * instead of asking the internet.
 */
class MailDns
{
    /** Selectors worth trying when nobody has said which one is used. */
    public const COMMON_SELECTORS = [
        'default', 'google', 'selector1', 'selector2', 's1', 's2', 'k1', 'k2',
        'zoho', 'zmail', 'mail', 'dkim', 'smtp', 'mandrill', 'sendgrid', 'ses', 'amazonses', 'pm', 'mailgun',
    ];

    /** The TXT records at a name, as plain strings. A test overrides this. */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return collect($records)
            ->map(fn (array $r) => (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? []))))
            ->filter()->values()->all();
    }

    /** The domain a mailbox sends as: the part after the @. */
    public static function domainOf(string $email): string
    {
        return strtolower(trim(substr(strrchr($email, '@') ?: '', 1)));
    }

    /**
     * Check one sending domain and score it out of 100.
     *
     * SPF and DKIM are 30 each - without either, receivers have nothing to
     * check. DMARC is 40, and only a policy that asks for something
     * (quarantine or reject) earns all of it: "p=none" watches without
     * protecting, which is a start rather than an arrival.
     */
    public function check(string $email, ?string $selector = null): array
    {
        $domain = self::domainOf($email);
        if ($domain === '') {
            return ['domain' => '', 'score' => 0, 'checked_at' => now()->toIso8601String(),
                'spf' => $this->fail('That is not an address with a domain in it.'),
                'dkim' => $this->fail('No domain to look in.'),
                'dmarc' => $this->fail('No domain to look in.')];
        }

        $spf = $this->spf($domain);
        $dkim = $this->dkim($domain, $selector);
        $dmarc = $this->dmarc($domain);

        $score = ($spf['ok'] ? 30 : 0)
            + ($dkim['ok'] ? 30 : 0)
            + ($dmarc['ok'] ? ($dmarc['policy'] === 'none' ? 20 : 40) : 0);

        return [
            'domain' => $domain,
            'spf' => $spf,
            'dkim' => $dkim,
            'dmarc' => $dmarc,
            'score' => $score,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function spf(string $domain): array
    {
        $records = array_values(array_filter($this->txt($domain), fn ($t) => str_starts_with(strtolower(trim($t)), 'v=spf1')));

        if (! $records) {
            return $this->fail('No SPF record. Add a TXT record on ' . $domain . ' that starts with "v=spf1" and names the servers that may send for you.');
        }
        if (count($records) > 1) {
            return $this->fail('More than one SPF record - receivers treat that as a failure. Merge them into one TXT record.', $records[0]);
        }

        $record = trim($records[0]);
        $all = str_contains($record, '-all') ? 'strict (-all)' : (str_contains($record, '~all') ? 'soft (~all)' : 'open');

        return ['ok' => true, 'record' => $record, 'note' => 'SPF found, ending ' . $all . '.'];
    }

    private function dkim(string $domain, ?string $selector): array
    {
        foreach (array_filter([$selector, ...self::COMMON_SELECTORS]) as $candidate) {
            foreach ($this->txt($candidate . '._domainkey.' . $domain) as $record) {
                if (stripos($record, 'p=') !== false) {
                    return [
                        'ok' => true,
                        'selector' => $candidate,
                        'record' => mb_substr($record, 0, 200),
                        'note' => 'DKIM key published for selector "' . $candidate . '".',
                    ];
                }
            }
        }

        return $this->fail(
            'No DKIM key found. Your mail provider gives you the selector and record - publish it at <selector>._domainkey.' . $domain
            . ', then enter the selector here so this check knows where to look.'
        );
    }

    private function dmarc(string $domain): array
    {
        foreach ($this->txt('_dmarc.' . $domain) as $record) {
            if (stripos(trim($record), 'v=dmarc1') !== 0) {
                continue;
            }
            preg_match('/\bp\s*=\s*(none|quarantine|reject)/i', $record, $m);
            $policy = strtolower($m[1] ?? 'none');

            return [
                'ok' => true,
                'policy' => $policy,
                'record' => trim($record),
                'note' => $policy === 'none'
                    ? 'DMARC is watching only (p=none). Move to quarantine once SPF and DKIM pass for everything you send.'
                    : 'DMARC is enforcing (p=' . $policy . ').',
            ];
        }

        return $this->fail('No DMARC record. Add a TXT record at _dmarc.' . $domain . ' such as "v=DMARC1; p=none; rua=mailto:you@' . $domain . '" and tighten it later.');
    }

    private function fail(string $note, ?string $record = null): array
    {
        return ['ok' => false, 'record' => $record, 'note' => $note];
    }
}
