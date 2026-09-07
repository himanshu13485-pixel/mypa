<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * "Ring this number on my phone."
 *
 * Sent by somebody at a laptop to their own phone, and to nobody else's — the
 * whole event exists so a salesperson can click a lead's number on the screen
 * they are working at and have the call go out on the SIM they already pay
 * for. It carries no authority of its own: the phone opens its dialler with
 * the number entered, and a person still presses the green button.
 *
 * ShouldBroadcastNow because a dial request is only interesting for the few
 * seconds after the click. Queued behind a slow job it would arrive after the
 * person had given up and typed the number in by hand, which is worse than
 * not arriving at all — they would then be dialling twice.
 *
 * The websocket is the fast path, taken whenever the app is open on the
 * phone. FCM is the fallback for a backgrounded app, sent alongside this by
 * the controller, and lands as a notification to tap.
 */
class DialRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userUuid,
        public string $number,
        /** Who is being rung, for the phone to show while it dials. */
        public ?string $label = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->userUuid)];
    }

    public function broadcastAs(): string
    {
        return 'dial.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'number' => $this->number,
            'label' => $this->label,
        ];
    }
}
