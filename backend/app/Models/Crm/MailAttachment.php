<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on an email. For mail that arrived it is a pointer into the
 * server's copy, fetched when opened; for mail going out, a local file.
 */
class MailAttachment extends Model
{
    protected $table = 'crm_mail_attachments';

    protected $fillable = ['mail_message_id', 'filename', 'mime', 'size', 'content_id', 'is_inline', 'part', 'path'];

    protected function casts(): array
    {
        return ['is_inline' => 'boolean', 'size' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }
}
