<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One archive run: what was copied, where to, and how it went. */
class MailBackupRun extends Model
{
    use HasUuids;

    protected $table = 'crm_mail_backup_runs';

    protected $fillable = [
        'organization_id', 'mail_account_id', 'member_id', 'kind', 'destination',
        'status', 'messages', 'bytes', 'path', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'messages' => 'integer',
            'bytes' => 'integer',
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

    public function serialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'destination' => $this->destination,
            'status' => $this->status,
            'messages' => $this->messages,
            'bytes' => $this->bytes,
            'path' => $this->path,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'mailbox' => $this->account?->email,
        ];
    }
}
