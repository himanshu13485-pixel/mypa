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
     * Whose name a staff member's mail carries - the company they work for.
     *
     * Separate from forStaff() on purpose: which server sends is a question
     * of mailboxes, and a company with none set up (or one that fails and
     * falls back to the platform) still writes to its people as itself. Their
     * approval, their leave, their task: the subject, the heading, the
     * sign-off say the company, not Netvork. Null for somebody who is nobody's
     * employee - Netvork's own users hear from Netvork.
     */
    public static function brandFor(\App\Models\User $user): ?string
    {
        $member = \App\Models\Crm\Member::visible()->with('organization')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get()
            ->first(fn ($m) => $m->organization?->status === 'active');

        $name = trim((string) $member?->organization?->name);

        return $name !== '' ? $name : null;
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
    /** A mailbox with an address to send from - the screen's "Active". */
    public static function isSetUp(mixed $sender): bool
    {
        return is_array($sender) && filled($sender['from_address'] ?? null);
    }

    public function houseMailbox(): ?array
    {
        $senders = (array) ($this->settings()['company_senders'] ?? []);

        $chosen = collect($senders)->first(fn ($s) => ! empty($s['is_report_sender']) && self::isSetUp($s));

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
     * `source` says which rule answered, because "why did this go out as
     * GrapOut?" is asked about every invoice a group sends and the answer
     * was previously buried in this method.
     *
     * @return array{mailer: Mailer, address: string, name: string, source: string}
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

        /*
         * The company's own mailbox only once it is actually set up.
         *
         * A company gets a row on the Communication screen the moment it
         * exists, long before anybody fills its mailbox in - and that empty
         * row was being taken as its sender. So a company "Not set up" sent
         * its invoices through the platform's plain server instead of the
         * report sender's mailbox, which is the one place its mail was meant
         * to go until it had one of its own. "Set up" here means what it
         * means on that screen: an address to send from.
         */
        $ownMailbox = self::isSetUp($sender);
        if (! $ownMailbox) {
            $sender = null;
        }

        $sender ??= $this->houseMailbox();
        $fromHouse = ! $ownMailbox && is_array($sender);

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
            'source' => $ownMailbox ? 'company' : ($fromHouse ? 'house' : 'settings'),
        ];
    }

    /**
     * Who an invoice from this company would go out as, without sending one.
     *
     * The screens ask this so they can say it before the button is pressed:
     * a group with five issuing companies cannot otherwise tell which
     * mailbox a document is about to leave from.
     *
     * @return array{address: string, name: string, source: string, company: ?string}
     */
    public function senderFor(?int $issuingCompanyId, string $purpose = 'default'): array
    {
        $resolved = $this->resolve($issuingCompanyId, $purpose);
        $house = $this->houseMailbox();

        return [
            'address' => $resolved['address'],
            'name' => $resolved['name'],
            'source' => $resolved['source'],
            // The company whose mailbox answered, when it was not this one's.
            'company' => $resolved['source'] === 'house' ? ($house['from_name'] ?? null) : null,
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
