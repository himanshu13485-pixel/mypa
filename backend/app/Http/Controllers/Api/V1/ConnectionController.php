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
