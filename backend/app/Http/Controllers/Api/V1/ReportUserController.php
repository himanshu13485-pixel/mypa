<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Report;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** User-facing side of the moderation system: file a report. */
class ReportUserController extends Controller
{
    public function store(Request $request, AppIdService $appIds): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required_without:message_uuid', 'nullable', 'string', 'max:255'],
            'message_uuid' => ['required_without:identifier', 'nullable', 'uuid'],
            'reason' => ['required', 'in:' . implode(',', Report::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        $me = $request->user();
        $message = null;
        $target = null;

        if (! empty($data['message_uuid'])) {
            $message = Message::with('conversation')->where('uuid', $data['message_uuid'])->first();
            abort_unless($message && $message->conversation->hasMember($me), 404, 'Message not found.');
            abort_if($message->user_id === $me->id, 422, 'You cannot report your own message.');
            $target = $message->user;
        } else {
            $target = $appIds->findVisibleUser($data['identifier'], $me);
            if (! $target || $target->id === $me->id) {
                return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
            }
        }

        // One open report per reporter/target/message.
        $exists = Report::where('reporter_id', $me->id)
            ->where('reported_user_id', $target->id)
            ->where('message_id', $message?->id)
            ->where('status', 'open')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'You already have an open report for this. Our moderators will review it.'], 409);
        }

        Report::create([
            'reporter_id' => $me->id,
            'reported_user_id' => $target->id,
            'message_id' => $message?->id,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
        ]);

        return response()->json([
            'message' => 'Report submitted. A moderator will review it — thank you for keeping My PA safe.',
        ], 201);
    }
}
