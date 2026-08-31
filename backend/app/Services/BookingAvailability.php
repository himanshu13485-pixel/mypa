<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\Event;
use App\Models\Meeting;
use Carbon\CarbonImmutable;

/**
 * When somebody can actually be booked.
 *
 * Four things narrow a week down to a list of offerable moments, and they are
 * deliberately applied in this order because each is cheaper than the next:
 *
 *   The weekly hours say when the host is willing at all.
 *   Notice and horizon cut off what is too soon and too far away.
 *   Slots are laid out inside each window at duration + buffer intervals.
 *   Anything already in the diary removes the slots it touches.
 *
 * Everything is computed in the host's own timezone and returned in UTC. That
 * split matters: "Tuesday 9am" is a wall-clock fact about the host's life and
 * survives daylight saving, whereas the instant it corresponds to does not.
 * Doing the arithmetic in UTC and converting at the end would quietly move
 * somebody's morning by an hour twice a year.
 */
class BookingAvailability
{
    /**
     * A meeting has a start but no length anywhere in the schema, so one is
     * assumed when checking whether it blocks a slot. An hour is the
     * pessimistic choice, and pessimism is right here: the cost of assuming
     * too much is a slot nobody could have taken anyway, and the cost of
     * assuming too little is being double-booked.
     */
    public const ASSUMED_MEETING_MINUTES = 60;

    /** The same, for a calendar entry saved without an end time. */
    public const ASSUMED_EVENT_MINUTES = 60;

    /**
     * Bookable start times between two instants, as UTC CarbonImmutables.
     *
     * $ignoring stands one booking down for the duration of the question,
     * along with the meeting and calendar entry it created. Rescheduling
     * needs that: the booking being moved is itself on the host's diary
     * three times over, so without it the commonest small move — half an
     * hour later, overlapping where it currently sits — would be refused as
     * a clash with itself.
     *
     * The range is what the caller asked to see; notice and horizon narrow it
     * further, and asking for a window entirely outside them returns nothing
     * rather than an error — a calendar showing an empty month is a truthful
     * answer to "can I book you in 2031".
     */
    public function slots(
        BookingPage $page,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Booking $ignoring = null,
    ): array {
        if (! $page->is_active) {
            return [];
        }

        $now = CarbonImmutable::now();
        $earliest = $now->addMinutes($page->min_notice_minutes);
        $latest = $now->addDays($page->max_days_ahead)->endOfDay();

        $from = $from->greaterThan($earliest) ? $from : $earliest;
        $to = $to->lessThan($latest) ? $to : $latest;

        if ($from->greaterThanOrEqualTo($to)) {
            return [];
        }

        $tz = $page->timezone();
        $windows = $page->hours()->orderBy('start_time')->get()->groupBy('weekday');
        if ($windows->isEmpty()) {
            return [];
        }

        // Widened by a day at each end: a window in the host's timezone can
        // begin on a calendar day that the requested UTC range does not name.
        $busy = $this->busyPeriods($page, $from->subDay(), $to->addDay(), $ignoring);

        $slots = [];
        $step = max(5, $page->duration_minutes + $page->buffer_minutes);
        $day = $from->setTimezone($tz)->startOfDay();
        $lastDay = $to->setTimezone($tz)->startOfDay();

        while ($day->lessThanOrEqualTo($lastDay)) {
            foreach ($windows->get($day->dayOfWeek, collect()) as $window) {
                $slots = array_merge($slots, $this->slotsInWindow(
                    $page, $day, $window->start_time, $window->end_time, $step, $from, $to, $busy,
                ));
            }

            $day = $day->addDay();
        }

        return $slots;
    }

    /** One weekday window, sliced into offerable starts. */
    private function slotsInWindow(
        BookingPage $page,
        CarbonImmutable $day,
        string $startTime,
        string $endTime,
        int $step,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $busy,
    ): array {
        $windowStart = $day->setTimeFromTimeString($startTime);
        $windowEnd = $day->setTimeFromTimeString($endTime);

        // An end at or before the start is a window somebody has mistyped; it
        // offers nothing rather than looping forever.
        if ($windowEnd->lessThanOrEqualTo($windowStart)) {
            return [];
        }

        $slots = [];

        for ($start = $windowStart; ; $start = $start->addMinutes($step)) {
            $end = $start->addMinutes($page->duration_minutes);

            /*
             * The whole meeting has to fit inside the window, not just its
             * start. Offering 16:45 for a half-hour meeting on a day that ends
             * at 17:00 is how a booking system books you for time you said you
             * did not have.
             *
             * The buffer is deliberately not required to fit: it is space
             * between meetings, not part of the working day, and demanding it
             * at the end would silently shorten every window by ten minutes.
             */
            if ($end->greaterThan($windowEnd)) {
                break;
            }

            if ($start->lessThan($from) || $start->greaterThan($to)) {
                continue;
            }

            if ($this->overlapsAnything($start, $end, $busy)) {
                continue;
            }

            $slots[] = $start->utc();
        }

        return $slots;
    }

