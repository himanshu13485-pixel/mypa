<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternalNote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal Work: staff-only (Admin / Subadmin / Salesperson) discussion threads
 * attached to a user. Never visible to the user themselves.
 */
class InternalNoteController extends Controller
{
    /** Recent threads: users with internal notes, newest activity first. */
    public function threads(Request $request): JsonResponse
    {
        $threads = InternalNote::with('user:id,uuid,name,username,email')
            ->selectRaw('user_id, MAX(created_at) as last_at, COUNT(*) as notes_count')
            ->groupBy('user_id')
            ->orderByDesc('last_at')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'user' => [
                    'uuid' => $row->user->uuid,
                    'name' => $row->user->name,
                    'username' => $row->user->username,
                    'email' => $row->user->email,
                ],
                'notes_count' => (int) $row->notes_count,
                'last_at' => $row->last_at,
            ]);

        return response()->json(['data' => $threads]);
    }

    /** Resolve a username / email / App ID to a user, for starting a new thread. */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']]);

        $user = app(\App\Services\AppIdService::class)->lookup($data['identifier']);

        if (! $user) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        return response()->json(['data' => [
            'uuid' => $user->uuid,
            'name' => $user->name,
            'username' => $user->username,
        ]]);
    }

    public function index(Request $request, User $user): JsonResponse
    {
        $notes = InternalNote::with('author:id,uuid,name')
            ->where('user_id', $user->id)
            ->oldest()
            ->limit(200)
            ->get()
            ->map(fn ($note) => [
                'uuid' => $note->uuid,
                'body' => $note->body,
                'author' => [
                    'uuid' => $note->author->uuid,
                    'name' => $note->author->name,
                    'is_me' => $note->author_id === $request->user()->id,
                ],
                'created_at' => $note->created_at,
            ]);

        return response()->json([
            'data' => [
                'user' => ['uuid' => $user->uuid, 'name' => $user->name, 'username' => $user->username],
                'notes' => $notes,
            ],
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $note = InternalNote::create([
            'user_id' => $user->id,
            'author_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        return response()->json([
            'message' => 'Note added.',
            'data' => [
                'uuid' => $note->uuid,
                'body' => $note->body,
                'author' => ['uuid' => $request->user()->uuid, 'name' => $request->user()->name, 'is_me' => true],
                'created_at' => $note->created_at,
            ],
        ], 201);
    }
}
