<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One message, written once, arriving as an ordinary private one.
 *
 * The thing people do instead of this is make a group, send the announcement,
 * and discover they have introduced forty customers to each other — every one
 * of them now holding a list of the others' names, and every "thanks!" going
 * to all of them. The other thing they do is paste the same text into forty
 * chats by hand, which is right but takes forty goes.
 *
 * So: forty real messages in forty real direct conversations. What a recipient
 * gets is not a special kind of message with the disclosure filed off — it IS
 * a private message, in the thread they already have with the sender, and a
 * reply goes back to the sender alone. Nobody is in a room with anybody. The
 * only thing hidden from them is the count, which is the sender's business,
 * and Message::serializeFor is where that is actually enforced.
 *
 * Every door an ordinary message has to walk through is walked through here,
 * one recipient at a time:
 *
 *   - "who may message me", so that this cannot become a way to reach people
 *     who have said connections-only and are not connected;
 *   - a block, which holds here exactly as it holds in a typed message;
 *   - the same notification, so nothing about the arrival is unusual either.
 *
 * That is the difference between this and a bulk sender. Take those checks out
 * and what is left is a tool for putting unsolicited mail in front of people
 * in the most trusted place the app has, which is not what is being built.
 */
class BroadcastController extends Controller
{
    public function store(Request $request, AppIdService $appIds): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'user_uuids' => ['required', 'array', 'min:1', 'max:' . Broadcast::MAX_RECIPIENTS],
            'user_uuids.*' => ['uuid'],
            'body' => ['required', 'string', 'max:10000'],
        ], [
            'user_uuids.max' => 'A broadcast can go to at most ' . Broadcast::MAX_RECIPIENTS . ' people at a time.',
        ]);

        $recipients = User::with('settings')
            ->whereIn('uuid', $data['user_uuids'])
            ->where('id', '!=', $me->id)
            ->get();

        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'None of those people could be found.'], 422);
        }

        /*
         * The row first, the messages after.
         *
         * The copies point at it, so it has to exist before they do; and if
         * every recipient turns out to be refused it is deleted again below
         * rather than left behind as a broadcast that reached nobody.
         */
        $broadcast = Broadcast::create([
            'user_id' => $me->id,
            'body' => $data['body'],
            'recipient_count' => 0,
        ]);

        $sent = 0;
        $refused = [];

        foreach ($recipients as $recipient) {
            if ($reason = $this->refusalFor($me, $recipient, $appIds)) {
                $refused[] = ['uuid' => $recipient->uuid, 'name' => $recipient->name, 'reason' => $reason];

                continue;
            }

            $conversation = Conversation::directBetween($me, $recipient);

            // The block test needs the conversation, so it comes after it —
            // and it is the same call an ordinary message makes.
            if ($conversation->blockBetween($me)) {
                $refused[] = [
                    'uuid' => $recipient->uuid,
                    'name' => $recipient->name,
                    'reason' => 'This message could not be delivered.',
                ];

                continue;
            }

            $message = $conversation->messages()->create([
                'user_id' => $me->id,
                'type' => 'text',
                'body' => $data['body'],
                'broadcast_id' => $broadcast->id,
            ]);

            $conversation->update(['last_message_at' => now()]);
            $conversation->members()->updateExistingPivot($me->id, ['last_read_at' => now()]);

            /*
             * The ordinary live event and the ordinary notification.
             *
             * Not a quieter variant of either. A recipient whose screen
             * behaved differently for this message — arriving without a chime,
             * or without lighting the bell — would have been told it was a
             * broadcast by the only means that matters, which is behaviour.
             */
            broadcast(new MessageSent($message->load(['user', 'conversation'])))->toOthers();
            $this->notify($recipient, $conversation, $message, $me);

            $sent++;
        }

        if ($sent === 0) {
            $broadcast->delete();

            return response()->json([
                'message' => 'None of those people could be messaged.',
                'data' => ['sent' => 0, 'refused' => $refused],
            ], 422);
        }

        $broadcast->update(['recipient_count' => $sent]);

        return response()->json([
            'message' => $sent === 1
                ? 'Sent.'
                : "Sent privately to {$sent} people.",
            'data' => [
                'uuid' => $broadcast->uuid,
                'sent' => $sent,
                'refused' => $refused,
            ],
        ], 201);
    }

    /**
     * Why this person may not be messaged, or null if they may.
     *
     * The same two rules ConversationController::store applies when somebody
     * opens a chat by hand — deliberately identical, because a broadcast that
     * could reach someone a typed message could not would be a way round a
     * privacy setting rather than a convenience.
     */
    protected function refusalFor(User $me, User $recipient, AppIdService $appIds): ?string
    {
        $pref = $recipient->settings?->privacyValue('who_can_message') ?? 'connections';

        if ($pref === 'nobody') {
            return 'This person is not accepting messages.';
        }

        if ($pref === 'connections' && ! $appIds->areConnected($me, $recipient)) {
            return 'You can only message your connections.';
        }

        return null;
    }

    /**
     * The same notification a typed message sends.
     *
     * A near-copy of MessageController::notifyMembers rather than a call to
     * it — that one is protected, works from a conversation's whole member
     * list, and skips people who muted the thread, all of which is right there
     * and none of which is reusable from here without loosening it. The one
     * behaviour worth keeping identical is the wording and the link, and those
     * are what is copied.
     */
    protected function notify(User $recipient, Conversation $conversation, Message $message, User $me): void
    {
        // Somebody who muted this conversation muted it for everything, and a
        // broadcast is not an exception to that.
        $muted = $conversation->members()
            ->where('users.id', $recipient->id)
            ->whereNotNull('conversation_members.muted_at')
            ->exists();

        if ($muted) {
            return;
        }

        $recipient->notify(new SocialNotification(
            'message',
            "{$me->name}: " . str($message->body ?? '')->stripTags()->limit(120)->toString(),
            ['conversation_uuid' => $conversation->uuid, 'message_uuid' => $message->uuid],
            '/messages?conversation=' . $conversation->uuid,
            'message-' . $message->uuid,
        ));
    }
}
