<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $tz = $user->profile?->timezone ?? config('app.timezone');

        $todayStart = now($tz)->startOfDay()->utc();
        $todayEnd = now($tz)->endOfDay()->utc();

        $base = fn () => Task::visibleTo($user)->where('status', '!=', 'archived');

        $total = $base()->count();
        $completed = $base()->where('status', 'completed')->count();

        $counts = [
            'today' => $base()->whereBetween('due_at', [$todayStart, $todayEnd])->count(),
            'upcoming' => $base()->where('due_at', '>', $todayEnd)
                ->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'overdue' => $base()->overdue()->count(),
            'important' => $base()->where('is_important', true)
                ->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'completed' => $completed,
            'pending' => $base()->whereNotIn('status', ['completed', 'cancelled'])->count(),
            'assigned_to_me' => Task::whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
                ->whereNotIn('status', ['completed', 'cancelled', 'archived'])->count(),
            'assigned_by_me' => $user->tasks()->has('assignees')
                ->whereNotIn('status', ['completed', 'cancelled', 'archived'])->count(),
            'total' => $total,
            'completion_rate' => $total > 0 ? round($completed / $total * 100) : 0,
        ];

        return response()->json([
            'data' => [
                'counts' => $counts,
                'today_tasks' => TaskResource::collection(
                    $base()->with('category')
                        ->whereBetween('due_at', [$todayStart, $todayEnd])
                        ->orderBy('due_at')->limit(10)->get()
                ),
                'overdue_tasks' => TaskResource::collection(
                    $base()->with('category')->overdue()->orderBy('due_at')->limit(10)->get()
                ),
                'recent_tasks' => TaskResource::collection(
                    $base()->with('category')->latest()->limit(5)->get()
                ),
            ],
        ]);
    }
}
