<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One mailbox somebody has added to Mails: how to read it, how to send
 * from it, and how it signs and answers on their behalf.
 *
 * It belongs to the member who added it. Passwords are encrypted at rest
 * and never serialised - the screen only learns whether one is on file.
 */
class MailAccount extends Model
{
    use HasUuids;

    protected $table = 'crm_mail_accounts';

    /** The well-known providers, filled in for people so they only type a password. */
    public const PROVIDERS = [
        'gmail' => [
            'label' => 'Gmail / Google Workspace',
            'imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'note' => 'Needs an app password (Google Account > Security > App passwords) - your normal password will be refused.',
        ],
        'outlook' => [
            'label' => 'Outlook / Microsoft 365',
            'imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'note' => 'IMAP and SMTP AUTH must be allowed for the mailbox in the Microsoft 365 admin centre.',
        ],
        'zoho' => [
            'label' => 'Zoho Mail',
            'imap_host' => 'imap.zoho.in', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.zoho.in', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'note' => 'Accounts hosted outside India use imap.zoho.com / smtp.zoho.com. Enable IMAP access in Zoho settings.',
        ],
        'yahoo' => [
            'label' => 'Yahoo Mail',
            'imap_host' => 'imap.mail.yahoo.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.mail.yahoo.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'note' => 'Needs an app password from Yahoo Account Security.',
        ],
        'ses' => [
            'label' => 'Amazon SES (sending) + your IMAP',
            'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => 'email-smtp.ap-south-1.amazonaws.com', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'note' => 'SES sends only; add the IMAP server of wherever the mailbox receives. Use SES SMTP credentials, not your AWS keys, and set the endpoint for your region.',
        ],
        'cpanel' => [
            'label' => 'cPanel / hosting mailbox',
            'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => '', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'note' => 'Usually mail.yourdomain.com for both, with the mailbox address as the username.',
        ],
        'custom' => [
            'label' => 'Other (enter the servers)',
            'imap_host' => '', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'smtp_host' => '', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'note' => null,
        ],
    ];

    protected $fillable = [
        'organization_id', 'member_id', 'created_by_member_id', 'is_shared', 'label', 'tag', 'email', 'from_name', 'reply_to', 'provider',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'signature_html', 'signature_reply_html', 'signature_on', 'signature_before_quote',
        'auto_reply', 'forward_to', 'forwards', 'is_default',
        'daily_cap', 'sent_today', 'cap_date', 'dkim_selector', 'dns', 'verify_cert',
        'sync_state', 'last_synced_at', 'last_error', 'sync_failures', 'sync_paused_until', 'status', 'detached_at', 'backup',
    ];

    protected $hidden = ['imap_password', 'smtp_password'];

