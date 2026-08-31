<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppId;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Event::visibleTo($request->user())->with('participants:id,uuid,name');

        if ($from = $request->query('date_from')) {
            $query->where('starts_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('starts_at', '<=', $to . ' 23:59:59');
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return response()->json([
            'data' => $query->orderBy('starts_at')->limit(500)->get()->map(fn ($e) => $this->serialize($e, $request)),
        ]);
    }

    /** Combined calendar feed: events + due tasks in one response. */
    public function feed(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $from = $request->query('date_from');
        $to = $request->query('date_to') . ' 23:59:59';

        $events = Event::visibleTo($request->user())
            ->whereBetween('starts_at', [$from, $to])
            ->orderBy('starts_at')
            ->limit(500)
            ->get()
            ->map(fn ($e) => $this->serialize($e, $request));

        $tasks = Task::visibleTo($request->user())
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$from, $to])
            ->where('status', '!=', 'archived')
            ->with('category:id,uuid,name,color')
            ->orderBy('due_at')
            ->limit(500)
            ->get()
            ->map(fn ($t) => [
                'kind' => 'task',
                'uuid' => $t->uuid,
                'title' => $t->title,
                'starts_at' => $t->due_at,
                'all_day' => false,
                'color' => $t->color ?? $t->category?->color,
                'status' => $t->status,
                'priority' => $t->priority,
            ]);

        return response()->json(['data' => ['events' => $events, 'tasks' => $tasks]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $participants = $data['participants'] ?? [];
        unset($data['participants']);

        $event = $request->user()->events()->create($data);
        $this->syncParticipants($request, $event, $participants);

        return response()->json([
            'message' => 'Event created.',
            'data' => $this->serialize($event->fresh()->load('participants:id,uuid,name'), $request),
        ], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorizeView($request, $event);

        return response()->json([
            'data' => $this->serialize($event->load('participants:id,uuid,name'), $request),
        ]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->user_id === $request->user()->id, 403);

        $data = $this->validated($request, $event);
        $participants = $data['participants'] ?? null;
        unset($data['participants']);

        /*
         * A moved event has not been reminded about yet.
         *
         * reminded_at is what stops the sweep sending the same reminder
         * every minute, which means leaving it set after the start time
         * changes would silence the new time completely — the worst
         * outcome, because rescheduling is exactly when people rely on
         * being told.
         */
        if (array_key_exists('starts_at', $data)
            && $event->starts_at?->ne($data['starts_at'])) {
            $data['reminded_at'] = null;
        }

        $event->update($data);

        if ($participants !== null) {
            $this->syncParticipants($request, $event, $participants, sync: true);
        }

        return response()->json([
            'message' => 'Event updated.',
            'data' => $this->serialize($event->fresh()->load('participants:id,uuid,name'), $request),
        ]);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->user_id === $request->user()->id, 403);

        $event->delete();

        return response()->json(['message' => 'Event deleted.']);
    }

    public function respond(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:accepted,declined,tentative']]);

        $isParticipant = $event->participants()->where('users.id', $request->user()->id)->exists();
        abort_unless($isParticipant, 403, 'You are not invited to this event.');

        $event->participants()->updateExistingPivot($request->user()->id, ['status' => $data['status']]);

        // The organiser is the one who needs to know, and only they get told —
        // an RSVP is not news to the rest of the guest list.
        if ($event->user && $event->user_id !== $request->user()->id) {
            $said = ['accepted' => 'is coming to', 'declined' => 'cannot make', 'tentative' => 'might make'][$data['status']];

            $event->user->notify(new \App\Notifications\SocialNotification(
                'event_response',
                "{$request->user()->name} {$said} “{$event->title}”.",
                ['event_uuid' => $event->uuid, 'status' => $data['status']],
                '/calendar',
            ));
        }

        return response()->json(['message' => 'Response saved.']);
    }

    /** Export the user's calendar (events + due tasks) as an ICS file. */
    public function exportIcs(Request $request): Response
    {
        $user = $request->user();

        $events = Event::visibleTo($user)
            ->where('starts_at', '>=', now()->subMonths(1))
            ->orderBy('starts_at')->limit(500)->get();

        $tasks = Task::visibleTo($user)
            ->whereNotNull('due_at')
            ->where('due_at', '>=', now()->subMonths(1))
            ->whereNotIn('status', ['cancelled', 'archived'])
            ->orderBy('due_at')->limit(500)->get();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//My PA//Calendar//EN',
            'CALSCALE:GREGORIAN',
        ];

        $fmt = fn ($dt) => $dt->clone()->utc()->format('Ymd\THis\Z');

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:event-' . $event->uuid . '@mypa';
            $lines[] = 'DTSTAMP:' . $fmt($event->created_at);
            $lines[] = 'DTSTART:' . $fmt($event->starts_at);
            $lines[] = 'DTEND:' . $fmt($event->ends_at ?? $event->starts_at->clone()->addHour());
            $lines[] = 'SUMMARY:' . $this->icsEscape($event->title);
            if ($event->location) {
                $lines[] = 'LOCATION:' . $this->icsEscape($event->location);
            }
            if ($event->description) {
                $lines[] = 'DESCRIPTION:' . $this->icsEscape(str($event->description)->limit(500));
            }
            $lines[] = 'END:VEVENT';
        }

        foreach ($tasks as $task) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:task-' . $task->uuid . '@mypa';
            $lines[] = 'DTSTAMP:' . $fmt($task->created_at);
            $lines[] = 'DTSTART:' . $fmt($task->due_at);
            $lines[] = 'DTEND:' . $fmt($task->due_at->clone()->addMinutes($task->estimated_minutes ?? 30));
            $lines[] = 'SUMMARY:' . $this->icsEscape('[Task] ' . $task->title);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="mypa-calendar.ics"',
        ]);
    }

    protected function icsEscape(mixed $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n'],
            (string) $value
        );
    }

    // --- Helpers ------------------------------------------------------------

    protected function validated(Request $request, ?Event $event = null): array
    {
        $data = $this->validateInput($request, $event);

        // Datetimes arrive as wall-clock time in the user's timezone; store UTC.
        $tz = $request->user()->profile?->timezone ?? config('app.timezone');
        foreach (['starts_at', 'ends_at'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = \Illuminate\Support\Carbon::parse($data[$field], $tz)->utc();
            }
        }

        if (array_key_exists('group_uuid', $data)) {
            $group = $data['group_uuid']
                ? \App\Models\Group::withMember($request->user())->where('uuid', $data['group_uuid'])->firstOrFail()
                : null;
            $data['group_id'] = $group?->id;
            unset($data['group_uuid']);
        }

        return $data;
    }

    protected function validateInput(Request $request, ?Event $event = null): array
    {
        return $request->validate([
            'title' => [$event ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'in:' . implode(',', Event::TYPES)],
            'starts_at' => [$event ? 'sometimes' : 'required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'all_day' => ['sometimes', 'boolean'],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_link' => ['nullable', 'url', 'max:500'],
            'color' => ['nullable', 'string', 'max:16'],
            'repeat_config' => ['nullable', 'array'],
            'participants' => ['sometimes', 'array'],
            'participants.*' => ['string', 'max:32'],
            'group_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);
    }

    protected function syncParticipants(Request $request, Event $event, array $appIds, bool $sync = false): void
    {
        $ids = collect($appIds)
            ->map(fn ($appId) => app(\App\Services\AppIdService::class)->findVisibleUser($appId, $request->user())?->id)
            ->filter(fn ($id) => $id && $id !== $request->user()->id)
            ->unique()
            ->values();

        // Who is new, worked out before the sync — afterwards everyone looks
        // like a participant and there is no way to tell an invitation from a
        // name that was already on the list. Re-notifying on every edit is how
        // a calendar starts getting ignored.
        $existing = $event->participants()->pluck('users.id');
        $invited = $ids->diff($existing);

        if ($sync) {
            $event->participants()->sync($ids->mapWithKeys(fn ($id) => [$id => ['status' => 'invited']]));
        } else {
            $event->participants()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['status' => 'invited']]));
        }

        if ($invited->isEmpty()) {
            return;
        }

        $host = $request->user()->name;

        foreach (User::with('profile')->whereIn('id', $invited)->get() as $person) {
            // Their clock, not the organiser's — an invitation that names a
            // time in somebody else's timezone is worse than naming none.
            $when = $event->starts_at?->timezone($person->profile?->timezone ?? config('app.timezone'));

            $person->notify(new \App\Notifications\SocialNotification(
                'event_invite',
                $when
                    ? "{$host} invited you to “{$event->title}” on " . $when->format('D j M, g:ia') . '.'
                    : "{$host} invited you to “{$event->title}”.",
                ['event_uuid' => $event->uuid, 'title' => $event->title],
                '/calendar',
            ));
        }
    }

    protected function serialize(Event $event, Request $request): array
    {
        return [
            'kind' => 'event',
            'uuid' => $event->uuid,
            'title' => $event->title,
            'description' => $event->description,
            'type' => $event->type,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'all_day' => $event->all_day,
            'location' => $event->location,
            'meeting_link' => $event->meeting_link,
            'color' => $event->color,
            'is_own' => $event->user_id === $request->user()->id,
            'participants' => $event->relationLoaded('participants')
                ? $event->participants->map(fn ($u) => [
                    'uuid' => $u->uuid,
                    'name' => $u->name,
                    'status' => $u->pivot->status,
                ])
                : [],
            'created_at' => $event->created_at,
        ];
    }

    protected function authorizeView(Request $request, Event $event): void
    {
        $user = $request->user();
        $visible = $event->user_id === $user->id
            || $event->participants()->where('users.id', $user->id)->exists();

        abort_unless($visible, 403);
    }
}
