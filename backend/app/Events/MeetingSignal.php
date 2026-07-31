<?php

namespace App\Events;

use App\Models\Meeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Meeting mesh signalling (join / leave / end / offer / answer / ice),
 * relayed to one participant's personal channel. Never queued.
 */
class MeetingSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Meeting $meeting,
        public string $fromUserUuid,
        public string $fromName,
        public string $toUserUuid,
        public string $signalType, // join | leave | end | offer | answer | ice
        public array $payload = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->toUserUuid)];
    }

    public function broadcastAs(): string
    {
        return 'meeting.signal';
    }

    public function broadcastWith(): array
    {
        return [
            'meeting_code' => $this->meeting->code,
            'meeting_type' => $this->meeting->type,
            'from_uuid' => $this->fromUserUuid,
            'from_name' => $this->fromName,
            'signal' => $this->signalType,
            'payload' => $this->payload,
        ];
    }
}
