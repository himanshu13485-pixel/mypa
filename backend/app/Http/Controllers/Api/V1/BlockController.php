<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $blocked = $request->user()->blockedUsers()->with('appId')->get();

        return response()->json([
            'data' => $blocked->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->name,
                'app_id' => $u->appId?->app_id,
                'reason' => $u->pivot->reason,
                'blocked_at' => $u->pivot->created_at,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = AppId::where('app_id', strtoupper(trim($data['app_id'])))->first()?->user;

        if (! $target || $target->id === $request->user()->id) {
            return response()->json(['message' => 'No user found for that App ID.'], 404);
        }

        $request->user()->blockedUsers()->syncWithoutDetaching([
            $target->id => ['reason' => $data['reason'] ?? null],
        ]);

        return response()->json(['message' => 'User blocked.'], 201);
    }

    public function destroy(Request $request, string $appId): JsonResponse
    {
        $target = AppId::where('app_id', strtoupper(trim($appId)))->first()?->user;

        if (! $target) {
            return response()->json(['message' => 'No user found for that App ID.'], 404);
        }

        $request->user()->blockedUsers()->detach($target->id);

        return response()->json(['message' => 'User unblocked.']);
    }
}
