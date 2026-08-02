<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageSent;
use App\Events\MessageUpdated;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageController extends Controller
{
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $hidden = MessageDeletion::where('user_id', $me->id)->pluck('message_id');

        $query = $conversation->messages()
            ->withTrashed() // deleted-for-everyone still shows a tombstone
            ->whereNotIn('id', $hidden)
            ->with(['user:id,uuid,name', 'attachments', 'reactions', 'replyTo.user:id,uuid,name'])
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

        $messages = $query->limit(30)->get()->reverse()->values()
            ->map(fn ($m) => $m->serializeFor($me));

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $maxKb = (int) config('mypa.files.max_upload_kb');

        $data = $request->validate([
            'body' => ['required_without:attachments', 'nullable', 'string', 'max:10000'],
            'type' => ['sometimes', 'in:text,image,file,audio,voice,video'],
            'reply_to' => ['nullable', 'uuid'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', "max:{$maxKb}"],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:36000'],
        ]);

        $replyTo = null;
        if (! empty($data['reply_to'])) {
            $replyTo = $conversation->messages()->where('uuid', $data['reply_to'])->first();
        }

        foreach ($request->file('attachments', []) as $upload) {
            \App\Support\UploadGuard::assertSafe($upload);
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

        return response()->json([
            'message' => 'Sent.',
            'data' => $message->load(['user:id,uuid,name', 'attachments', 'reactions', 'replyTo.user:id,uuid,name'])
                ->serializeFor($me),
        ], 201);
    }

    public function update(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);
        abort_unless($message->conversation_id === $conversation->id, 404);
        abort_unless($message->user_id === $me->id, 403, 'You can only edit your own messages.');
        abort_if($message->type !== 'text', 422, 'Only text messages can be edited.');

        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);

        $message->update(['body' => $data['body'], 'edited_at' => now()]);

        broadcast(new MessageUpdated($conversation, $message->uuid, 'edited'))->toOthers();

        return response()->json([
            'message' => 'Message edited.',
            'data' => $message->fresh()->load(['user:id,uuid,name', 'attachments', 'reactions'])->serializeFor($me),
        ]);
    }

    public function destroy(Request $request, Conversation $conversation, string $messageUuid): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);

        $message = $conversation->messages()->withTrashed()->where('uuid', $messageUuid)->firstOrFail();
        $scope = $request->query('for', 'me');

        if ($scope === 'everyone') {
            abort_unless($message->user_id === $me->id, 403, 'You can only delete your own messages for everyone.');
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
            'data' => $message->fresh()->load('reactions')->serializeFor($me),
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
