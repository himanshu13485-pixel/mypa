<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Somewhere for a broken browser to say so.
 *
 * Deliberately open to unauthenticated callers: the errors most worth knowing
 * about are the ones that stop someone signing in, and those have no token to
 * offer. It is throttled instead, and everything it stores is truncated —
 * a report is a lead to investigate, not a place to keep data.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'url' => ['nullable', 'string', 'max:512'],
            'release' => ['nullable', 'string', 'max:64'],
        ]);

        $fingerprint = ClientError::fingerprintFor($data['message'], $data['stack'] ?? null);

        $error = ClientError::firstOrNew(['fingerprint' => $fingerprint]);
        $error->fill([
            'message' => $data['message'],
            'stack' => $data['stack'] ?? $error->stack,
            'url' => $data['url'] ?? $error->url,
            'release' => $data['release'] ?? $error->release,
            'last_user_id' => $request->user()?->id,
            'last_agent' => substr((string) $request->userAgent(), 0, 255),
            'last_seen_at' => now(),
        ]);
        $error->first_seen_at ??= now();
        $error->hits = ($error->hits ?? 0) + 1;
        // A fault that had been marked fixed and is happening again is news.
        if ($error->exists && $error->resolved_at) {
            $error->resolved_at = null;
        }
        $error->save();

        return response()->json(['message' => 'Noted.'], 202);
    }

    /** The list an admin actually looks at: loudest and most recent first. */
    public function index(Request $request): JsonResponse
    {
        $errors = ClientError::with('lastUser:id,uuid,name')
            ->when(! $request->boolean('resolved'), fn ($q) => $q->whereNull('resolved_at'))
            ->orderByDesc('last_seen_at')
            ->paginate(30)
            ->through(fn ($e) => [
                'id' => $e->id,
                'message' => $e->message,
                'stack' => $e->stack,
                'url' => $e->url,
                'release' => $e->release,
                'hits' => $e->hits,
                'last_user' => $e->lastUser?->name,
                'last_agent' => $e->last_agent,
                'first_seen_at' => $e->first_seen_at,
                'last_seen_at' => $e->last_seen_at,
                'resolved_at' => $e->resolved_at,
            ]);

        return response()->json($errors);
    }

    public function resolve(Request $request, ClientError $clientError): JsonResponse
    {
        $clientError->update([
            'resolved_at' => $clientError->resolved_at ? null : now(),
        ]);

        return response()->json(['message' => $clientError->resolved_at ? 'Marked fixed.' : 'Reopened.']);
    }
}
