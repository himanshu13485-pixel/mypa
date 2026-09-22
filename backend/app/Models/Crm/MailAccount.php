<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'organization_id', 'member_id', 'label', 'email', 'from_name', 'reply_to', 'provider',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'signature_html', 'auto_reply', 'forward_to', 'is_default',
        'sync_state', 'last_synced_at', 'last_error', 'status',
    ];

    protected $hidden = ['imap_password', 'smtp_password'];

    protected function casts(): array
    {
        return [
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'auto_reply' => 'array',
            'sync_state' => 'array',
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }

    /** Can it read? Can it send? - the two halves a mailbox can have. */
    public function canReceive(): bool
    {
        return filled($this->imap_host) && filled($this->imap_password);
    }

    public function canSend(): bool
    {
        return filled($this->smtp_host) && filled($this->smtp_password);
    }

    /** The address and name mail goes out as. */
    public function sender(): array
    {
        return ['address' => $this->email, 'name' => $this->from_name ?: $this->email];
    }

    public function serialize(): array
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
            'auto_reply' => $this->auto_reply ?: ['enabled' => false],
            'forward_to' => $this->forward_to,
            'is_default' => $this->is_default,
            'can_receive' => $this->canReceive(),
            'can_send' => $this->canSend(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'status' => $this->status,
        ];
    }
}
