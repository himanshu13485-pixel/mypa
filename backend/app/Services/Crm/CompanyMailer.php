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

    /**
     * The mailbox a company's own staff hear from.
     *
     * Somebody who signs up on Netvork is Netvork's user, and their codes
     * come from the platform. The day a company takes them on as an
     * employee, the company becomes the one writing to them — so the code
     * arrives from an address they recognise as work, through that
     * company's own server, which is also the only way it passes SPF and
     * DKIM for that domain rather than landing in spam.
     *
     * Which mailbox, when a group runs several: the one the Admin marked
     * as the report sender, because an answer somebody chose beats one
     * this code inferred. Failing that, the company that pays the salaries,
     * since that is the one that employs people; failing that, the only
     * company with a mailbox at all — an unambiguous answer or none, never
     * a guess between two. Failing all of it, the company's general sender;
     * and if nothing is set up, null, and the platform sends as before.
     *
     * @return array{mailer: Mailer, address: string, name: string}|null
     */
    public static function forStaff(\App\Models\User $user): ?array
    {
        $member = \App\Models\Crm\Member::visible()->with('organization')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get()
            ->first(fn ($m) => $m->organization?->status === 'active');

        if (! $member) {
            return null;
        }

        $org = $member->organization;
        $comm = (array) data_get($org->settings, 'communication', []);

        // A company that has switched its own mail off does not get to
        // switch off its employees' sign-in codes: the platform sends those.
        if (! ($comm['email_enabled'] ?? true)) {
            return null;
        }

        $senders = (array) ($comm['company_senders'] ?? []);

        $sender = (new self($org))->houseMailbox();

        $address = ($sender['from_address'] ?? null) ?: ($comm['from_address'] ?? null);
        if (! $address) {
            return null;
        }

        return [
            'mailer' => (new self($org))->transportFor(is_array($sender) ? $sender : null),
            'address' => $address,
            'name' => ($sender['from_name'] ?? null) ?: (($comm['from_name'] ?? null) ?: $org->name),
        ];
    }

    /**
     * The company's own mailbox, when the mail is not any one company's.
     *
     * A newsletter, a staff notification, a sign-in code: none of them belong
     * to an issuing company, and until this existed they all left through the
     * platform's default server. A company that has gone to the trouble of
     * setting up its own SMTP has said where its mail comes from, and meant
     * all of it — not only the invoices.
     *
     * Which one, when a group runs several: the mailbox an Admin marked as
     * the report sender, because an answer somebody chose beats one this code
     * inferred. Failing that the company that pays the salaries, since that
     * is the one that employs people. Failing that the only company with a
     * mailbox at all — an unambiguous answer or none, never a guess between
     * two.
     */
    public function houseMailbox(): ?array
    {
        $senders = (array) ($this->settings()['company_senders'] ?? []);

        $chosen = collect($senders)->first(fn ($s) => ! empty($s['is_report_sender']));

        if (! $chosen) {
            $employer = \App\Models\Crm\IssuingCompany::where('organization_id', $this->org->id)
                ->where('pays_salary', true)->first();
            $chosen = $employer ? ($senders[(string) $employer->id] ?? null) : null;
        }

        if (! $chosen) {
            $withMailbox = collect($senders)->filter(fn ($s) => ($s['mailer'] ?? 'none') !== 'none');
            $chosen = $withMailbox->count() === 1 ? $withMailbox->first() : null;
        }

        return is_array($chosen) ? $chosen : null;
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

        /*
         * The named company's mailbox, or the company's own.
         *
         * Falling straight through to the platform's server whenever no
         * issuing company was named is what sent newsletters and staff mail
         * out as Netvork from a company that had configured its own SMTP —
         * failing that company's SPF and DKIM, and arriving from an address
         * their recipients do not recognise.
         */
        $sender = $issuingCompanyId !== null
            ? ((array) ($comm['company_senders'] ?? []))[(string) $issuingCompanyId] ?? null
            : null;

        $sender ??= $this->houseMailbox();

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
