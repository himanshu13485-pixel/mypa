<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookingPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The host's side: the link, the hours, and what has been booked.
 *
 * Everything here is about one page — your own — so nothing takes an id. There
 * is exactly one per person and the route resolves it from the session, which
 * removes a whole class of "whose page is this" mistake.
 */
class BookingPageController extends Controller
{
    /**
     * Your booking page, created on first sight if you have never had one.
     *
     * Created rather than 404'd because there is nothing to decide: everyone
     * gets one, its defaults are sensible, and making somebody press "create"
     * before they can see what they would be creating is a step that exists
     * only to be got past. It starts inactive, so a link nobody has configured
     * yet cannot be booked.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->serialize($this->pageFor($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $page = $this->pageFor($request);

        $data = $request->validate([
            'slug' => ['sometimes', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/',
                'unique:booking_pages,slug,' . $page->id],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'duration_minutes' => ['sometimes', 'integer', 'min:5', 'max:480'],
            'buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'min_notice_minutes' => ['sometimes', 'integer', 'min:0', 'max:20160'],
            'max_days_ahead' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],

            /*
             * Which room the link books into.
             *
             * The URL is required when the provider is not Netvork's own,
             * because a page that says "Google Meet" with nothing behind it
             * would take bookings and send guests nowhere. It is checked
             * against the real host so a typo cannot quietly point the whole
             * page at somebody else's site.
             */
            'meeting_provider' => ['sometimes', 'in:netvork,google_meet'],
            'external_meeting_url' => [
                'exclude_if:meeting_provider,netvork',
                'required_if:meeting_provider,google_meet',
                'nullable', 'string', 'max:512', 'url:https',
                'regex:/^https:\/\/meet\.google\.com\/[A-Za-z0-9?=&_\-\/]+$/',
            ],

            // The whole week arrives at once and replaces what was there. A
            // person edits their availability as a shape, not as a list of
            // rows, and diffing it would be work in aid of nothing.
            'hours' => ['sometimes', 'array', 'max:50'],
            'hours.*.weekday' => ['required_with:hours', 'integer', 'min:0', 'max:6'],
            'hours.*.start_time' => ['required_with:hours', 'date_format:H:i'],
            'hours.*.end_time' => ['required_with:hours', 'date_format:H:i', 'after:hours.*.start_time'],
        ]);

        $hours = $data['hours'] ?? null;
        unset($data['hours']);

        $page->update($data);

        if ($hours !== null) {
            $page->hours()->delete();
            foreach ($hours as $window) {
                $page->hours()->create([
                    'weekday' => $window['weekday'],
                    'start_time' => $window['start_time'],
                    'end_time' => $window['end_time'],
                ]);
            }
        }

        return response()->json([
            'message' => 'Booking link updated.',
            'data' => $this->serialize($page->fresh()->load('hours')),
        ]);
    }

    /** What has been booked, soonest first, upcoming by default. */
    public function bookings(Request $request): JsonResponse
    {
        $page = $this->pageFor($request);

        $bookings = $page->bookings()
            ->with('meeting:id,code')
            ->when(! $request->boolean('past'), fn ($q) => $q->where('starts_at', '>=', now()->subHours(2)))
            ->when($request->boolean('past'), fn ($q) => $q->where('starts_at', '<', now()))
            ->orderBy('starts_at', $request->boolean('past') ? 'desc' : 'asc')
            ->limit(200)
            ->get();

        return response()->json(['data' => $bookings->map(fn ($b) => $this->serializeBooking($b))]);
    }

    /**
     * The host calling one off.
     *
     * Scoped through their own page rather than looked up by uuid alone, so a
     * booking belonging to somebody else is a 404 rather than a 403 — there is
     * no reason to confirm it exists.
     */
    public function cancelBooking(Request $request, string $booking): JsonResponse
    {
        $page = $this->pageFor($request);

        $row = $page->bookings()->with(['host', 'meeting', 'page'])->where('uuid', $booking)->first();
        abort_if($row === null, 404, 'We could not find that booking.');
        abort_if($row->status === 'cancelled', 409, 'That booking is already cancelled.');

        app(\App\Services\BookingService::class)->cancel($row, 'host');

        return response()->json(['message' => 'Booking cancelled — ' . $row->name . ' has been emailed.']);
    }

    protected function pageFor(Request $request): BookingPage
    {
        $user = $request->user();

        return BookingPage::with('hours')->firstOrCreate(
            ['user_id' => $user->id],
            [
                'slug' => BookingPage::slugFor($user),
                'title' => null,
                // Off until somebody has looked at it and set their hours: an
                // active link with no availability is a page that says "no
                // times", which reads as broken rather than as unfinished.
                'is_active' => false,
            ],
        );
    }

    protected function serialize(BookingPage $page): array
    {
        return [
            'uuid' => $page->uuid,
            'slug' => $page->slug,
            'url' => rtrim((string) config('mypa.frontend_url'), '/') . '/book/' . $page->slug,
            'title' => $page->title,
            'description' => $page->description,
            'duration_minutes' => $page->duration_minutes,
            'buffer_minutes' => $page->buffer_minutes,
            'min_notice_minutes' => $page->min_notice_minutes,
            'max_days_ahead' => $page->max_days_ahead,
            'is_active' => $page->is_active,
            'meeting_provider' => $page->meeting_provider,
            'external_meeting_url' => $page->external_meeting_url,
            'timezone' => $page->timezone(),
            'hours' => $page->hours->map(fn ($h) => [
                'weekday' => $h->weekday,
                'start_time' => Str::substr($h->start_time, 0, 5),
                'end_time' => Str::substr($h->end_time, 0, 5),
            ])->values(),
        ];
    }

    protected function serializeBooking($booking): array
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
            'meeting_code' => $booking->meeting?->code,
            'meeting_url' => $booking->meeting_url,
        ];
    }
}
