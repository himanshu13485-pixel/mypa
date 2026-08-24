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
     * Cut off everything signed in as this account.
     *
     * The button for a leaked token, and for the day an integration is retired.
     * The account itself stays — its connections and what it has sent are the
     * record of what happened, and deleting it would take that with it. Issue
     * a new token from its panel to start it up again.
     */
    public function revokeTokens(Request $request, string $uuid, ServiceAccountService $accounts): JsonResponse
    {
        $bot = User::with('appId')->where('uuid', $uuid)->where('is_service_account', true)->first();
        abort_unless($bot, 404, 'No such service account.');

        $count = $bot->tokens()->count();
        $bot->tokens()->delete();

        return response()->json([
            'message' => $count === 1
                ? "Revoked the one token for {$bot->name}. Nothing can sign in as it now."
                : "Revoked {$count} tokens for {$bot->name}. Nothing can sign in as it now.",
            'data' => $accounts->summarise($bot->fresh('appId')),
        ]);
    }
}