    /**
     * Everything already on this host's diary, widened by the buffer.
     *
     * Widening here rather than at each comparison is what makes the buffer
     * mean "leave a gap either side of anything I am already doing" — the
     * useful reading — rather than only applying between two bookings made
     * through this page.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function busyPeriods(
        BookingPage $page,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Booking $ignoring = null,
    ): array {
        $pad = $page->buffer_minutes;
        $periods = [];

        // The rows store the app timezone's wall clock, so the range must be
        // bound on that clock too — a Carbon binds as its OWN wall clock.
        $from = $from->setTimezone(config('app.timezone'));
        $to = $to->setTimezone(config('app.timezone'));

        $add = function (?CarbonImmutable $start, ?CarbonImmutable $end, int $fallbackMinutes) use (&$periods, $pad) {
            if (! $start) {
                return;
            }
            $end = $end && $end->greaterThan($start) ? $end : $start->addMinutes($fallbackMinutes);
            $periods[] = [$start->subMinutes($pad), $end->addMinutes($pad)];
        };

        foreach (Booking::confirmed()
            ->where('host_id', $page->user_id)
            ->when($ignoring, fn ($q) => $q->whereKeyNot($ignoring->getKey()))
            ->whereBetween('starts_at', [$from, $to])
            ->get(['starts_at', 'ends_at']) as $booking) {
            $add(CarbonImmutable::parse($booking->starts_at), CarbonImmutable::parse($booking->ends_at), $page->duration_minutes);
        }

        // The host's own calendar. All-day entries are skipped: they mean "this
        // is happening today", not "I am unavailable for every minute of it",
        // and treating a birthday as a wall of busy would empty the day.
        foreach (Event::where('user_id', $page->user_id)
            ->when($ignoring?->event_id, fn ($q, $id) => $q->whereKeyNot($id))
            ->where('all_day', false)
            ->whereBetween('starts_at', [$from, $to])
            ->get(['starts_at', 'ends_at']) as $event) {
            $add(
                CarbonImmutable::parse($event->starts_at),
                $event->ends_at ? CarbonImmutable::parse($event->ends_at) : null,
                self::ASSUMED_EVENT_MINUTES,
            );
        }

        // Meetings the host is hosting. Ended ones are not in anybody's way.
        foreach (Meeting::where('host_id', $page->user_id)
            ->when($ignoring?->meeting_id, fn ($q, $id) => $q->whereKeyNot($id))
            ->whereIn('status', ['scheduled', 'active'])
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from, $to])
            ->get(['scheduled_at']) as $meeting) {
            $add(CarbonImmutable::parse($meeting->scheduled_at), null, self::ASSUMED_MEETING_MINUTES);
        }

        return $periods;
    }

    /** Half-open overlap: touching end-to-start is not a clash. */
    private function overlapsAnything(CarbonImmutable $start, CarbonImmutable $end, array $busy): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($start->lessThan($busyEnd) && $end->greaterThan($busyStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this exact start still bookable?
     *
     * Asked again when a booking is submitted, because the list the guest is
     * looking at was computed when the page loaded and somebody else may have
     * taken the slot since. Comparing against the generated list rather than
     * re-deriving the rules also means a submitted time that was never on
     * offer — a hand-crafted request, or a stale tab from before the hours
     * changed — is refused for the same reason and by the same code.
     */
    public function isBookable(BookingPage $page, CarbonImmutable $start, ?Booking $ignoring = null): bool
    {
        foreach ($this->slots($page, $start->subMinute(), $start->addMinute(), $ignoring) as $slot) {
            if ($slot->equalTo($start)) {
                return true;
            }
        }

        return false;
    }
}
