<?php

namespace App\Services;

use App\Mail\BookingUpdate;
use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\Event;
use App\Models\Meeting;
use App\Notifications\SocialNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Turning an agreed moment into the things that make it real.
 *
 * A booking on its own is a row nobody would ever see. What people expect is
 * three things at once: a room to meet in, an entry on the host's calendar,
 * and both sides told. This puts them in one transaction so a booking can
 * never exist without its meeting, or a meeting without its booking.
 *
 * The email and the notification are sent after the transaction commits, not
 * inside it. Sending inside means a mail failure rolls back a booking the
 * guest has already been shown as confirmed, and a retry then double-books.
 */
class BookingService
{
    public function __construct(private BookingAvailability $availability)
    {
    }

    /**
     * The room's password, which is also what makes guest joining possible.
     *
     * A meeting with no password refuses guests outright — there would be
     * nothing to check them against — so a booked meeting must always have
     * one, or the person who booked it could not get in. Generated rather than
     * chosen, from an alphabet with no O/0 or I/1 in it, because this gets
     * read off a screen and typed on a phone.
     */
    private function passcode(): string
    {
        return substr(str_shuffle(str_repeat('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 2)), 0, 6);
    }

    /**
     * Book a slot.
     *
     * The slot is re-checked here rather than trusted from the request: the
     * list the guest is looking at was computed when their page loaded, and
     * somebody else may have taken it since. Returns null if it has gone, so
     * the caller can say so rather than quietly double-booking.
     */
    public function book(BookingPage $page, CarbonImmutable $start, array $guest): ?Booking
    {
        if (! $this->availability->isBookable($page, $start)) {
            return null;
        }

        $end = $start->addMinutes($page->duration_minutes);
        $host = $page->user;
        $title = $page->title ?: "Meeting with {$guest['name']}";

        $booking = DB::transaction(function () use ($page, $host, $start, $end, $guest, $title) {
            /*
             * Where this booking is met.
             *
             * A host who runs on Google Meet has given us a link, and standing
             * up a Netvork room beside it would only be a second door nobody
             * opens — the guest gets one address, the host's own. Netvork
             * remains the default, and the room is made the moment it is
             * needed rather than kept waiting for one.
             */
            $external = $page->meeting_provider !== 'netvork'
                ? trim((string) $page->external_meeting_url)
                : '';

            $meeting = $external === '' ? Meeting::create([
                'host_id' => $host->id,
                'code' => Meeting::generateCode(),
                'title' => $title,
                'type' => 'video',
                'requires_approval' => false,
                'passcode' => $this->passcode(),
                'scheduled_at' => $start,
                'status' => 'scheduled',
            ]) : null;

            $link = $meeting
                ? rtrim((string) config('mypa.frontend_url'), '/') . '/meetings/room/' . $meeting->code
                : $external;

            /*
             * The calendar entry, so the booking appears where the host looks.
             *
             * The calendar page reads events and knows nothing about meetings,
             * so without this a booked meeting would be invisible on the one
             * screen whose entire job is showing what is coming up.
             */
            $event = Event::create([
                'user_id' => $host->id,
                'title' => $title,
                'description' => trim(($guest['note'] ?? '') . "\n\nBooked by {$guest['name']} <{$guest['email']}>"),
                'type' => 'meeting',
                'starts_at' => $start,
                'ends_at' => $end,
                'all_day' => false,
                'meeting_link' => $link,
            ]);

            return Booking::create([
                'booking_page_id' => $page->id,
                'host_id' => $host->id,
                'meeting_id' => $meeting?->id,
                'meeting_url' => $external ?: null,
                'event_id' => $event->id,
                'name' => $guest['name'],
                'email' => $guest['email'],
                'note' => $guest['note'] ?? null,
                'guest_timezone' => $guest['timezone'] ?? 'UTC',
                'starts_at' => $start,
                'ends_at' => $end,
                'manage_token' => Booking::newManageToken(),
                'status' => 'confirmed',
            ]);
        });

        $this->tell($booking->load(['host', 'meeting', 'page']), 'confirmed');

        return $booking;
    }

    /** Called off, by whichever side did it. */
    public function cancel(Booking $booking, string $by): void
    {
        if ($booking->status === 'cancelled') {
            return;
        }

        DB::transaction(function () use ($booking, $by) {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $by,
            ]);

            // The room and the diary entry go; the booking row stays. "This was
            // booked and then called off" is worth being able to see, and the
            // slot is freed by the status rather than by the row vanishing.
            $booking->meeting?->update(['status' => 'ended', 'ended_at' => now()]);
            $booking->event?->delete();
        });

        $this->tell($booking->fresh()->load(['host', 'meeting', 'page']), 'cancelled', $by);
    }

    /**
     * Moved, rather than cancelled and rebooked.
     *
     * The same row, meeting and calendar entry are carried to the new time, so
     * the link already in the guest's email keeps working and the manage token
     * stays valid. Cancelling and re-creating would invalidate both and send
     * somebody a second email contradicting the first.
     */
    public function reschedule(Booking $booking, CarbonImmutable $start): ?Booking
    {
        $page = $booking->page;
        if (! $page || $booking->status === 'cancelled') {
            return null;
        }

        /*
         * Its own slot does not count as a clash.
         *
         * The booking being moved sits on the host's diary three times over —
         * as a booking, as a meeting and as a calendar entry — so a check that
         * did not stand all three down would refuse the commonest small move:
         * half an hour later, overlapping where it currently is. Passing the
         * booking to be ignored says that once, instead of mutating rows and
         * putting them back.
         */
        $moved = DB::transaction(function () use ($booking, $page, $start) {
            if (! $this->availability->isBookable($page, $start, $booking)) {
                return null;
            }

            $end = $start->addMinutes($page->duration_minutes);
            $booking->update(['starts_at' => $start, 'ends_at' => $end]);
            $booking->meeting?->update(['scheduled_at' => $start, 'reminded_at' => null]);
            $booking->event?->update(['starts_at' => $start, 'ends_at' => $end, 'reminded_at' => null]);

            return $booking;
        });

        if ($moved) {
            $this->tell($moved->fresh()->load(['host', 'meeting', 'page']), 'rescheduled');
        }

        return $moved;
    }

    /**
     * Tell both sides, in the way each of them actually reads.
     *
     * The host gets a notification, because they are in the app and the bell
     * is where things about them arrive. The guest gets an email, because it
     * is the only address anybody has for them — and because that email is
     * their sole route back to the booking.
     *
     * Neither is allowed to fail the booking. By the time this runs the
     * transaction has committed and the guest has been told it worked; an
     * unreachable mail server must not turn that into an error page and a
     * second attempt.
     */
    public function tell(Booking $booking, string $kind, ?string $by = null): void
    {
        $when = $booking->starts_at
            ->setTimezone($booking->host?->profile?->timezone ?: config('app.timezone'))
            ->format('D j M, g:ia');

        $message = match ($kind) {
            'cancelled' => $by === 'host'
                ? "You cancelled {$booking->name}'s booking on {$when}."
                : "{$booking->name} cancelled their booking on {$when}.",
            'rescheduled' => "{$booking->name} moved their booking to {$when}.",
            default => "{$booking->name} booked you for {$when}.",
        };

        try {
            $booking->host?->notify(new SocialNotification(
                'booking_' . $kind,
                $message,
                ['booking_uuid' => $booking->uuid, 'meeting_code' => $booking->meeting?->code],
                '/meetings',
                'booking-' . $booking->uuid,
            ));
        } catch (\Throwable $e) {
            Log::warning('booking: could not notify the host: ' . $e->getMessage());
        }

        // The host cancelling their own booking does not need an email about
        // it; the guest always does.
        try {
            Mail::to($booking->email)->send(new BookingUpdate($booking, $kind));
        } catch (\Throwable $e) {
            Log::warning('booking: could not email the guest: ' . $e->getMessage());
        }
    }
}
