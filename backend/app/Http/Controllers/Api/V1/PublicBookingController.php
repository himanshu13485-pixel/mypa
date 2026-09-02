<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPage;
use App\Services\BookingAvailability;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use App\Support\SignupGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The side of this that strangers see.
 *
 * Every route here is reachable with no account, which makes it the second
 * unguarded door into the app after the meeting guest join — so the same rules
 * apply. Nothing returns more about the host than a person holding their link
 * already knows: a name, what the meeting is called, how long it takes. Not
 * their email, not their other bookings, and not who booked the slots that are
 * missing from the list.
 *
 * A booking is looked up by a 64-character token rather than an id, because
 * the guest has no session and that token is the only thing standing in for
 * one. Everything is throttled.
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private BookingAvailability $availability,
        private BookingService $bookings,
    ) {
    }

    /** Enough to draw the page: who, what, how long. */
    public function page(string $slug): JsonResponse
    {
        $page = BookingPage::with('user:id,name')->where('slug', $slug)->where('is_active', true)->first();

        abort_if($page === null, 404, 'There is no booking link here.');

        return response()->json(['data' => [
            'slug' => $page->slug,
            'host_name' => $page->user?->name,
            'title' => $page->title ?: 'Meeting with ' . $page->user?->name,
            'description' => $page->description,
            'duration_minutes' => $page->duration_minutes,
            'timezone' => $page->timezone(),
            'max_days_ahead' => $page->max_days_ahead,
        ]]);
    }

    /**
     * Bookable starts in a window, as UTC instants.
     *
     * The caller says which fortnight it is showing and gets back only the
     * times that survive every rule. Returning instants rather than local
     * strings keeps the timezone question entirely on the client, where the
     * browser already knows the answer.
     */
    public function slots(Request $request, string $slug): JsonResponse
    {
        $page = BookingPage::where('slug', $slug)->where('is_active', true)->first();
        abort_if($page === null, 404, 'There is no booking link here.');

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
        ]);

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        // A guard against somebody asking for a decade and making the server
        // walk it a day at a time. The client shows a fortnight.
        abort_if($from->diffInDays($to) > 62, 422, 'Ask for a shorter range.');

        $slots = $this->availability->slots($page, $from, $to);

        return response()->json(['data' => [
            'duration_minutes' => $page->duration_minutes,
            'slots' => array_map(fn ($slot) => $slot->toIso8601String(), $slots),
        ]]);
    }

    public function book(Request $request, string $slug): JsonResponse
    {
        $page = BookingPage::with('user')->where('slug', $slug)->where('is_active', true)->first();
        abort_if($page === null, 404, 'There is no booking link here.');

        /*
         * The other form a stranger can submit.
         *
         * A booking link is public by design, which makes it the second door
         * worth wedging: a script filling it takes real slots out of somebody's
         * diary and sends a confirmation email in their name for each one.
         * The same three layers, and the same silence about which objected.
         */
        SignupGuard::assertHuman($request, 'name');

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            // The guard's own fields: read by SignupGuard, never stored.
            'company_website' => ['nullable', 'string', 'max:255'],
            'form_started_at' => ['nullable', 'numeric'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
            /*
             * all_with_bc, matching registration and the profile.
             *
             * Browsers report whatever their ICU build calls the zone, and
             * plenty still say Asia/Calcutta rather than Asia/Kolkata. The
             * plain rule accepts only canonical names, so every one of those
             * visitors was refused outright with "the timezone field must be
             * a valid timezone" — a booking page nobody in India could use.
             */
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all_with_bc'],
        ]);

        $booking = $this->bookings->book($page, CarbonImmutable::parse($data['starts_at'])->utc(), $data);

        // Taken between the page loading and this arriving. A plain 409 rather
        // than an error page: the client refreshes the list and the person
        // picks again, which is the only useful thing that can happen next.
        abort_if($booking === null, 409, 'Sorry — that time has just been taken. Please pick another.');

        return response()->json([
            'message' => 'Booked.',
            'data' => $this->serialize($booking->fresh()->load(['host:id,name', 'meeting:id,code,passcode'])),
        ], 201);
    }

    /** A booking, to whoever holds its token. */
    public function show(string $token): JsonResponse
    {
        $booking = $this->byToken($token);

        return response()->json(['data' => $this->serialize($booking)]);
    }

    public function cancel(string $token): JsonResponse
    {
        $booking = $this->byToken($token);

        abort_if($booking->status === 'cancelled', 409, 'This booking is already cancelled.');
        abort_if($booking->starts_at->isPast(), 422, 'This meeting has already started.');

        $this->bookings->cancel($booking, 'guest');

        return response()->json(['message' => 'Your booking has been cancelled.']);
    }

    public function reschedule(Request $request, string $token): JsonResponse
    {
        $booking = $this->byToken($token);

        abort_if($booking->status === 'cancelled', 409, 'This booking was cancelled — book a new time instead.');
        abort_if($booking->starts_at->isPast(), 422, 'This meeting has already started.');

        $data = $request->validate(['starts_at' => ['required', 'date']]);

        $moved = $this->bookings->reschedule($booking, CarbonImmutable::parse($data['starts_at'])->utc());

        abort_if($moved === null, 409, 'Sorry — that time has just been taken. Please pick another.');

        return response()->json([
            'message' => 'Your booking has been moved.',
            'data' => $this->serialize($moved->fresh()->load(['host:id,name', 'meeting:id,code,passcode'])),
        ]);
    }

    /**
     * The token is the whole authorisation, so it is compared in constant time
     * and a miss is a flat 404 — the same answer a real token for a deleted
     * booking would give, so nothing can be learned by trying.
     */
    protected function byToken(string $token): Booking
    {
        /*
         * The page is loaded whole, not as a column subset.
         *
         * Rescheduling hands this page to the availability rules, which read
         * is_active, user_id, the durations and the notice window — and a
         * partial select leaves those null rather than absent, so every slot
         * silently evaluated as unavailable and every move came back as "that
         * time has just been taken". Narrowing the columns saved nothing worth
         * having and cost the feature.
         */
        $booking = Booking::with(['host:id,name', 'meeting:id,code,passcode', 'page.user.profile', 'page.hours'])
            ->where('manage_token', $token)
            ->first();

        abort_if($booking === null, 404, 'We could not find that booking.');

        return $booking;
    }

    /**
     * What the guest is allowed to see about their own booking.
     *
     * The passcode is in here on purpose: they need it to get into the room,
     * and they already have it in their email. The host's name and nothing
     * else of the host's.
     */
    protected function serialize(Booking $booking): array
    {
        return [
            'uuid' => $booking->uuid,
            'name' => $booking->name,
            'email' => $booking->email,
            'note' => $booking->note,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'ends_at' => $booking->ends_at->toIso8601String(),
            'guest_timezone' => $booking->guest_timezone,
            'status' => $booking->status,
            'host_name' => $booking->host?->name,
            'slug' => $booking->page?->slug,
            /*
             * One shape for both kinds of room. A booking met somewhere else
             * has a link and nothing to type, so code and passcode are null
             * and the screen showing this must not promise a password box.
             */
            'meeting' => match (true) {
                (bool) $booking->meeting_url => [
                    'provider' => 'google_meet',
                    'code' => null,
                    'passcode' => null,
                    'join_url' => $booking->meeting_url,
                ],
                (bool) $booking->meeting => [
                    'provider' => 'netvork',
                    'code' => $booking->meeting->code,
                    'passcode' => $booking->meeting->passcode,
                    'join_url' => rtrim((string) config('mypa.frontend_url'), '/') . '/join/' . $booking->meeting->code,
                ],
                default => null,
            },
        ];
    }
}
