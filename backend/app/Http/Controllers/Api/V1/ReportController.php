<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = fn () => Task::visibleTo($user);

        $total = $base()->count();
        $completed = $base()->where('status', 'completed')->count();

        $byStatus = $base()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $byPriority = $base()
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority');

        $byCategory = $base()
            ->with('category:id,uuid,name,color')
            ->get()
            ->groupBy(fn ($t) => $t->category?->name ?? 'Uncategorised')
            ->map(fn ($tasks, $name) => [
                'name' => $name,
                'color' => $tasks->first()->category?->color,
                'total' => $tasks->count(),
                'completed' => $tasks->where('status', 'completed')->count(),
            ])
            ->values();

        // Average completion time (created → completed), in hours.
        $avgHours = $base()
            ->whereNotNull('completed_at')
            ->get(['created_at', 'completed_at'])
            ->map(fn ($t) => $t->created_at->diffInHours($t->completed_at))
            ->avg();

        return response()->json([
            'data' => [
                'totals' => [
                    'total' => $total,
                    'completed' => $completed,
                    'pending' => $base()->whereNotIn('status', ['completed', 'cancelled', 'archived'])->count(),
                    'overdue' => $base()->overdue()->count(),
                    'important' => $base()->where('is_important', true)->count(),
                    'completion_rate' => $total > 0 ? round($completed / $total * 100) : 0,
                    'avg_completion_hours' => $avgHours !== null ? round($avgHours, 1) : null,
                ],
                'by_status' => $byStatus,
                'by_priority' => $byPriority,
                'by_category' => $byCategory,
            ],
        ]);
    }

    /** Completed and created counts per day for the last N days. */
    public function productivity(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = min(90, max(7, (int) $request->query('days', 30)));
        $from = now()->subDays($days - 1)->startOfDay();

        $completed = Task::visibleTo($user)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $from)
            ->get(['completed_at'])
            ->groupBy(fn ($t) => $t->completed_at->toDateString())
            ->map->count();

        $created = Task::visibleTo($user)
            ->where('created_at', '>=', $from)
            ->get(['created_at'])
            ->groupBy(fn ($t) => $t->created_at->toDateString())
            ->map->count();

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'completed' => $completed[$date] ?? 0,
                'created' => $created[$date] ?? 0,
            ];
        }

        return response()->json(['data' => $series]);
    }

    /** CSV export of the user's tasks (respects the same filters as the list). */
    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();

        $tasks = Task::visibleTo($user)
            ->with(['category:id,name', 'group:id,name', 'user:id,name'])
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($tasks) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Title', 'Status', 'Priority', 'Category', 'Group', 'Owner',
                'Due date', 'Completed at', 'Progress %', 'Important', 'Created at',
            ]);
            foreach ($tasks as $t) {
                fputcsv($out, [
                    $t->title,
                    $t->status,
                    $t->priority,
                    $t->category?->name,
                    $t->group?->name,
                    $t->user?->name,
                    $t->due_at?->toDateTimeString(),
                    $t->completed_at?->toDateTimeString(),
                    $t->progress,
                    $t->is_important ? 'yes' : 'no',
                    $t->created_at->toDateTimeString(),
                ]);
            }
            fclose($out);
        }, 'mypa-tasks.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }
}
