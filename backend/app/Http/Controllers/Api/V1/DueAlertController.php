<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\DueNow;
use Illuminate\Support\Carbon;

/**
 * The things whose time has come: tasks due, bills due.
 *
 * A due date that passes quietly is a due date nobody keeps. The app knew
 * both of these all along and said so only where somebody happened to be
 * looking — on the Tasks page, on the Bills page — so a person working in
 * the company CRM all morning heard nothing at all.
 *
 * One list for both, asked for from wherever the person is, so the alarm
 * belongs to the app rather than to a screen. What it does with it — the
 * ring, the box — is the client's business; this only answers what is due
 * and takes the answer "not yet".
 */
class DueAlertController extends Controller
{
    /** The kinds this answers for, and the models behind them. */
    private const KINDS = ['task' => Task::class, 'bill' => Bill::class];

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $now = now();

        $tasks = DueNow::tasks($me, $now)
            ->map(fn (Task $t) => [
                'kind' => 'task',
                'uuid' => $t->uuid,
                'title' => $t->title,
                'due_at' => $t->due_at?->toDateTimeString(),
                'note' => $t->priority ? ucfirst($t->priority) . ' priority' : null,
            ]);

        /*
         * A bill's own time of day, when it has been given one.
         *
         * due_on is a date and due_time the hour somebody wants to be told;
         * without the second, "due today" would ring at midnight, which is
         * the one time of day nobody wants to hear about a bill.
         */
        $bills = DueNow::bills($me, $now)
            ->map(fn (Bill $b) => [
                'kind' => 'bill',
                'uuid' => $b->uuid,
                'title' => $b->name,
                'due_at' => $b->due_on->toDateString() . ($b->due_time ? ' ' . $b->due_time : ''),
                'note' => $b->amount ? number_format((float) $b->amount, 2) . ' ' . ($b->currency ?: 'INR') : null,
            ])
            ->values();

        return response()->json(['data' => $tasks->concat($bills)->values()]);
    }

    /**
     * Not now — ring again in a quarter of an hour, or an hour.
     *
     * Dismissing is the same act with a longer arm: it is never "this is
     * done", because the thing is still due and still wants doing. It goes
     * quiet until tomorrow morning instead of for ever.
     */
    public function snooze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:task,bill'],
            'uuid' => ['required', 'string'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        $model = self::KINDS[$data['kind']];
        $item = $model::where('user_id', $request->user()->id)->where('uuid', $data['uuid'])->firstOrFail();

        $until = isset($data['minutes'])
            ? now()->addMinutes((int) $data['minutes'])
            : now()->addDay()->setTime(9, 0);
        $item->forceFill(['alert_snoozed_until' => $until])->save();

        return response()->json([
            'message' => isset($data['minutes'])
                ? 'Back in ' . $data['minutes'] . ' minutes.'
                : 'Quiet until tomorrow morning.',
            'until' => $until->toDateTimeString(),
        ]);
    }
}
