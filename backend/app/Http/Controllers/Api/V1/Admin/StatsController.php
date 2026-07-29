<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'users' => [
                    'total' => User::count(),
                    'active' => User::where('status', 'active')->count(),
                    'suspended' => User::where('status', 'suspended')->count(),
                    'new_this_week' => User::where('created_at', '>=', now()->subWeek())->count(),
                    'online_last_hour' => LoginHistory::where('logged_in_at', '>=', now()->subHour())
                        ->distinct('user_id')->count('user_id'),
                ],
                'tasks' => [
                    'total' => Task::count(),
                    'completed' => Task::where('status', 'completed')->count(),
                    'overdue' => Task::overdue()->count(),
                    'created_this_week' => Task::where('created_at', '>=', now()->subWeek())->count(),
                ],
                'registrations_by_day' => User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                    ->where('created_at', '>=', now()->subDays(30))
                    ->groupBy('date')->orderBy('date')->get(),
            ],
        ]);
    }
}
