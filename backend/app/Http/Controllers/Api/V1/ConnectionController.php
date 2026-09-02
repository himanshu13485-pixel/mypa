<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class ConnectionController extends Controller
{
    /**
     * Typeahead for every share / add-member box: matches name, username,
     * email or App ID.
     *
     * Your connections come first because they are who you usually mean, but
     * the search does not stop there — the group, file and chat flows all
     * accept anyone you are allowed to reach, and limiting the suggestions to
     * connections left people with an empty dropdown and no way to tell that
     * typing a username outright would have worked.
     *
     * Anyone the viewer is not permitted to discover is filtered out, using
     * the same rules as a direct App ID lookup: inactive accounts, blocks in
     * either direction, and the target's own who_can_find_me setting.
     */
    public function suggest(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 1) {
            return response()->json(['data' => []]);
        }

        $me = $request->user();
        $limit = 8;

        $connectionIds = \App\Models\Connection::where('status', 'accepted')
            ->where(fn ($w) => $w->where('requester_id', $me->id)->orWhere('addressee_id', $me->id))
            ->get(['requester_id', 'addressee_id'])
            ->flatMap(fn ($c) => [$c->requester_id, $c->addressee_id])
            ->unique()
            ->reject(fn ($id) => $id === $me->id)
            ->values();

        $match = fn ($w) => $w->where('name', 'like', "%{$q}%")
            ->orWhere('username', 'like', "%{$q}%")
            ->orWhere('email', 'like', "%{$q}%");

        $connections = \App\Models\User::with(['settings', 'appId', 'profile'])
            ->whereIn('id', $connectionIds)
            ->where($match)
            ->orderBy('name')
            ->limit($limit)
            ->get();

        // Top up from everyone else only if the connections did not fill the
        // list, so the people you already know still sort first.
        $others = collect();
        if ($connections->count() < $limit) {
            $others = \App\Models\User::with(['settings', 'appId', 'profile'])
                ->where('status', 'active')
                ->whereNotIn('id', $connectionIds->merge([$me->id]))
                // Strangers are found by their public handles — name,
                // username, App ID. An address has to be typed in full:
                // matching part of one turns this box into a way to read
                // the platform's address book three letters at a time.
                ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                    ->orWhere('username', 'like', "%{$q}%")
                    ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($q)])
                    ->orWhereHas('appId', fn ($a) => $a->where('app_id', 'like', "%{$q}%")->where('is_active', true)))
                ->orderBy('name')
                // Over-fetch: the privacy filter below runs in PHP, so some of
                // these will be dropped before the list is trimmed.
                ->limit(($limit - $connections->count()) * 4)
                ->get()
                ->filter(fn ($u) => $this->isDiscoverableBy($u, $me))
                ->take($limit - $connections->count());
        }

        $data = $connections->map(fn ($u) => $this->suggestion($u, true))
            ->concat($others->map(fn ($u) => $this->suggestion($u, false)))
            ->values();

        return response()->json(['data' => $data]);
    }

    /** Same visibility rules as looking someone up by App ID directly. */
    protected function isDiscoverableBy(\App\Models\User $target, \App\Models\User $viewer): bool
    {
        if ($target->id === $viewer->id || $target->status !== 'active') {
            return false;
        }

        if ($target->hasBlocked($viewer) || $viewer->hasBlocked($target)) {
            return false;
        }

        // 'connections' users are excluded here by definition: this branch only
        // ever runs for people the viewer is NOT connected to.
        return ($target->settings?->privacyValue('who_can_find_me') ?? 'everyone') === 'everyone';
    }

    protected function suggestion(\App\Models\User $u, bool $connected): array
    {
        return [
            'uuid' => $u->uuid,
            'name' => $u->name,
            'username' => $u->username,
            // Handing a stranger's email address to anyone who types three
            // letters of their name is more than discovery needs — people you
            // are not connected to are identified by username and App ID.
            'email' => $connected ? $u->email : null,
            'app_id' => $u->appId?->app_id,
            // An avatar stands in for the profile photo, so it answers to the
            // same privacy setting rather than being visible to everyone.
            'avatar' => $u->settings?->privacyValue('profile_photo_visibility') === 'nobody'
                ? null
                : $u->profile?->avatar,
            'photo_path' => $u->settings?->privacyValue('profile_photo_visibility') === 'nobody'
                ? null
                : $u->profile?->photo_path,
            'connected' => $connected,
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $me = $request->user();

        // settings comes along because the resource asks every row whether its
        // owner allows their presence to be seen. It was being lazy-loaded one
        // row at a time — twenty extra queries a page, to answer twenty
        // questions that could have been asked once.
        $query = Connection::with([
            'requester.appId', 'requester.profile', 'requester.settings',
            'addressee.appId', 'addressee.profile', 'addressee.settings',
        ])->where(fn ($q) => $q->where('requester_id', $me->id)->orWhere('addressee_id', $me->id));

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        /*
         * Searching the people you already know.
         *
         * Asked of the database, not of the page: the list is paginated at
         * twenty, so a filter applied in the browser could only ever search
         * the twenty already on screen — which is why looking for a
         * colleague by name found nothing the moment the address book grew
         * past a page. The other person is whichever side of the row is not
         * you, so both sides are searched.
         */
        if ($q = trim((string) $request->query('q'))) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)) . '%';
            $matches = fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(username) LIKE ?', [$like])
                ->orWhereHas('appId', fn ($a) => $a->whereRaw('LOWER(app_id) LIKE ?', [$like]));

            $query->where(fn ($w) => $w
                ->whereHas('requester', fn ($r) => $r->whereKeyNot($me->id)->where($matches))
                ->orWhereHas('addressee', fn ($a) => $a->whereKeyNot($me->id)->where($matches)));
        }

        /*
         * Reachable people first, when asked for.
         *
         * Ranked here rather than in SQL for one reason: presenceState() is
         * the method the dot itself is drawn from, and a CASE expression
         * rebuilding that ladder in SQL would be a second copy of it — one
         * that drifts the first time the thresholds move, and puts somebody
         * at the top of the list with a red dot beside their name.
         *
         * Which means fetching the set rather than a page of it. That is fine
         * at the size this list actually is — people you have deliberately
         * connected to — and guarded above the point where it stops being
         * fine, where the plain paginated query takes over again and the
         * ordering is simply not offered.
         */
        $rows = $query->latest()->limit(self::RANK_LIMIT + 1)->get();

        if ($rows->count() > self::RANK_LIMIT) {
            return ConnectionResource::collection($query->latest()->paginate(20));
        }

        $onlineCount = $rows
            ->filter(fn ($c) => $c->status === 'accepted' && $this->presenceRank($c, $me) === self::RANK_ONLINE)
            ->count();

        if ($request->boolean('online_first')) {
            // sortBy is stable in PHP 8, so everybody inside a bucket keeps
            // the order they already had — newest first. The toggle changes
            // which group you are in, never where you sit within it.
            $rows = $rows->sortBy(fn ($c) => $this->sortRank($c, $me))->values();
        }

        $perPage = 20;
        $page = LengthAwarePaginator::resolveCurrentPage();

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'query' => $request->query()],
        );

        // Alongside the list rather than inside its meta, which belongs to the
        // paginator. It is what the toggle labels itself with, so it has to be
        // the count across everybody — not the count on this page, which is
        // the number the toggle exists to stop you having to trust.
        return ConnectionResource::collection($paginator)
            ->additional(['online_count' => $onlineCount]);
    }

    /** Where the three states sort. Online first, and the rest in order. */
    protected const RANK_ONLINE = 0;

    protected const RANK_AWAY = 1;

    protected const RANK_UNREACHABLE = 2;

    /**
     * Past this many rows the ordering is not offered at all.
     *
     * Ranking means holding the whole set in memory, and an address book that
     * large is not one anybody is scanning for a green dot anyway — they are
     * searching it, which the query above already does in SQL.
     */
    protected const RANK_LIMIT = 500;

    /**
     * How reachable the other person is, as far as this viewer may know.
     *
     * The privacy check is the whole subtlety here. Somebody who set their
     * online status to 'nobody' must not sort into the first bucket, because
     * position is itself an answer: floating them to the top of a list headed
     * "online first" would say they are online just as plainly as a green dot
     * would, and the setting would be defeated by the ordering rather than
     * honoured by the display. They sort with the people who are not known to
     * be reachable, which — from where the viewer stands — is what they are.
     *
     * 'connections' needs no test in this list. Everybody in it is a
     * connection; that is what the list is.
     */
    protected function presenceRank(Connection $connection, \App\Models\User $me): int
    {
        $other = $connection->requester_id === $me->id ? $connection->addressee : $connection->requester;

        if (! $other) {
            return self::RANK_UNREACHABLE;
        }

        /*
         * The same rule as everywhere else, including its reciprocal half:
         * somebody who hides their own presence sorts nobody by it, because
         * they are not shown anybody's.
         */
        if (! $other->presenceVisibleTo($me)) {
            return self::RANK_UNREACHABLE;
        }

        return match ($other->presenceState()) {
            'online' => self::RANK_ONLINE,
            'away' => self::RANK_AWAY,
            default => self::RANK_UNREACHABLE,
        };
    }

    /**
     * The same rank, with requests held clear of it.
     *
     * A pending request is not a person you are choosing between — it is a
     * question waiting for an answer, and it is drawn in a card of its own.
     * Sorting it by presence would be sorting the wrong list; worse, sorting
     * it to the back could push it off the first page and out of a card that
     * has no second one. It leads instead, and keeps the order it had.
     */
    protected function sortRank(Connection $connection, \App\Models\User $me): int
    {
        return $connection->status === 'accepted'
            ? $this->presenceRank($connection, $me)
            : -1;
    }

    public function store(Request $request, AppIdService $appIds): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $me = $request->user();
        $target = $appIds->findVisibleUser($data['app_id'], $me);

        if (! $target || $target->id === $me->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $connectPref = $target->settings?->privacyValue('who_can_connect') ?? 'everyone';
        if ($connectPref === 'nobody') {
            return response()->json(['message' => 'This user is not accepting connection requests.'], 403);
        }

        $existing = Connection::where(function ($q) use ($me, $target) {
            $q->where(fn ($w) => $w->where('requester_id', $me->id)->where('addressee_id', $target->id))
                ->orWhere(fn ($w) => $w->where('requester_id', $target->id)->where('addressee_id', $me->id));
        })->whereIn('status', ['pending', 'accepted'])->first();

        if ($existing) {
            return response()->json([
                'message' => $existing->status === 'accepted'
                    ? 'You are already connected with this user.'
                    : 'A connection request is already pending.',
            ], 409);
        }

        /*
         * An application's account answers immediately, because nobody is
         * going to answer for it.
         *
         * A request to one would otherwise sit pending for ever — there is no
         * person reading its notifications — and whoever sent it would be left
         * waiting on a decision that was never coming. The consent that
         * matters is still the sender's: they chose to connect, and they can
         * disconnect or block exactly as with anyone else.
         */
        $auto = (bool) $target->is_service_account;

        $connection = Connection::create([
            'requester_id' => $me->id,
            'addressee_id' => $target->id,
            'message' => $data['message'] ?? null,
            ...($auto ? ['status' => 'accepted', 'responded_at' => now()] : []),
        ]);

        // Nothing is sent to a service account: it has a bell nobody reads.
        if (! $auto) {
            $target->notify(new \App\Notifications\SocialNotification(
                'connection_request',
                "{$me->name} sent you a connection request.",
                ['from_uuid' => $me->uuid, 'connection_uuid' => $connection->uuid],
                '/connections',
            ));
        }

        return response()->json([
            'message' => $auto
                ? "You are now connected with {$target->name}."
                : 'Connection request sent.',
            'data' => new ConnectionResource($connection->load(['requester.appId', 'addressee.appId'])),
        ], 201);
    }

    public function respond(Request $request, Connection $connection): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:accept,decline']]);

        abort_unless($connection->addressee_id === $request->user()->id, 403);
        abort_unless($connection->status === 'pending', 409, 'This request has already been handled.');

        $connection->update([
            'status' => $data['action'] === 'accept' ? 'accepted' : 'declined',
            'responded_at' => now(),
        ]);

        if ($data['action'] === 'accept') {
            $connection->requester->notify(new \App\Notifications\SocialNotification(
                'connection_accepted',
                "{$request->user()->name} accepted your connection request.",
                ['from_uuid' => $request->user()->uuid],
                '/connections',
            ));
        }

        // The request has been attended — clear its notification for me.
        $request->user()->unreadNotifications()
            ->where('data->kind', 'connection_request')
            ->where('data->connection_uuid', $connection->uuid)
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => $data['action'] === 'accept' ? 'Connection accepted.' : 'Connection declined.',
            'data' => new ConnectionResource($connection->fresh()->load(['requester.appId', 'addressee.appId'])),
        ]);
    }

    public function destroy(Request $request, Connection $connection): JsonResponse
    {
        $me = $request->user();

        abort_unless(in_array($me->id, [$connection->requester_id, $connection->addressee_id]), 403);

        $connection->delete();

        return response()->json(['message' => 'Connection removed.']);
    }
}
