<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody arrived, stepped away, or left.
 *
 * The whole point of this event is that it is not a poll. A dot that only
 * changes when the page is next refreshed is a dot nobody believes, and
 * shortening the poll to fix that is paying for the answer once a minute for
 * everybody, for ever, to catch a handful of moments a day.
 *
 * It rides the private channel each viewer is already subscribed to for calls
 * and notifications — `user.{uuid}` — so nothing new has to be authorised and
 * a page showing thirty names still has one socket. Every recipient is a
 * separate channel on one event, which the broadcaster publishes in a single
 * call, so the fan-out costs one request however many people are watching.
 *
 * Sent only on a change of state, never on a heartbeat: beating is constant
 * and changing is rare, and only one of the two is news.
 *
 * ShouldBroadcastNow because presence is only interesting while it is true,
 * and Realtime::send() carries it, so a websocket server that is down cannot
 * turn "I am here" into a failed request.
 */
class PresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $userUuid  whose presence changed
     * @param  string  $state  online, away or offline
     * @param  list<string>  $audience  uuids of the people who may be told
     */
    public function __construct(
        public string $userUuid,
        public string $state,
        public array $audience,
    ) {
    }

    public function broadcastOn(): array
    {
        return array_map(
            fn (string $uuid) => new PrivateChannel('user.' . $uuid),
            $this->audience,
        );
    }

    public function broadcastAs(): string
    {
        return 'presence.changed';
    }

    /**
     * The state and nothing else.
     *
     * Deliberately not the timestamp behind it: "away" is a word, but
     * last_active_at handed out on every change is a log of exactly when
     * somebody opens and closes the app, which is a great deal more than
     * anyone agreed to show by leaving their status visible.
     */
    public function broadcastWith(): array
    {
        return [
            'user_uuid' => $this->userUuid,
            'state' => $this->state,
        ];
    }
}
