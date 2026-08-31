<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The generic CRM activity trail. Employee changes, client edits, invoice
 * events — one table feeds every future "…Log" screen (User Log, Invoice
 * Log, Lead Log) instead of each module inventing its own.
 */
class ActivityLog extends Model
{
    protected $table = 'crm_activity_logs';

    protected $fillable = ['organization_id', 'member_id', 'action', 'subject_type', 'subject_id', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public static function record(?Member $actor, int $organizationId, string $action, Model $subject, ?array $changes = null): void
    {
        static::create([
            'organization_id' => $organizationId,
            'member_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'changes' => $changes,
        ]);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
