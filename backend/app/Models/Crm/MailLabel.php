<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A person's own tag for mail - one message can wear several. */
class MailLabel extends Model
{
    use HasUuids;

    protected $table = 'crm_mail_labels';

    protected $fillable = ['organization_id', 'member_id', 'name', 'color'];

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
}
