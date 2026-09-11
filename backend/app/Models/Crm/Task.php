<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasUuids;

    protected $table = 'crm_tasks';

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'in_progress', 'submitted', 'done', 'reopened'];

    /**
     * A plain task is work somebody was given. A pendency is work somebody
     * is holding up - accounts telling a salesperson that something on their
     * invoice is waiting on them. Same table, because it is the same loop:
     * it is issued, it is discussed, and one day it is finished.
     */
    public const KINDS = ['task', 'pendency'];

    protected $attributes = ['status' => 'open', 'priority' => 'normal', 'kind' => 'task'];

    protected $fillable = [
        'organization_id', 'title', 'description', 'assigned_member_id',
        'assigned_by', 'invoice_id', 'kind', 'due_at', 'start_at', 'end_at',
        'priority', 'status', 'progress_note',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
        'edited_at', 'edited_by', 'remind_at', 'remind_every_days',
        'assignee_snoozed_until', 'assigner_snoozed_until', 'last_reply_at', 'awaiting',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'edited_at' => 'datetime',
            'remind_at' => 'datetime',
            'assignee_snoozed_until' => 'datetime',
            'assigner_snoozed_until' => 'datetime',
            'last_reply_at' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assigned_member_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assigned_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'reviewed_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'edited_by');
    }

    /** The invoice or proforma this is about, if it is about one. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    /** Finished work stops asking to be remembered. */
    public function isLive(): bool
    {
        return $this->status !== 'done';
    }

    /**
     * Whose turn it is to be reminded, and whether now is the time.
     *
     * A reminder is for whoever owes the next word: the assignee once it is
     * issued, and the assigner once it has been answered. Either of them can
     * put it off for a day, and that is honoured for them alone - the other
     * side's reminder is not the same person's decision.
     */
    public function remindsNow(Member $viewer): bool
    {
        if (! $this->isLive() || $this->remind_at === null || $this->remind_at->isFuture()) {
            return false;
        }

        $isAssignee = $this->assigned_member_id === $viewer->id;
        $isAssigner = $this->assigned_by === $viewer->id;

        if (! $isAssignee && ! $isAssigner) {
            return false;
        }
        if ($this->awaiting === 'assignee' && ! $isAssignee) {
            return false;
        }
        if ($this->awaiting === 'assigner' && ! $isAssigner) {
            return false;
        }

        $snoozed = $this->awaiting === 'assigner' ? $this->assigner_snoozed_until : $this->assignee_snoozed_until;

        return $snoozed === null || $snoozed->isPast();
    }
}
