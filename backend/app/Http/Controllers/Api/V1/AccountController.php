<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Closing an account for good.
 *
 * There was no way to do this at all, which app stores require and India's
 * DPDP Act gives people a right to. It is deliberately blunt: everything the
 * account owns goes, rather than being flagged as deleted and quietly kept.
 *
 * Two things deliberately survive. Messages already sent to other people stay
 * in *their* conversations, attributed to a deleted account — the alternative
 * is one person being able to rewrite someone else's history. And the audit
 * log keeps a record that the deletion happened, without the data it deleted.
 */
class AccountController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            // Typing the words is the confirmation; a password is also
            // required from anyone who has one.
            'confirm' => ['required', 'in:DELETE'],
            'password' => ['nullable', 'string'],
        ]);

        if ($user->password && ! Hash::check($data['password'] ?? '', $user->password)) {
            return response()->json(['message' => 'That password is not right.'], 403);
        }

        // A live call or meeting would otherwise be left with a ghost in it.
        abort_if(
            \App\Models\Meeting::where('host_id', $user->id)->where('status', 'active')->exists(),
            409,
            'End the meeting you are hosting first.',
        );

        $summary = [
            'user_uuid' => $user->uuid,
            'name' => $user->name,
            'tasks' => $user->tasks()->count(),
            'notes' => $user->notes()->count(),
            'files' => $user->files()->count(),
        ];

        // Stored files are not rows and will not cascade — they have to be
        // removed from disk explicitly or the bytes outlive the account.
        $paths = File::withTrashed()->where('user_id', $user->id)->pluck('path')->filter();

        DB::transaction(function () use ($user) {
            $user->pushSubscriptions()->delete();
            $user->tokens()->delete();
            // Every other table hangs off users with a cascading foreign key,
            // so this is what actually removes the data.
            $user->delete();
        });

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
            Storage::disk('local')->delete($path);
        }

        AuditLog::create([
            'actor_id' => null,
            'action' => 'account.deleted',
            'details' => $summary,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'Your account and its data have been deleted.']);
    }
}
