<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.' . $this->message->conversation->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_uuid' => $this->message->conversation->uuid,
            'message_uuid' => $this->message->uuid,
            'sender_uuid' => $this->message->user->uuid,
            'sender_name' => $this->message->user->name,
            'type' => $this->message->type,
            /*
             * A locked group says a message arrived and not a word of it.
             *
             * Everyone in the room is on this channel whether they have given
             * the password or not, so sending the text here would hand it to
             * exactly the people the lock is keeping it from. They fetch it
             * through the guarded route once they are in.
             */
            'preview' => filled($this->message->conversation->group?->chat_password_hash)
                ? null
                : str($this->message->body ?? '')->limit(80)->toString(),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
