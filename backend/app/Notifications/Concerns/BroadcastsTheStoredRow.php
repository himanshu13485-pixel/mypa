<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Send over the websocket exactly what was written to the database.
 *
 * The broadcast is not a second, richer notification — it is a nudge saying a
 * row now exists, sent so an open tab does not have to wait for its next poll
 * to find out. The bell reloads from the API when it arrives rather than
 * rendering this payload, so the two can never disagree about what a
 * notification says.
 *
 * Sending the same array anyway costs nothing and makes the event readable in
 * a debugger, which is worth more than the handful of bytes.
 *
 * Laravel requires toBroadcast() or toArray() on anything using the broadcast
 * channel and throws at send time if neither exists — a runtime failure, in
 * the middle of whatever request triggered the notification, which is how
 * adding 'broadcast' to seven via() methods turned into 41 failing tests.
 */
trait BroadcastsTheStoredRow
{
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
