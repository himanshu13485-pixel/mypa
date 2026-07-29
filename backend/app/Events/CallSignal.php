<?php

namespace App\Events;

use App\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * WebRTC signalling relay (offer / answer / ICE candidates) and call lifecycle
 * (ringing / accepted / declined / ended). Broadcast immediately — never queued —
 * because signalling is latency-sensitive.
 */
class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Call $call,
        public string $fromUserUuid,
        public string $toUserUuid,
        public string $signalType, // ring | accept | decline | end | offer | answer | ice
        public array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        // Signals go to the target user's personal channel.
        return [new PrivateChannel('user.' . $this->toUserUuid)];
    }

    public function broadcastAs(): string
    {
        return 'call.signal';
    }

    public function broadcastWith(): array
    {
        return [
            'call_uuid' => $this->call->uuid,
            'conversation_uuid' => $this->call->conversation->uuid,
            'call_type' => $this->call->type,
            'from_uuid' => $this->fromUserUuid,
            'from_name' => $this->call->relationLoaded('caller') ? $this->call->caller->name : null,
            'signal' => $this->signalType,
            'payload' => $this->payload,
        ];
    }
}
