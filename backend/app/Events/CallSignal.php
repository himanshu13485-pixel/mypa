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
        /** The name of whoever is SENDING this signal — not the caller's. */
        public ?string $fromName,
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
            /*
             * The sender's own name.
             *
             * This used to read `$this->call->caller->name` — the name of
             * whoever *started* the call, whatever the value of fromUserUuid
             * beside it. In a call of three, every offer and every ICE
             * candidate therefore arrived labelled with the caller's name, and
             * the receiving client names a new peer from exactly this field:
             * everyone in the room ended up wearing one person's name.
             */
            'from_name' => $this->fromName ?? $this->senderName(),
            'signal' => $this->signalType,
            'payload' => $this->payload,
        ];
    }

    /**
     * Fallback for a caller that did not pass a name.
     *
     * Every dispatch site does pass one, so this is a guard rather than a
     * path — but a signal with a missing name is what caused the bug above,
     * and looking it up is cheaper than shipping one again.
     */
    protected function senderName(): ?string
    {
        if ($this->call->relationLoaded('caller') && $this->call->caller?->uuid === $this->fromUserUuid) {
            return $this->call->caller->name;
        }

        return \App\Models\User::where('uuid', $this->fromUserUuid)->value('name');
    }
}
