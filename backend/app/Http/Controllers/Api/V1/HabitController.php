<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Habit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HabitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tz = $request->user()->profile?->timezone ?? config('app.timezone');
        $today = Carbon::now($tz)->startOfDay();

        $habits = Habit::where('user_id', $request->user()->id)
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->whereNull('archived_at'))
            ->with(['logs' => fn ($q) => $q->where('logged_on', '>=', now()->subDays(60))])
            ->orderBy('name')
            ->get()
            ->map(fn ($habit) => $this->serialize($habit, $today));

        return response()->json(['data' => $habits]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request); $habit = Habit::create($data + ['user_id' => $request->user()->id]);

        return response()->json([
            'message' => 'Habit created.',
            'data' => $this->serialize($habit->load('logs'), now()->startOfDay()),
        ], 201);
    }

    public function update(Request $request, Habit $habit): JsonResponse
    {
        abort_unless($habit->user_id === $request->user()->id, 403);

        $habit->update($this->validated($request, $habit));

        return response()->json([
            'message' => 'Habit updated.',
            'data' => $this->serialize($habit->fresh()->load('logs'), now()->startOfDay()),
        ]);
    }

    public function destroy(Request $request, Habit $habit): JsonResponse
    {
        abort_unless($habit->user_id === $request->user()->id, 403);

        $habit->delete();

        return response()->json(['message' => 'Habit deleted.']);
    }

    /** Log (or increment) today's completion; `date` allows backfilling. */
    public function log(Request $request, Habit $habit): JsonResponse
    {
        abort_unless($habit->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'date' => ['sometimes', 'date', 'before_or_equal:today'],
            'count' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        $tz = $request->user()->profile?->timezone ?? config('app.timezone');
        $date = isset($data['date'])
            ? Carbon::parse($data['date'])->toDateString()
            : Carbon::now($tz)->toDateString();

        // whereDate: the stored value carries a time component, so an equality
        // match on the bare date string would silently miss existing rows.
        $log = $habit->logs()->whereDate('logged_on', $date)->first()
            ?? $habit->logs()->make(['logged_on' => $date]);

        if (array_key_exists('count', $data)) {
            // Explicit count: 0 removes the log.
            if ((int) $data['count'] === 0) {
                $log->exists && $log->delete();
            } else {
                $log->count = (int) $data['count'];
                $log->save();
            }
        } else {
            $log->count = ($log->exists ? $log->count : 0) + 1;
            $log->save();
        }

        return response()->json([
            'message' => 'Logged.',
            'data' => $this->serialize($habit->fresh()->load('logs'), Carbon::now($tz)->startOfDay()),
        ]);
    }

    protected function validated(Request $request, ?Habit $habit = null): array
    {
        return $request->validate([
            'name' => [$habit ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'target_per_period' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            'reminder_time' => ['nullable', 'date_format:H:i'],
            'archived_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    protected function serialize(Habit $habit, Carbon $today): array
    { $todayCount = $habit->logs->firstWhere(
            fn ($log) => $log->logged_on->isSameDay($today)
        )?->count ?? 0;

        // Last 7 days for the mini heat-strip.
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = $today->copy()->subDays($i);
            $week[] = [
                'date' => $day->toDateString(),
                'count' => $habit->logs->firstWhere(fn ($l) => $l->logged_on->isSameDay($day))?->count ?? 0,
            ];
        }

        return [
            'uuid' => $habit->uuid,
            'name' => $habit->name,
            'description' => $habit->description,
            'frequency' => $habit->frequency,
            'target_per_period' => $habit->target_per_period,
            'icon' => $habit->icon,
            'color' => $habit->color,
            'reminder_time' => $habit->reminder_time,
            'is_archived' => $habit->archived_at !== null,
            'today_count' => $todayCount,
            'done_today' => $todayCount >= $habit->target_per_period,
            'streak' => $habit->currentStreak($today),
            'total_completions' => (int) $habit->logs->sum('count'),
            'week' => $week,
        ];
    }
}
