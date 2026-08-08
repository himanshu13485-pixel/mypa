<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Notifications\SocialNotification;
use Illuminate\Console\Command;

/**
 * Tell people about a meeting shortly before it starts.
 *
 * Nothing read `meetings.scheduled_at` at all: a meeting could be booked for
 * Monday at ten and, when Monday came, nobody was told. Every other dated
 * thing in the app — tasks, bills, project entries — has had a reminder since
 * the beginning; meetings were simply missed.
 */
class SendMeetingReminders extends Command
{
    protected $signature = 'mypa:send-meeting-reminders';

    protected $description = 'Notify the host and invitees shortly before a scheduled meeting starts';

    /** How long before the start time to speak up. */
    public const LEAD_MINUTES = 10;

    public function handle(): int
    {
        $sent = 0;
        $now = now();

        Meeting::with(['host.settings', 'participants.settings'])
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->whereNull('reminded_at')
            // Inside the window, and not so long past the start that the
            // reminder has become an announcement of something already over.
            ->whereBetween('scheduled_at', [$now->copy()->subMinutes(5), $now->copy()->addMinutes(self::LEAD_MINUTES)])
            ->chunkById(100, function ($meetings) use (&$sent, $now) {
                foreach ($meetings as $meeting) {
                    $when = $meeting->scheduled_at;
                    $minutes = max(0, (int) round($now->diffInMinutes($when, false)));
                    $title = $meeting->title ?: 'Your meeting';
                    $message = $minutes <= 0
                        ? "{$title} is starting now."
                        : "{$title} starts in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.';

                    /*
                     * The host plus anyone already on the participant list —
                     * people invited to a scheduled meeting are attached when
                     * it is created, so this is the invitee list. Whoever
                     * simply has the link is not known to us and cannot be
                     * told; that is the nature of a link.
                     */
                    $people = $meeting->participants
                        ->push($meeting->host)
                        ->filter()
                        ->unique('id');

                    foreach ($people as $person) {
                        $person->notify(new SocialNotification(
                            'meeting_soon',
                            $message,
                            ['meeting_code' => $meeting->code, 'title' => $title],
                            "/meetings/room/{$meeting->code}",
                        ));
                        $sent++;
                    }

                    $meeting->updateQuietly(['reminded_at' => $now]);
                }
            });

        $this->info("Sent {$sent} meeting reminder(s).");

        return self::SUCCESS;
    }
}
