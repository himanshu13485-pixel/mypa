<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in the conversation on a task.
 *
 * Nobody edits one and nobody deletes one: the whole use of a thread against
 * a pendency is being able to say later what was asked and what was answered,
 * and a record that can be rewritten afterwards cannot do that.
 */
class TaskComment extends Model
{
    use HasUuids;

    protected $table = 'crm_task_comments';

    protected $fillable = ['organization_id', 'task_id', 'member_id', 'body'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
