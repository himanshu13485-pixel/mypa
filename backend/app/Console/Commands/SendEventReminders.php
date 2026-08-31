<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\SocialNotification;
use Illuminate\Console\Command;

/**
 * Tell people about a calendar entry shortly before it starts.
 *
 * Modelled on SendMeetingReminders, and for the same reason: starts_at was
 * written, indexed, and read by nobody. An appointment booked for Tuesday at
 * nine passed in silence, which made the calendar the one part of the app you
 * still had to remember on your own.
 *
 * The lead time is longer than a meeting's ten minutes because these are not
 * all things you attend from a chair — an appointment usually has a journey
 * in front of it, and thirty minutes is the difference between a reminder and
 * an apology.
 */
class SendEventReminders extends Command
{
    protected $signature = 'mypa:send-event-reminders';

    protected $description = 'Notify the owner and invitees shortly before a calendar event starts';

    /** How long before the start time to speak up. */
    public const LEAD_MINUTES = 30;

    public function handle(): int
    {
        $sent = 0;
        $now = now();

        Event::with(['user.settings', 'participants.settings'])
            ->whereNull('reminded_at')
            /*
             * All-day entries are excluded on purpose.
             *
             * Their starts_at is midnight, so a lead time would fire this at
             * half past eleven the night before — waking somebody to tell them
             * it is nearly a birthday. A day-level reminder is a different
             * feature with a different sensible hour, and pretending an
             * all-day event is a timed one gets it wrong in the most annoying
             * possible way.
             */
            ->where('all_day', false)
            // Inside the window, and not so far past the start that the
            // reminder has become an announcement of something already over.
            ->whereBetween('starts_at', [$now->copy()->subMinutes(5), $now->copy()->addMinutes(self::LEAD_MINUTES)])
            ->chunkById(100, function ($events) use (&$sent, $now) {
                foreach ($events as $event) {
                    $minutes = max(0, (int) round($now->diffInMinutes($event->starts_at, false)));
                    $message = $minutes <= 0
                        ? "{$event->title} is starting now."
                        : "{$event->title} starts in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.';

                    if ($event->location) {
                        $message .= " At {$event->location}.";
                    }

                    // The owner plus whoever was invited. Declining is an
                    // answer, and the answer was no — reminding them anyway
                    // would be the app arguing with them.
                    $people = $event->participants
                        ->reject(fn ($person) => $person->pivot?->status === 'declined')
                        ->push($event->user)
                        ->filter()
                        ->unique('id');

                    foreach ($people as $person) {
                        $person->notify(new SocialNotification(
                            'event_reminder',
                            $message,
                            ['event_uuid' => $event->uuid, 'title' => $event->title],
                            '/calendar?open=' . $event->uuid,
                        ));
                        $sent++;
                    }

                    $event->updateQuietly(['reminded_at' => $now]);
                }
            });

        $this->info("Sent {$sent} event reminder(s).");

        return self::SUCCESS;
    }
}
