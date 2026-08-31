<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServiceAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Service accounts, from the outside.
 *
 * Each one has a panel of its own, but reaching it means holding a token —
 * which is exactly what you have lost when you most need to look. This is the
 * view that does not depend on the credential still working: what exists, what
 * it is doing, and the one button that matters when something is wrong.
 */
class ServiceAccountAdminController extends Controller
{
    public function index(Request $request, ServiceAccountService $accounts): JsonResponse
    {
        $bots = User::with('appId')
            ->where('is_service_account', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $bot) => $accounts->summarise($bot));

        return response()->json(['data' => $bots]);
    }

    public function store(Request $request, ServiceAccountService $accounts): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'username' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        ['user' => $bot, 'token' => $token] = $accounts->create($data['name'], $data['username'] ?? null);

        return response()->json([
            'message' => "{$bot->name} created.",
            'data' => [
                ...$accounts->summarise($bot),
                // The only time this is readable. Not stored, not recoverable.
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Issue a token for an account that already exists.
     *
     * Without this the revoke button below is a one-way door: dropping every
     * token leaves nothing able to sign in, and the account's own panel — the
     * only other place a token can be issued — is reached by holding one. An
     * admin could cut an integration off and then have no way to start it
     * again. Rotating a leaked token needs both halves, in this order.
     */
    public function issueToken(Request $request, string $uuid, ServiceAccountService $accounts): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:64']]);

        $bot = $this->find($uuid);
        $token = $accounts->issueToken($bot, $data['name'] ?? 'issued by admin');

        return response()->json([
            'message' => "New token for {$bot->name}.",
            'data' => ['token' => $token],
        ], 201);
    }

    /** Every token this account has, and whether each can still be read back. */
    public function tokens(Request $request, string $uuid): JsonResponse
    {
        $tokens = $this->find($uuid)->tokens()
            ->latest('id')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'revealable' => $token->encrypted_value !== null,
            ]);

        return response()->json(['data' => $tokens]);
    }

    /**
     * Show a token again.
     *
     * The reason it is kept at all: an integration's token lives in another
     * system's configuration, and an admin who cannot read it back cannot
     * check what was installed, or repair a setup, without first cutting the
     * integration off. Encrypted at rest, so a database dump alone does not
     * carry it.
     */
    public function revealToken(Request $request, string $uuid, int $id, ServiceAccountService $accounts): JsonResponse
    {
        $token = $this->find($uuid)->tokens()->whereKey($id)->first();
        abort_unless($token, 404, 'No such token.');

        $value = $accounts->revealToken($token);

        return $value
            ? response()->json(['data' => ['id' => $token->id, 'token' => $value]])
            : response()->json([
                'message' => 'This token was issued before tokens were kept, so it cannot be shown. Issue a new one.',
            ], 410);
    }

    /** Withdraw one token, leaving the others working. */
    public function revokeToken(Request $request, string $uuid, int $id): JsonResponse
    {
        $token = $this->find($uuid)->tokens()->whereKey($id)->first();
        abort_unless($token, 404, 'No such token.');

        $token->delete();

        return response()->json(['message' => "Revoked “{$token->name}”."]);
    }

    /**
     * Cut off everything signed in as this account.
     *
     * The button for a leaked token, and for the day an integration is retired.
     * The account itself stays — its connections and what it has sent are the
     * record of what happened, and deleting it would take that with it. Issue
     * a new token from its panel to start it up again.
     */
    public function revokeTokens(Request $request, string $uuid, ServiceAccountService $accounts): JsonResponse
    {
        $bot = $this->find($uuid);
        $count = $bot->tokens()->count();
        $bot->tokens()->delete();

        return response()->json([
            'message' => $count === 1
                ? "Revoked the one token for {$bot->name}. Nothing can sign in as it now."
                : "Revoked {$count} tokens for {$bot->name}. Nothing can sign in as it now.",
            'data' => $accounts->summarise($bot->fresh('appId')),
        ]);
    }

    protected function find(string $uuid): User
    {
        $bot = User::with('appId')->where('uuid', $uuid)->where('is_service_account', true)->first();
        abort_unless($bot, 404, 'No such service account.');

        return $bot;
    }
}
