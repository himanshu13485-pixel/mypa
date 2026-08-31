<?php

namespace App\Services\Crm;

use App\Models\Crm\Organization;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * Which mailbox a company's mail leaves from.
 *
 * Every issuing company can carry its OWN sender and its own mailbox (SMTP
 * or AWS SES, the way the grapme mailbox setup works): invoices from Acme
 * Exports leave from accounts@acme-exports.com through ITS server, never a
 * sister company's. Absent a company mailbox, the purpose-level sender from
 * the Communication setup applies, and the server default is the last
 * resort. Secrets are stored encrypted and decrypted only at send time.
 */
class CompanyMailer
{
    public function __construct(private Organization $org)
    {
    }

    /** The whole Communication setup, defaults filled. */
    public function settings(): array
    {
        return (array) data_get($this->org->settings, 'communication', []) + [
            'email_enabled' => true,
            'company_senders' => [],
        ];
    }

    /**
     * The mailer + sender for one company and purpose.
     *
     * @return array{mailer: Mailer, address: string, name: string}
     */
    public function resolve(?int $issuingCompanyId, string $purpose = 'default'): array
    {
        $comm = $this->settings();

        abort_unless((bool) ($comm['email_enabled'] ?? true), 422,
            'The Email channel is switched off in the Communication setup.');

        $sender = $issuingCompanyId !== null
            ? ((array) ($comm['company_senders'] ?? []))[(string) $issuingCompanyId] ?? null
            : null;

        // The address: the company's own, else the purpose-level one, else
        // the general one, else the server default.
        $purposeKey = $purpose === 'dues' ? 'dues_from_address'
            : ($purpose === 'invoice' ? 'invoice_from_address' : 'from_address');
        $address = ($sender['from_address'] ?? null)
            ?: (($comm[$purposeKey] ?? null) ?: (($comm['from_address'] ?? null) ?: config('mail.from.address')));
        $name = ($sender['from_name'] ?? null)
            ?: (($comm['from_name'] ?? null) ?: config('mail.from.name'));

        return [
            'mailer' => $this->transportFor($sender),
            'address' => $address,
            'name' => $name,
        ];
    }

    /** A company's own mailbox as a mailer, or the server default. */
    private function transportFor(?array $sender): Mailer
    {
        $kind = $sender['mailer'] ?? null;

        try {
            if ($kind === 'smtp' && ! empty($sender['smtp_host'])) {
                $encryption = $sender['smtp_encryption'] ?? 'tls';

                return Mail::build(array_filter([
                    'transport' => 'smtp',
                    'host' => $sender['smtp_host'],
                    'port' => (int) ($sender['smtp_port'] ?? 587),
                    // SSL/TLS (465) needs the smtps scheme; STARTTLS (587)
                    // negotiates on its own once the scheme stays plain.
                    'scheme' => $encryption === 'ssl' ? 'smtps' : null,
                    'encryption' => $encryption === 'none' ? null : 'tls',
                    // The login defaults to the mailbox address itself; the
                    // username field exists for hosts where the two differ.
                    'username' => ($sender['smtp_username'] ?? null) ?: ($sender['from_address'] ?? null),
                    'password' => $this->secret($sender['smtp_password'] ?? null),
                ], fn ($v) => $v !== null));
            }
            if ($kind === 'ses' && ! empty($sender['ses_key'])) {
                return Mail::build([
                    'transport' => 'ses',
                    'key' => $this->secret($sender['ses_key']),
                    'secret' => $this->secret($sender['ses_secret'] ?? null),
                    'region' => $sender['ses_region'] ?? 'ap-south-1',
                ]);
            }
        } catch (\Throwable) {
            // A broken mailbox config must not silence the mail entirely.
        }

        return Mail::mailer();
    }

    private function secret(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;   // stored before encryption existed
        }
    }
}
