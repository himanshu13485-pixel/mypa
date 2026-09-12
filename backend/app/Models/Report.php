<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasUuids;

    public const REASONS = ['spam', 'harassment', 'inappropriate', 'impersonation', 'scam', 'other'];

    protected $fillable = [
        'reporter_id', 'reported_user_id', 'message_id', 'organization_id', 'reason', 'details',
        'status', 'action_taken', 'action_note', 'reviewed_by', 'reviewed_at',
        'escalated_at', 'escalated_by', 'escalation_note',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    /** The company both people belong to, when there is one. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Crm\Organization::class, 'organization_id');
    }

    public function escalator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    /**
     * The company two people share, if they share one.
     *
     * Both have to be active members of it. A report from somebody outside a
     * company about somebody inside it is not that company's internal matter
     * - it is a stranger's complaint, and it goes to the platform.
     */
    public static function sharedCompany(User $a, User $b): ?int
    {
        $ofA = \App\Models\Crm\Member::where('user_id', $a->id)->where('status', 'active')->pluck('organization_id');
        if ($ofA->isEmpty()) {
            return null;
        }

        return \App\Models\Crm\Member::where('user_id', $b->id)
            ->where('status', 'active')
            ->whereIn('organization_id', $ofA)
            ->whereHas('organization', fn ($o) => $o->where('status', 'active'))
            ->value('organization_id');
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
