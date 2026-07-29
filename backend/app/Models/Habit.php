<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Habit extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id', 'name', 'description', 'frequency', 'target_per_period',
        'icon', 'color', 'reminder_time', 'archived_at',
    ];

    /** Mirror the DB defaults so a freshly created model is safe to use. */
    protected $attributes = [
        'frequency' => 'daily',
        'target_per_period' => 1,
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    /**
     * Current streak of consecutive periods (days/weeks/months) with at least
     * target_per_period completions, counted back from today (an incomplete
     * "today" does not break the streak).
     */
    public function currentStreak(?Carbon $today = null): int
    {
        $today = ($today ?? now())->startOfDay();
        // Guard: a zero/null target would make every period "done" (0 >= null
        // is true in PHP) and the walk-back below would never terminate.
        $target = max(1, (int) $this->target_per_period);
        $logs = $this->logs()->orderByDesc('logged_on')->limit(400)->get()
            ->keyBy(fn ($log) => $log->logged_on->toDateString());

        $streak = 0;
        $cursor = $today->copy();

        $step = fn (Carbon $c) => match ($this->frequency) {
            'weekly' => $c->subWeek(),
            'monthly' => $c->subMonthNoOverflow(),
            default => $c->subDay(),
        };

        $periodDone = function (Carbon $c) use ($logs, $target): bool {
            return match ($this->frequency) {
                'weekly' => $logs->filter(fn ($l) => $l->logged_on->isSameWeek($c))->sum('count') >= $target,
                'monthly' => $logs->filter(fn ($l) => $l->logged_on->isSameMonth($c))->sum('count') >= $target,
                default => ($logs[$c->toDateString()]->count ?? 0) >= $target,
            };
        };

        // Today/current period may still be in progress — skip it if not done.
        if (! $periodDone($cursor)) {
            $cursor = $step($cursor);
        }

        while ($periodDone($cursor)) {
            $streak++;
            $cursor = $step($cursor);
        }

        return $streak;
    }
}
