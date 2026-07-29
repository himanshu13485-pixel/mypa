<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoalMilestone extends Model
{
    protected $fillable = ['goal_id', 'title', 'due_on', 'is_done', 'sort_order'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'is_done' => 'boolean',
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
