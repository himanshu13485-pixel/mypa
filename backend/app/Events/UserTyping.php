<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "X is typing…" — sent as it happens and never stored.
 *
 * Broadcast immediately rather than queued: a typing indicator that arrives
 * after a queue worker gets round to it is worse than none at all, and there
 * is nothing to persist or retry.
 */
class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public User $user,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.' . $this->conversation->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_uuid' => $this->conversation->uuid,
            'user_uuid' => $this->user->uuid,
            'name' => $this->user->name,
        ];
    }
}
