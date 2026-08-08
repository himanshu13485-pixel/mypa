<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Models\Connection;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
                ->where(fn ($w) => $w->where(fn ($n) => $match($n))
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

        $query = Connection::with(['requester.appId', 'requester.profile', 'addressee.appId', 'addressee.profile'])
            ->where(fn ($q) => $q->where('requester_id', $me->id)->orWhere('addressee_id', $me->id));

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return ConnectionResource::collection($query->latest()->paginate(20));
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

        $connection = Connection::create([
            'requester_id' => $me->id,
            'addressee_id' => $target->id,
            'message' => $data['message'] ?? null,
        ]);

        $target->notify(new \App\Notifications\SocialNotification(
            'connection_request',
            "{$me->name} sent you a connection request.",
            ['from_uuid' => $me->uuid, 'connection_uuid' => $connection->uuid],
            '/connections',
        ));

        return response()->json([
            'message' => 'Connection request sent.',
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
