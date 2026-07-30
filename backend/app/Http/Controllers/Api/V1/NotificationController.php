<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return response()->json($query->paginate(20));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['count' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }

    /** Attending a section clears its notification kinds (sidebar auto-clear). */
    public function markKindsRead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kinds' => ['required', 'array', 'min:1', 'max:10'],
            'kinds.*' => ['string', 'max:64'],
        ]);

        $request->user()->unreadNotifications()
            ->whereIn('data->kind', $data['kinds'])
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications cleared.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return response()->json(['message' => 'Notification removed.']);
    }
}
