<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppIdController extends Controller
{
    public function search(Request $request, AppIdService $appIds): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:32']]);

        $user = $appIds->findVisibleUser($request->query('q'), $request->user());

        if (! $user) {
            return response()->json(['data' => null, 'message' => 'No user found for that App ID.'], 404);
        }

        $photoVisible = $user->settings?->privacyValue('profile_photo_visibility') !== 'nobody';

        return response()->json([
            'data' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'app_id' => $user->appId?->app_id,
                'photo_path' => $photoVisible ? $user->profile?->photo_path : null,
                'is_connected' => $appIds->areConnected($request->user(), $user),
            ],
        ]);
    }

    public function myQr(Request $request): JsonResponse
    {
        $appId = $request->user()->appId?->app_id;

        abort_unless($appId, 404, 'App ID not found.');

        // QR payload the frontend renders as a QR code; scanning opens a connect link.
        return response()->json([
            'data' => [
                'app_id' => $appId,
                'payload' => config('mypa.frontend_url') . '/connect?app_id=' . $appId,
            ],
        ]);
    }
}