    protected function casts(): array
    {
        return [
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'auto_reply' => 'array',
            'forwards' => 'array',
            'signature_before_quote' => 'boolean',
            'sync_state' => 'array',
            'dns' => 'array',
            // Cloud keys live in here, so the whole lot is encrypted at rest.
            'backup' => 'encrypted:array',
            'is_default' => 'boolean',
            'is_shared' => 'boolean',
            'verify_cert' => 'boolean',
            'cap_date' => 'date',
            'detached_at' => 'datetime',
            'daily_cap' => 'integer',
            'sent_today' => 'integer',
            'last_synced_at' => 'datetime',
            'sync_failures' => 'integer',
            'sync_paused_until' => 'datetime',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Everybody else the Admin let into this mailbox. */
    public function sharedMembers(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'crm_mail_account_member', 'mail_account_id', 'member_id')
            ->withPivot(['can_send', 'signature_html', 'is_default'])->withTimestamps();
    }

    /**
     * Every mailbox this person may open - which is every mailbox of theirs.
     *
     * A company mailbox given to three people is three mailboxes, one each,
     * pointing at the same address: nobody reads through somebody else's
     * row, so this is simply "mine".
     */
    public function scopeFor(Builder $query, Member $member): Builder
    {
        return $query->where('member_id', $member->id);
    }

    /** Only the owner's own mailboxes count against their allowance. */
    public function scopeOwnedBy(Builder $query, Member $member): Builder
    {
        return $query->where('member_id', $member->id);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }

    /** Can it read? Can it send? - the two halves a mailbox can have. */
    public function canReceive(): bool
    {
        return ! $this->detached_at && filled($this->imap_host) && filled($this->imap_password);
    }

    public function canSend(): bool
    {
        return ! $this->detached_at && filled($this->smtp_host) && filled($this->smtp_password);
    }

    /**
     * How long a mailbox is left alone after this many failures in a row.
     *
     * The first couple are forgiven outright - a mail server having a bad
     * minute is not a broken mailbox. After that the gap widens, because
     * what is left is nearly always something only a person can fix: a
     * password that changed, a host that moved, a certificate that expired.
     */
    private const SYNC_BACKOFF = [3 => 15, 5 => 60, 8 => 360];

    /**
     * How long to leave a mailbox alone, given how many times running it
     * has now failed - or null while it is still being forgiven.
     *
     * The count itself is kept by whoever noticed the failure, because the
     * same number decides two things: when a mailbox is worth announcing
     * as a problem, and when it stops being worth asking. They agree on
     * purpose - the pass that first says PROBLEM is the pass that first
     * lets it rest.
     */
    public static function restUntil(int $failures): ?\Illuminate\Support\Carbon
    {
        $minutes = 0;
        foreach (self::SYNC_BACKOFF as $after => $wait) {
            if ($failures >= $after) {
                $minutes = $wait;
            }
        }

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }

    /** This mailbox failed again just now, where nothing else counted it. */
    public function noteSyncFailure(): void
    {
        $failures = (int) $this->sync_failures + 1;

        $this->forceFill([
            'sync_failures' => $failures,
            'sync_paused_until' => self::restUntil($failures),
        ])->save();
    }

    /** It worked - whatever was wrong is over. */
    public function noteSyncSuccess(): void
    {
        if ($this->sync_failures || $this->sync_paused_until) {
            $this->forceFill(['sync_failures' => 0, 'sync_paused_until' => null])->save();
        }
    }

    /** Being left alone for now, after failing too many times in a row. */
    public function syncIsPaused(): bool
    {
        return $this->sync_paused_until !== null && $this->sync_paused_until->isFuture();
    }

    /**
     * Claim one of today's sends.
     *
     * A mailbox may be held to a daily limit - the mail servers people rent
     * impose one anyway, and hitting theirs gets an account suspended, while
     * hitting ours only delays a mail. The counter starts again each day.
     */
    public function claimSend(): bool
    {
        $today = now()->toDateString();
        if ($this->cap_date?->toDateString() !== $today) {
            $this->forceFill(['cap_date' => $today, 'sent_today' => 0])->save();
        }
        if ($this->daily_cap && $this->sent_today >= $this->daily_cap) {
            return false;
        }
        $this->forceFill(['sent_today' => $this->sent_today + 1])->save();

        return true;
    }

    /**
     * The addresses this mailbox actually forwards to.
     *
     * The verified entries in `forwards`, plus the older single `forward_to`
     * where a mailbox was set up before codes existed - so nothing anybody
     * already relies on stops arriving.
     */
    public static function verifiedForwards(self $account): array
    {
        $list = collect((array) $account->forwards)
            ->filter(fn ($f) => ! empty($f['verified_at']) && ! empty($f['address']))
            ->pluck('address');

        if ($account->forward_to) {
            $list->push($account->forward_to);
        }

        return $list->map(fn ($a) => strtolower(trim((string) $a)))->unique()->values()->all();
    }

    /** How many are left today, or null when nothing limits it. */
    public function sendsLeft(): ?int
    {
        if (! $this->daily_cap) {
            return null;
        }
        $used = $this->cap_date?->toDateString() === now()->toDateString() ? $this->sent_today : 0;

        return max(0, $this->daily_cap - $used);
    }

    /** The address and name mail goes out as. */
    public function sender(): array
    {
        return ['address' => $this->email, 'name' => $this->from_name ?: $this->email];
    }

    /** The mailbox as its owner sees it. */
    public function serialize(?Member $viewer = null): array
    {
        return [
            'uuid' => $this->uuid,
            'label' => $this->label,
            'email' => $this->email,
            'from_name' => $this->from_name,
            'reply_to' => $this->reply_to,
            'provider' => $this->provider,
            'imap_host' => $this->imap_host,
            'imap_port' => $this->imap_port,
            'imap_encryption' => $this->imap_encryption,
            'imap_username' => $this->imap_username,
            'has_imap_password' => filled($this->imap_password),
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption,
            'smtp_username' => $this->smtp_username,
            'has_smtp_password' => filled($this->smtp_password),
            'signature_html' => $this->signature_html,
            'signature_reply_html' => $this->signature_reply_html,
            'signature_on' => $this->signature_on ?: 'all',
            'signature_before_quote' => $this->signature_before_quote === null ? true : (bool) $this->signature_before_quote,
            // Addresses, and whether each has proved it wants the mail. The
            // codes themselves never leave the server.
            'forwards' => collect((array) $this->forwards)->map(fn (array $f) => [
                'address' => $f['address'] ?? '',
                'verified' => ! empty($f['verified_at']),
                'sent_at' => $f['code_sent_at'] ?? null,
            ])->values()->all(),
            'auto_reply' => $this->auto_reply ?: ['enabled' => false],
            'forward_to' => $this->forward_to,
            'is_default' => (bool) $this->is_default,
            'can_receive' => $this->canReceive(),
            'can_send' => $this->canSend(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'status' => $this->detached_at ? 'detached' : $this->status,
            // Everybody who holds this address, so a company mailbox can
            // say so without pretending anybody is borrowing it.
            'also_held_by' => self::where('organization_id', $this->organization_id)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $this->email)])
                ->where('id', '!=', $this->id)
                ->with('member.user:id,name,email')
                ->get()
                ->map(fn (self $a) => $a->member?->user?->name ?: $a->member?->user?->email)
                ->filter()->values()->all(),
            'tag' => $this->tag,
            'daily_cap' => $this->daily_cap,
            'sent_today' => $this->cap_date?->toDateString() === now()->toDateString() ? $this->sent_today : 0,
            'sends_left' => $this->sendsLeft(),
            'dkim_selector' => $this->dkim_selector,
            'verify_cert' => $this->verify_cert === null ? true : (bool) $this->verify_cert,
            'dns' => $this->dns,
            'detached_at' => $this->detached_at?->toIso8601String(),
            'created_by_admin' => (bool) $this->created_by_member_id,
        ];
    }
}
