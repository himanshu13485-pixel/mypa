<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function upcoming(Request $request): JsonResponse
    {
        $reminders = TaskReminder::with('task:id,uuid,title,due_at,status')
            ->where('user_id', $request->user()->id)
            ->whereNull('acknowledged_at')
            ->whereHas('task', fn ($t) => $t->whereNotIn('status', ['completed', 'cancelled', 'archived']))
            ->orderBy('remind_at')
            ->paginate(20);

        return response()->json($reminders);
    }

    public function snooze(Request $request, TaskReminder $reminder): JsonResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'minutes' => ['required_without:until', 'integer', 'min:1', 'max:10080'],
            'until' => ['required_without:minutes', 'date', 'after:now'],
        ]);

        $until = isset($data['until'])
            ? \Illuminate\Support\Carbon::parse($data['until'])
            : now()->addMinutes((int) $data['minutes']);

        $reminder->update(['snoozed_until' => $until]);

        return response()->json([
            'message' => 'Reminder snoozed until ' . $until->toDayDateTimeString() . '.',
            'data' => $reminder->fresh(),
        ]);
    }

    public function acknowledge(Request $request, TaskReminder $reminder): JsonResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 403);

        $reminder->update(['acknowledged_at' => now(), 'snoozed_until' => null]);

        return response()->json(['message' => 'Reminder dismissed.']);
    }
}
