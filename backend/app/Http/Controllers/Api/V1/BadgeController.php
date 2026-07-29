<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\MessageDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unattended counters for the sidebar: each number drops as the user attends
 * the item (reads the conversation, opens the Calls page, answers the request).
 */
class BadgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        // Unread messages across conversations (others' messages after my last_read_at).
        $hidden = MessageDeletion::where('user_id', $me->id)->pluck('message_id');
        $messages = 0;
        Conversation::visibleTo($me)->with('members')->get()->each(function ($conversation) use ($me, $hidden, &$messages) {
            $lastRead = $conversation->members->firstWhere('id', $me->id)?->pivot->last_read_at;
            $messages += $conversation->messages()
                ->where('user_id', '!=', $me->id)
                ->whereNotIn('id', $hidden)
                ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
                ->count();
        });

        // Missed calls not yet seen on the Calls page.
        $calls = Call::where('status', 'missed')
            ->where('caller_id', '!=', $me->id)
            ->whereHas('participants', fn ($p) => $p->where('users.id', $me->id)->whereNull('call_participants.seen_at'))
            ->count();

        // Connection requests waiting for my answer.
        $connections = Connection::where('addressee_id', $me->id)
            ->where('status', 'pending')
            ->count();

        // Unread notification bell count rides along to save a request.
        $notifications = $me->unreadNotifications()->count();

        return response()->json([
            'data' => [
                'messages' => $messages,
                'calls' => $calls,
                'connections' => $connections,
                'notifications' => $notifications,
            ],
        ]);
    }

    /** Attend missed calls (Calls page opened) — clears the calls badge. */
    public function markCallsSeen(Request $request): JsonResponse
    {
        $me = $request->user();

        \Illuminate\Support\Facades\DB::table('call_participants')
            ->where('user_id', $me->id)
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);

        return response()->json(['message' => 'Calls marked seen.']);
    }
}
