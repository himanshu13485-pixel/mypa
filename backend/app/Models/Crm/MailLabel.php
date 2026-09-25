<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A mailbox's own tag for mail - one message can wear several.
 *
 * The mailbox owns it, not the person: somebody holding two mailboxes files
 * two different sorts of correspondence and should not see one's labels
 * while reading the other.
 */
class MailLabel extends Model
{
    use HasUuids;

    protected $table = 'crm_mail_labels';

    protected $fillable = ['organization_id', 'member_id', 'mail_account_id', 'name', 'color'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(MailMessage::class, 'crm_mail_label_message');
    }

    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /** The standing rules that file mail under this label. */
    public function filters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MailFilter::class, 'mail_label_id');
    }
}
