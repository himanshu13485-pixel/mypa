<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecurringTaskService
{
    /**
     * When a recurring task is completed (or rolled forward), create the next
     * occurrence: a fresh copy shifted by the repeat interval, with checklists
     * reset and reminders re-created relative to the new due date.
     *
     * Returns the new task, or null when the recurrence has ended.
     */
    public function generateNext(Task $task): ?Task
    {
        $config = $task->repeat_config;

        if (! $config || empty($config['frequency'])) {
            return null;
        }

        $baseDue = $task->due_at ?? $task->start_at ?? now();
        $nextDue = $this->advance(Carbon::parse($baseDue), $config);

        if (! $nextDue) {
            return null;
        }

        // Recurrence ended?
        if (! empty($config['until']) && $nextDue->gt(Carbon::parse($config['until'])->endOfDay())) {
            return null;
        }

        // Avoid duplicates: a pending occurrence for this series at that date already exists.
        $exists = Task::where('user_id', $task->user_id)
            ->where('title', $task->title)
            ->where('due_at', $nextDue)
            ->whereNotIn('status', ['completed', 'cancelled', 'archived'])
            ->exists();

        if ($exists) {
            return null;
        }

        return DB::transaction(function () use ($task, $nextDue) {
            $shift = $task->due_at && $task->start_at
                ? $task->due_at->diffInSeconds($task->start_at, false)
                : null;

            $next = Task::create(array_merge(
                $task->only([
                    'user_id', 'category_id', 'parent_id', 'title', 'description', 'priority',
                    'estimated_minutes', 'location', 'contact_person', 'color',
                    'is_important', 'is_confidential', 'repeat_config',
                ]),
                [
                    'status' => 'not_started',
                    'progress' => 0,
                    'due_at' => $nextDue,
                    'start_at' => $shift !== null ? $nextDue->copy()->addSeconds($shift) : null,
                ]
            ));

            foreach ($task->checklists as $item) {
                $next->checklists()->create($item->only(['title', 'sort_order']));
            }

            foreach ($task->reminders as $reminder) {
                $remindAt = $reminder->offset_minutes !== null
                    ? $nextDue->copy()->subMinutes($reminder->offset_minutes)
                    : $nextDue;

                $next->reminders()->create([
                    'user_id' => $reminder->user_id,
                    'remind_at' => $remindAt,
                    'offset_minutes' => $reminder->offset_minutes,
                    'channels' => $reminder->channels,
                    'repeat_until_acknowledged' => $reminder->repeat_until_acknowledged,
                ]);
            }

            foreach ($task->assignees as $assignee) {
                $next->assignees()->attach($assignee->id, ['assigned_by' => $assignee->pivot->assigned_by]);
            }

            $next->tags()->sync($task->tags->pluck('id'));

            $next->logActivity(null, 'recurring_generated', ['from' => $task->uuid]);

            return $next;
        });
    }

    protected function advance(Carbon $from, array $config): ?Carbon
    {
        $interval = max(1, (int) ($config['interval'] ?? 1));

        return match ($config['frequency']) {
            'daily' => $from->copy()->addDays($interval),
            'weekly' => $from->copy()->addWeeks($interval),
            'monthly' => $from->copy()->addMonthsNoOverflow($interval),
            'yearly' => $from->copy()->addYearsNoOverflow($interval),
            'custom' => isset($config['days']) ? $from->copy()->addDays(max(1, (int) $config['days'])) : null,
            default => null,
        };
    }
}
