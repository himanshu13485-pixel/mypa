<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\RecurringTaskService;
use Illuminate\Console\Command;

class GenerateRecurringTasks extends Command
{
    protected $signature = 'mypa:generate-recurring';

    protected $description = 'Roll forward recurring tasks whose occurrence was completed or missed';

    public function handle(RecurringTaskService $service): int
    {
        $generated = 0;

        // Completed recurring tasks that don't have a next occurrence yet.
        Task::whereNotNull('repeat_config')
            ->where('status', 'completed')
            ->with(['checklists', 'reminders', 'assignees', 'tags'])
            ->chunkById(100, function ($tasks) use ($service, &$generated) {
                foreach ($tasks as $task) {
                    if ($service->generateNext($task)) {
                        $generated++;
                    }
                    // Detach the finished occurrence from the series so it is
                    // not re-examined on every run.
                    $task->updateQuietly(['repeat_config' => null]);
                }
            });

        // Missed recurring tasks: due more than a day ago and never completed —
        // generate the next occurrence but keep the overdue one visible.
        Task::whereNotNull('repeat_config')
            ->whereNotIn('status', ['completed', 'cancelled', 'archived'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->subDay())
            ->with(['checklists', 'reminders', 'assignees', 'tags'])
            ->chunkById(100, function ($tasks) use ($service, &$generated) {
                foreach ($tasks as $task) {
                    if ($service->generateNext($task)) {
                        $generated++;
                        $task->updateQuietly(['repeat_config' => null]);
                    }
                }
            });

        $this->info("Generated {$generated} recurring occurrence(s).");

        return self::SUCCESS;
    }
}
