<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Message;
use App\Services\ServiceAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The panel a service account gets instead of the app.
 *
 * An application signed in here has no use for notes, habits or meetings, and
 * showing it those would be an invitation to treat it as a person's account.
 * What it does need is the three things nobody else has to think about: who is
 * allowed to hear from it, what may sign in as it, and whether anything is
 * actually going out.
 *
 * Every route is scoped to the caller. A service account administers itself
 * and nothing else — there is no id to pass and no way to name another account.
 */
class ServiceAccountController extends Controller
{
    /** Identity, plus enough numbers to tell working from silent. */
    public function overview(Request $request): JsonResponse
    {
        $me = $request->user();

        $sent = Message::where('user_id', $me->id);

        return response()->json([
            'data' => [
                'name' => $me->name,
                'username' => $me->username,
                'app_id' => $me->appId?->app_id,
                'connections' => $this->connectionQuery($request)->count(),
                'tokens' => $me->tokens()->count(),
                'messages_sent' => (clone $sent)->count(),
                // The single most useful number here: an integration that has
                // stopped is otherwise indistinguishable from one nobody is
                // using, and both look like silence from the outside.
                'last_sent_at' => (clone $sent)->latest('id')->value('created_at'),
            ],
        ]);
    }

    // -- Tokens ---------------------------------------------------------------

    public function tokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()
            ->latest('id')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                // So the one you are holding is not the one you revoke.
                'current' => $token->id === $request->user()->currentAccessToken()?->id,
                // Tokens issued before they were kept cannot be shown again.
                'revealable' => $token->encrypted_value !== null,
            ]);

        return response()->json(['data' => $tokens]);
    }

    public function issueToken(Request $request, ServiceAccountService $accounts): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:64']]);

        $token = $accounts->issueToken($request->user(), $data['name']);

        return response()->json([
            'message' => 'Token created.',
            'data' => ['name' => $data['name'], 'token' => $token],
        ], 201);
    }

    /**
     * Read a token back.
     *
     * Kept encrypted rather than hashed, for service accounts only, because an
     * integration's token lives in another system's configuration: losing the
     * only copy means nobody can check what was installed, or repair a setup
     * without cutting the integration off first.
     */
    public function revealToken(Request $request, int $id, ServiceAccountService $accounts): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->first();
        abort_unless($token, 404, 'No such token.');

        $value = $accounts->revealToken($token);

        return $value
            ? response()->json(['data' => ['id' => $token->id, 'token' => $value]])
            : response()->json([
                'message' => 'This token was issued before tokens were kept, so it cannot be shown. Issue a new one.',
            ], 410);
    }

    public function revokeToken(Request $request, int $id): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($id)->first();
        abort_unless($token, 404, 'No such token.');

        /*
         * Revoking the token you are using would sign you out mid-sentence and
         * leave you unable to reach the panel to fix it. Refused rather than
         * confirmed: this is the one mistake here that cannot be undone from
         * inside the app.
         */
        abort_if(
            $token->id === $request->user()->currentAccessToken()?->id,
            422,
            'That is the token you are signed in with. Issue another one first, sign in with it, then revoke this.'
        );

        $token->delete();

        return response()->json(['message' => 'Token revoked.']);
    }

    // -- Connections ----------------------------------------------------------

    /** Who has agreed to hear from this account. */
    public function connections(Request $request): JsonResponse
    {
        $connections = $this->connectionQuery($request)
            ->with(['requester.appId', 'addressee.appId'])
            ->latest('responded_at')
            ->limit(500)
            ->get()
            ->map(function (Connection $c) use ($request) {
                $person = $c->requester_id === $request->user()->id ? $c->addressee : $c->requester;

                return [
                    'uuid' => $c->uuid,
                    'name' => $person?->name,
                    'app_id' => $person?->appId?->app_id,
                    'connected_at' => $c->responded_at,
                ];
            });

        return response()->json(['data' => $connections]);
    }

    /**
     * Drop a connection.
     *
     * Cutting someone off from an integration is the account's business, and
     * the person keeps the same power from their own side — this is not a
     * privilege, it is the other half of a two-sided agreement.
     */
    public function disconnect(Request $request, string $uuid): JsonResponse
    {
        $connection = $this->connectionQuery($request)->where('uuid', $uuid)->first();
        abort_unless($connection, 404, 'No such connection.');

        $connection->delete();

        return response()->json(['message' => 'Disconnected.']);
    }

    // -- Internals ------------------------------------------------------------

    /** Accepted connections in either direction — who asked first is history. */
    protected function connectionQuery(Request $request)
    {
        $id = $request->user()->id;

        return Connection::where('status', 'accepted')
            ->where(fn ($q) => $q->where('requester_id', $id)->orWhere('addressee_id', $id));
    }
}
