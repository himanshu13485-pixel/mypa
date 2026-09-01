<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppIdController extends Controller
{
    /**
     * Who can I connect with? Part of a handle is enough — the person who
     * remembers "grapout" finds harshgrapout, which is how anybody actually
     * remembers a colleague's username.
     */
    public function search(Request $request, AppIdService $appIds): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:64']]);

        $matches = $appIds->searchVisibleUsers($request->query('q'), $request->user());

        if ($matches->isEmpty()) {
            return response()->json([
                'data' => [],
                'message' => 'Nobody found. Try more of their username, or their App ID or e-mail in full.',
            ], 404);
        }

        return response()->json([
            'data' => $matches->map(function ($user) use ($appIds, $request) {
                $photoVisible = $user->settings?->privacyValue('profile_photo_visibility') !== 'nobody';

                return [
                    'uuid' => $user->uuid,
                    'name' => $user->name,
                    'username' => $user->username,
                    'app_id' => $user->appId?->app_id,
                    'photo_path' => $photoVisible ? $user->profile?->photo_path : null,
                    'avatar' => $photoVisible ? $user->profile?->avatar : null,
                    'is_connected' => $appIds->areConnected($request->user(), $user),
                ];
            })->values(),
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
