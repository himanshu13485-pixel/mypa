<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One email, in one mailbox, in one folder.
 *
 * Arrived mail is a copy of what the server holds; mail going out starts
 * life here - a draft, then the outbox while it can still be stopped, then
 * sent. The folder says where it is, the status where it is going.
 */
class MailMessage extends Model
{
    use HasUuids;

    protected $table = 'crm_mail_messages';

    /** The folders every mailbox has, in the order the sidebar lists them. */
    public const FOLDERS = ['inbox', 'outbox', 'drafts', 'scheduled', 'sent', 'spam', 'trash', 'archive'];

    protected $fillable = [
        'organization_id', 'mail_account_id', 'folder', 'remote_folder', 'uid', 'message_id',
        'in_reply_to', 'reference_ids', 'thread_key', 'from_name', 'from_email', 'to', 'cc', 'bcc',
        'reply_to', 'subject', 'snippet', 'body_html', 'body_text', 'has_attachments', 'is_read',
        'is_starred', 'date', 'scheduled_for', 'send_after', 'status', 'error', 'size', 'trashed_from',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'has_attachments' => 'boolean',
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'date' => 'datetime',
            'scheduled_for' => 'datetime',
            'send_after' => 'datetime',
            'uid' => 'integer',
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(MailLabel::class, 'crm_mail_label_message');
    }

    /**
     * The key a conversation is filed under.
     *
     * The first message-id in the chain when there is one - every reply
     * carries it in References - and otherwise the subject with its Re: and
     * Fwd: prefixes worn off, which is how people's eyes group mail anyway.
     */
    public static function threadKeyFor(?string $references, ?string $inReplyTo, ?string $messageId, ?string $subject): string
    {
        $chain = trim((string) $references);
        if ($chain !== '') {
            preg_match('/<[^>]+>/', $chain, $first);
            if (! empty($first[0])) {
                return substr(hash('sha256', strtolower($first[0])), 0, 40);
            }
        }
        if (filled($inReplyTo)) {
            return substr(hash('sha256', strtolower(trim($inReplyTo))), 0, 40);
        }

        $plain = strtolower(trim((string) preg_replace('/^(\s*(re|fw|fwd|aw|sv)\s*(\[\d+\])?\s*:\s*)+/i', '', (string) $subject)));
        if ($plain !== '') {
            return 's' . substr(hash('sha256', $plain), 0, 39);
        }

        return substr(hash('sha256', strtolower((string) ($messageId ?: uniqid('', true)))), 0, 40);
    }

    /** The row the list draws. */
    public function summary(): array
    {
        return [
            'uuid' => $this->uuid,
            'folder' => $this->folder,
            'thread_key' => $this->thread_key,
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'to' => $this->to ?? [],
            'subject' => $this->subject,
            'snippet' => $this->snippet,
            'has_attachments' => $this->has_attachments,
            'is_read' => $this->is_read,
            'is_starred' => $this->is_starred,
            'date' => $this->date?->toIso8601String(),
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'send_after' => $this->send_after?->toIso8601String(),
            'status' => $this->status,
            'error' => $this->error,
            'account_uuid' => $this->account?->uuid,
            'labels' => $this->relationLoaded('labels')
                ? $this->labels->map(fn (MailLabel $l) => ['uuid' => $l->uuid, 'name' => $l->name, 'color' => $l->color])->values()
                : [],
        ];
    }

    /** Everything the reader needs. */
    public function full(): array
    {
        return $this->summary() + [
            'cc' => $this->cc ?? [],
            'bcc' => $this->bcc ?? [],
            'reply_to' => $this->reply_to,
            'message_id' => $this->message_id,
            'body_html' => $this->body_html,
            'body_text' => $this->body_text,
            'attachments' => $this->attachments->map(fn (MailAttachment $a) => [
                'id' => $a->id,
                'filename' => $a->filename,
                'mime' => $a->mime,
                'size' => $a->size,
                'is_inline' => $a->is_inline,
            ])->values(),
        ];
    }
}
