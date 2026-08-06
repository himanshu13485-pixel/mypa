<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Joining a meeting with a passcode and no account.
 *
 * The pass is deliberately narrow: it is minted for one meeting, it carries no
 * password and no email, it cannot be used anywhere else in the API, and it
 * stops working after 30 minutes whether or not the meeting is still running.
 */
class MeetingGuestController extends Controller
{
    /** How long a guest pass lasts. */
    public const GUEST_MINUTES = 30;

    public function join(Request $request, string $code): JsonResponse
    {
        $meeting = Meeting::where('code', $code)->first();

        // A meeting that does not exist and one that has not opened its doors
        // answer the same way. Guessing codes should not be a way to discover
        // which meetings are real.
        abort_if($meeting === null || ! $meeting->guest_access, 404, 'No meeting is open here.');
        abort_if($meeting->status === 'ended', 410, 'This meeting has ended.');
        abort_if((bool) $meeting->is_locked, 423, 'This meeting is locked — the host is not letting anyone else in.');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:50'],
            'passcode' => ['required', 'string', 'max:12'],
        ]);

        // A passcode is required for guests, always — guest access without one
        // would make the join code alone enough for anyone who saw it.
        abort_if(
            ! $meeting->passcode || ! hash_equals($meeting->passcode, $data['passcode']),
            403,
            'That passcode is not right.',
        );

        $raw = Str::random(48);
        $guest = new User([
            'name' => trim($data['name']),
            'status' => 'active',
            'guest_meeting_id' => $meeting->id,
            'guest_expires_at' => now()->addMinutes(self::GUEST_MINUTES),
            'guest_token' => hash('sha256', $raw),
        ]);
        // No email and no password: there is no account here to sign into.
        $guest->username = 'guest_'.Str::lower(Str::random(12));
        $guest->save();

        return response()->json([
            'data' => [
                // Sent once, never stored raw, and the only way back in.
                'token' => $raw,
                'expires_at' => $guest->guest_expires_at,
                'minutes' => self::GUEST_MINUTES,
                'guest' => ['uuid' => $guest->uuid, 'name' => $guest->name],
                'meeting' => [
                    'code' => $meeting->code,
                    'title' => $meeting->title,
                    'type' => $meeting->type,
                    'requires_approval' => (bool) $meeting->requires_approval,
                ],
            ],
        ], 201);
    }
}
