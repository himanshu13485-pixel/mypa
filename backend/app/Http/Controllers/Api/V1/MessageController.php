<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageDeletion;
use App\Models\MessageStar;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    /** How many messages one conversation may hold up at once. */
    private const MAX_PINS = 5;

    /**
     * How many messages one forward may carry.
     *
     * A selection is a handful of things worth passing on, not a thread
     * export — and every one is copied on disk into every destination, so the
     * cost is messages times destinations.
     */
    public const MAX_FORWARD_AT_ONCE = 30;

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $hidden = MessageDeletion::where('user_id', $me->id)->pluck('message_id');

        $query = $conversation->messages()
            ->withTrashed() // deleted-for-everyone still shows a tombstone
            ->whereNotIn('id', $hidden)
            /*
             * conversation.group is here for canBeUnsentBy, which asks whether
             * the reader runs the group. Without it that is a pair of queries
             * per message on every thread the list ever draws.
             */
            ->with(['user:id,uuid,name', 'attachments', 'reactions', 'stars', 'replyTo.user:id,uuid,name', 'broadcast:id,recipient_count', 'conversation.group'])
            ->orderByDesc('id');

        if ($q = $request->query('q')) {
            $query->whereNull('deleted_at')->where('body', 'like', "%{$q}%");
        }
        if ($before = $request->query('before')) {
            $anchor = Message::where('uuid', $before)->first();
            if ($anchor) {
                $query->where('id', '<', $anchor->id);
            }
        }

        // Read state is one value for the whole conversation rather than a
        // lookup per message: how far the others have read to.
        $othersReadAt = $conversation->othersReadAt($me);

        $messages = $query->limit(30)->get()->reverse()->values()
            ->map(fn ($m) => $m->serializeFor($me, $othersReadAt));

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        // A block has to hold for the life of the conversation, not just at
        // the moment it was opened. Someone who blocked you is not told that
        // their own block is the reason — that is their business, not yours.
        if ($block = $conversation->blockBetween($me)) {
            abort(403, $block === 'mine'
                ? 'You have blocked this person. Unblock them to send messages.'
                : 'This message could not be delivered.');
        }

        // An announcement group: everybody reads it, the people running it
        // write. Checked here rather than hidden in the UI, because a
        // closed group that only looks closed is not closed.
        if ($conversation->group?->only_admins_post && ! $conversation->group->canManage($me)) {
            abort(403, 'Only the admins of this group can post here.');
        }

        // Chat has its own ceiling, lower than Drive's: see config/mypa.php.
        $maxKb = (int) config('mypa.files.max_chat_upload_kb');
        $maxMb = (int) round($maxKb / 1024);

        $data = $request->validate([
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:10000'],
            'type' => ['sometimes', 'in:text,image,file,audio,voice,video'],
            'reply_to' => ['nullable', 'uuid'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            /*
             * The ceiling said in megabytes, because "max:25600" is what the
             * rule reads and "25 MB" is what a person needs to be told.
             */
            'attachments.*' => ['file', "max:{$maxKb}"],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:36000'],
        ], [
            'attachments.*.max' => "Each file has to be {$maxMb} MB or smaller.",
            'attachments.max' => 'Five files at a time is the limit.',
        ]);

        $replyTo = null;
        if (! empty($data['reply_to'])) {
            $replyTo = $conversation->messages()->where('uuid', $data['reply_to'])->first();
        }

        $incoming = 0;
        foreach ($request->file('attachments', []) as $upload) {
            \App\Support\UploadGuard::assertSafe($upload);
            $incoming += (int) $upload->getSize();
        }

        // Attachments land on the same disk as the user's Drive and now count
        // against the same quota; without this, chat was an unmetered way to
        // fill the server.
        if ($incoming > 0 && ! app(\App\Services\SubscriptionEntitlementService::class)->canUploadBytes($me, $incoming)) {
            return response()->json([
                'message' => 'This attachment would take you over your storage limit. Free up space or upgrade your plan.',
            ], 422);
        }

        $message = $conversation->messages()->create([
            'user_id' => $me->id,
            'type' => $data['type'] ?? 'text',
            'body' => $data['body'] ?? null,
            'reply_to_id' => $replyTo?->id,
        ]);

        foreach ($request->file('attachments', []) as $upload) {
            $path = $upload->store('chat-files/' . $conversation->id, 'local');
            $message->attachments()->create([
                'name' => $upload->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
                'duration_seconds' => $data['duration_seconds'] ?? null,
            ]);
        }

        $conversation->update(['last_message_at' => now()]);
        $conversation->members()->updateExistingPivot($me->id, ['last_read_at' => now()]);

        broadcast(new MessageSent($message->load(['user', 'conversation'])))->toOthers();

        $this->notifyMembers($conversation, $message, $me);

        return response()->json([
            'message' => 'Sent.',
            'data' => $message->load(['user:id,uuid,name', 'attachments', 'reactions', 'stars', 'replyTo.user:id,uuid,name'])
                ->serializeFor($me),
        ], 201);
    }

    /**
     * Tell everyone else in the conversation, one alert per message.
     *
     * The broadcast above only lands in an app that is already open on this
     * thread. Until now a closed tab or a pocketed phone got nothing at all,
     * so chat was the one part of the app you had to keep watching to use.
     *
     * Deliberately per message rather than a digest per conversation: a
     * collapsed "3 new messages" hides who is waiting on what, and a chat is
     * expected to behave like a chat. That is also why each carries its own
     * push tag — devices replace a notification that reuses a tag, so without
     * it the fifth message would silently overwrite the fourth.
     *
     * Muting stays honoured. That is a decision the member made about this
     * thread; it is not the same thing as never having been asked.
     */
    protected function notifyMembers(Conversation $conversation, Message $message, User $me): void
    {
        $recipients = $conversation->members()
            ->where('users.id', '!=', $me->id)
            ->whereNull('conversation_members.muted_at')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        // A group says which room it came from; a direct chat is just the
        // sender, because naming the conversation would only repeat them.
        $room = $conversation->name ?? $conversation->group?->name;
        $where = $conversation->type === 'direct' || ! $room ? '' : " in {$room}";
        $preview = "{$me->name}{$where}: " . $this->previewOf($message);

        foreach ($recipients as $member) {
            $member->notify(new \App\Notifications\SocialNotification(
                'message',
                $preview,
                ['conversation_uuid' => $conversation->uuid, 'message_uuid' => $message->uuid],
                '/messages?conversation=' . $conversation->uuid,
                'message-' . $message->uuid,
            ));
        }
    }

    /** What the message looks like in one line, when there is no room for it. */
    protected function previewOf(Message $message): string
    {
        if (filled($message->body)) {
            return str($message->body)->stripTags()->limit(120)->toString();
        }

        return match ($message->type) {
            'image' => 'sent a photo',
            'video' => 'sent a video',
            'audio', 'voice' => 'sent a voice message',
            'file' => 'sent a file',
            default => 'sent a message',
        };
    }

    /**
     * The same message again, in somebody else's thread.
     *
     * Copying text out and pasting it into three chats is how this was done,
     * which loses the attachments and takes three trips. The forward keeps
     * both and takes one.
     *
     * What it does not do is carry the reply it was part of, or who sent it
     * to you. A quoted reply means nothing in a conversation that never saw
     * the message being answered, and who forwarded what to you is not the
     * next reader's business.
     */
    public function forward(Request $request, Conversation $conversation, string $messageUuid): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $original = $conversation->messages()->with('attachments')->where('uuid', $messageUuid)->first();
        abort_unless($original, 404);
        abort_if($original->trashed(), 404, 'That message is no longer here.');

        $data = $request->validate([
            'conversation_uuids' => ['required', 'array', 'min:1', 'max:10'],
            'conversation_uuids.*' => ['uuid'],
        ]);

        [$sent, $refused] = $this->deliverForwards($me, $data['conversation_uuids'], collect([$original]));

        abort_if($sent === [] && $refused === [], 422, 'None of those conversations are yours.');

        return response()->json([
            'message' => count($sent) === 1
                ? 'Forwarded.'
                : 'Forwarded to ' . count($sent) . ' conversations.',
            'data' => ['sent' => $sent, 'refused' => $refused],
        ], 201);
    }

    /**
     * Several messages at once, the way a selection is forwarded.
     *
     * Its own route rather than the browser calling the single forward in a
     * loop: a loop is one request per message per destination, it can half
     * fail with nothing sensible to tell anybody, and — the part that
     * actually shows — the copies arrive in whatever order the responses
     * happen to come back in.
     *
     * Here they are re-read in the order they were written and delivered in
     * that order, so an exchange forwarded on reads on the other side the way
     * it read on this one.
     */
    public function forwardMany(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $data = $request->validate([
            'message_uuids' => ['required', 'array', 'min:1', 'max:' . self::MAX_FORWARD_AT_ONCE],
            'message_uuids.*' => ['uuid'],
            'conversation_uuids' => ['required', 'array', 'min:1', 'max:10'],
            'conversation_uuids.*' => ['uuid'],
        ], [
            'message_uuids.max' => self::MAX_FORWARD_AT_ONCE . ' messages at a time is the limit.',
        ]);

        /*
         * Ordered by id, not by the order the uuids arrived in. The client
         * sends a set — what was ticked — and the thread's own order is the
         * only one that means anything on the other side.
         */
        $originals = $conversation->messages()
            ->with('attachments')
            ->whereIn('uuid', $data['message_uuids'])
            ->orderBy('id')
            ->get();

        abort_if($originals->isEmpty(), 404, 'Those messages are no longer here.');

        [$sent, $refused] = $this->deliverForwards($me, $data['conversation_uuids'], $originals);

        abort_if($sent === [] && $refused === [], 422, 'None of those conversations are yours.');

        $count = $originals->count();
        $noun = $count === 1 ? 'message' : 'messages';

        return response()->json([
            'message' => count($sent) === 1
                ? "Forwarded {$count} {$noun}."
                : "Forwarded {$count} {$noun} to " . count($sent) . ' conversations.',
            'data' => ['sent' => $sent, 'refused' => $refused, 'messages' => $count],
        ], 201);
    }

    /**
     * Put every one of $originals into every one of $conversationUuids.
     *
     * Shared by the single forward and the many, because they differ only in
     * how many messages they carry — and a second copy of the attachment
     * duplication, the block check and the notification is a second place for
     * them to drift apart.
     *
     * @param  \Illuminate\Support\Collection<int, Message>  $originals
     * @return array{0: list<string>, 1: list<string>}  uuids sent to, and refused
     */
    protected function deliverForwards(User $me, array $conversationUuids, $originals): array
    {
        /*
         * Only threads this person is actually in.
         *
         * Filtered rather than refused: a uuid they are not a member of is
         * either a stale list or somebody trying their luck, and neither is
         * worth failing the other nine destinations over.
         */
        $targets = Conversation::whereIn('uuid', $conversationUuids)
            ->get()
            ->filter(fn (Conversation $c) => $c->hasMember($me));

        $sent = [];
        $refused = [];

        foreach ($targets as $target) {
            // The same doors that guard an ordinary message guard this one.
            if ($target->blockBetween($me)) {
                $refused[] = $target->uuid;

                continue;
            }

            if ($target->group?->only_admins_post && ! $target->group->canManage($me)) {
                $refused[] = $target->uuid;

                continue;
            }

            $last = null;
            foreach ($originals as $original) {
                if ($original->trashed()) {
                    continue;
                }
                $last = $this->copyInto($target, $original, $me);
            }

            if (! $last) {
                continue;
            }

            $target->update(['last_message_at' => now()]);
            $target->members()->updateExistingPivot($me->id, ['last_read_at' => now()]);

            /*
             * One notification for the batch, not one per message.
             *
             * Forwarding a ten-message exchange should not put ten lines on
             * somebody's lock screen. The last copy carries the preview,
             * which is what typing them would have left too.
             */
            $this->notifyMembers($target, $last, $me);

            $sent[] = $target->uuid;
        }

        return [$sent, $refused];
    }

    /** One message, copied into one conversation. Returns the copy. */
    protected function copyInto(Conversation $target, Message $original, User $me): Message
    {
        $copy = $target->messages()->create([
            'user_id' => $me->id,
            'type' => $original->type,
            'body' => $original->body,
            'is_forwarded' => true,
        ]);

        /*
         * Attachments are copied on disk, not pointed at.
         *
         * Two rows sharing one file means deleting either message takes
         * the other one's attachment with it. The copy costs storage,
         * which is why it is charged to the same quota an upload is.
         */
        foreach ($original->attachments as $attachment) {
            $path = 'chat-files/' . $target->id . '/' . \Illuminate\Support\Str::random(40);

            if (! \Illuminate\Support\Facades\Storage::disk('local')->exists($attachment->path)) {
                continue;
            }

            \Illuminate\Support\Facades\Storage::disk('local')
                ->copy($attachment->path, $path);

            $copy->attachments()->create([
                'name' => $attachment->name,
                'path' => $path,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'duration_seconds' => $attachment->duration_seconds,
            ]);
        }

        broadcast(new MessageSent($copy->load(['user', 'conversation'])))->toOthers();

        return $copy;
    }

    /**
     * Keeping a message, privately.
     *
     * A toggle rather than two endpoints: the button is one button, and a
     * client that has lost track of the current state should not be able to
     * create two stars or fail to remove one.
     */
    public function star(Request $request, Conversation $conversation, string $messageUuid): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $message = $conversation->messages()->where('uuid', $messageUuid)->first();
        abort_unless($message, 404);

        $existing = MessageStar::where('message_id', $message->id)->where('user_id', $me->id)->first();

        if ($existing) {
            $existing->delete();

            return response()->json(['message' => 'Removed from starred.', 'data' => ['starred' => false]]);
        }

        MessageStar::create(['message_id' => $message->id, 'user_id' => $me->id]);

        return response()->json(['message' => 'Starred.', 'data' => ['starred' => true]]);
    }

    /**
     * Holding a message up for everyone in the conversation.
     *
     * Whoever may post may pin: a thread nobody is allowed to organise fills
     * up and stays that way. In an announcement group that is the admins,
     * which is the same rule that governs writing there in the first place.
     */
    public function pin(Request $request, Conversation $conversation, string $messageUuid): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        if ($conversation->group?->only_admins_post && ! $conversation->group->canManage($me)) {
            abort(403, 'Only the admins of this group can pin messages here.');
        }

        $message = $conversation->messages()->where('uuid', $messageUuid)->first();
        abort_unless($message, 404);
        abort_if($message->trashed(), 404, 'That message is no longer here.');

        if ($message->pinned_at) {
            $message->update(['pinned_at' => null, 'pinned_by_id' => null]);

            return response()->json(['message' => 'Unpinned.', 'data' => ['pinned' => false]]);
        }

        /*
         * A cap, because a pinned list of forty is an unpinned list.
         *
         * The oldest pin makes way rather than the request being refused:
         * whoever is pinning has decided this one matters now, and telling
         * them to go and find the stalest pin first is work the app can do.
         */
        $pinned = $conversation->messages()->whereNotNull('pinned_at')
            ->orderBy('pinned_at')->get();

        if ($pinned->count() >= self::MAX_PINS) {
            $pinned->first()->update(['pinned_at' => null, 'pinned_by_id' => null]);
        }

        $message->update(['pinned_at' => now(), 'pinned_by_id' => $me->id]);

        return response()->json(['message' => 'Pinned.', 'data' => ['pinned' => true]]);
    }

    /** What is currently held up in this conversation. */
    public function pinned(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $messages = $conversation->messages()
            ->whereNotNull('pinned_at')
            ->with(['user:id,uuid,name', 'attachments', 'reactions', 'stars', 'pinnedBy:id,uuid,name'])
            ->orderByDesc('pinned_at')
            ->get();

        return response()->json(['data' => $messages->map(fn (Message $m) => $m->serializeFor($me))->values()]);
    }

    public function update(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);
        abort_unless($message->conversation_id === $conversation->id, 404);
        abort_unless($message->user_id === $me->id, 403, 'You can only edit your own messages.');
        abort_if($message->type !== 'text', 422, 'Only text messages can be edited.');

        /*
         * An hour to fix a typo, and then it stands.
         *
         * Editing was unbounded, which meant a message somebody answered a
         * week ago could be quietly rewritten under their reply — the reply
         * stays, the thing it replied to does not. A window short enough that
         * the conversation has not moved on is the whole point; the mark that
         * says it was edited is the other half, and the reader keeps that
         * either way.
         */
        abort_if(
            $message->created_at->lt(now()->subMinutes(Message::EDIT_WINDOW_MINUTES)),
            422,
            'Messages can only be edited for ' . Message::EDIT_WINDOW_MINUTES . ' minutes after sending.',
        );

        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $message->update(['body' => $data['body'], 'edited_at' => now()]);

        broadcast(new MessageUpdated($conversation, $message->uuid, 'edited'))->toOthers();

        return response()->json([
            'message' => 'Message edited.',
            'data' => $message->fresh()->load(['user:id,uuid,name', 'attachments', 'reactions', 'stars'])->serializeFor($me),
        ]);
    }

    public function destroy(Request $request, Conversation $conversation, string $messageUuid): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $message = $conversation->messages()->withTrashed()->where('uuid', $messageUuid)->firstOrFail();
        $scope = $request->query('for', 'me');

        if ($scope === 'everyone') {
            /*
             * Your own message, or anything at all in a group you run.
             *
             * Somebody has to be able to take down what should not have
             * been said, and in a group of two hundred that person is not
             * going to be the author. Outside a group it stays your own
             * messages only: nobody moderates a private conversation.
             */
            $moderates = $conversation->group?->canManage($me) ?? false;
            abort_unless($message->user_id === $me->id || $moderates, 403,
                'You can only delete your own messages for everyone.');

            /*
             * And, for the author, only for a while.
             *
             * Six hours is long enough for the case this exists for — the
             * message sent to the wrong chat and noticed after lunch — and
             * short enough that a conversation somebody has already answered,
             * quoted or acted on cannot be hollowed out underneath them a week
             * later. "Delete for me" has no clock and is still there
             * afterwards, which is the right answer to wanting an old message
             * off your own screen.
             *
             * The clock does not bind whoever runs the group. Abuse is rarely
             * reported the same afternoon, and a moderator who cannot act on
             * it is not a moderator.
             */
            abort_if(
                ! $moderates && $message->created_at->lt(now()->subHours(Message::DELETE_WINDOW_HOURS)),
                422,
                'Messages can only be deleted for everyone within '
                    . Message::DELETE_WINDOW_HOURS . ' hours of sending. You can still delete it for yourself.',
            );
            if (! $message->trashed()) {
                // Remove stored attachment data as well.
                foreach ($message->attachments as $attachment) {
                    Storage::disk('local')->delete($attachment->path);
                }
                $message->attachments()->delete();
                $message->update(['body' => null]);
                $message->delete();
            }
            broadcast(new MessageUpdated($conversation, $message->uuid, 'deleted'))->toOthers();

            return response()->json(['message' => 'Message deleted for everyone.']);
        }

        MessageDeletion::firstOrCreate(['message_id' => $message->id, 'user_id' => $me->id]);

        return response()->json(['message' => 'Message deleted for you.']);
    }

    public function react(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);
        abort_unless($message->conversation_id === $conversation->id, 404);

        $data = $request->validate(['emoji' => ['required', 'string', 'max:16']]);

        $existing = $message->reactions()
            ->where('user_id', $me->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $message->reactions()->create(['user_id' => $me->id, 'emoji' => $data['emoji']]);
        }

        broadcast(new MessageUpdated($conversation, $message->uuid, 'reacted'))->toOthers();

        return response()->json([
            'message' => $existing ? 'Reaction removed.' : 'Reaction added.',
            'data' => $message->fresh()->load(['reactions', 'stars'])->serializeFor($me),
        ]);
    }

    public function downloadAttachment(Request $request, Conversation $conversation, int $attachmentId): StreamedResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $attachment = MessageAttachment::whereHas(
            'message',
            fn ($m) => $m->where('conversation_id', $conversation->id)->whereNull('deleted_at'),
        )->findOrFail($attachmentId);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
