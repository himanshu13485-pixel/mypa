<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasUuids;

    protected $table = 'crm_tasks';

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const STATUSES = ['open', 'in_progress', 'submitted', 'done', 'reopened'];

    protected $attributes = ['status' => 'open', 'priority' => 'normal'];

    protected $fillable = [
        'organization_id', 'title', 'description', 'assigned_member_id',
        'assigned_by', 'due_at', 'priority', 'status', 'progress_note',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
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
}
