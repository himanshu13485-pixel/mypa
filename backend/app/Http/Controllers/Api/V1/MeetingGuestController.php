<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Joining a meeting with the meeting password and no account.
 *
 * There is one invite link and everybody gets the same one. A signed-in member
 * opening it walks into the room; anyone else arrives here, where the meeting
 * password takes the place of an account. The password is also the switch: a
 * meeting without one has nothing to check a stranger against, and admits
 * members only.
 *
 * The pass minted here is deliberately narrow: it is for one meeting, it
 * carries no login and no email, it cannot be used anywhere else in the API,
 * and it stops working after 30 minutes whether or not the meeting is still
 * running.
 */
class MeetingGuestController extends Controller
{
    /** How long a guest pass lasts. */
    public const GUEST_MINUTES = 30;

    /**
     * What the join page needs before it can ask for anything.
     *
     * Booleans and nothing else: a guessed code learns only whether a password
     * box would do it any good, which is what the 403 below would tell it
     * anyway.
     */
    public function peek(string $code): JsonResponse
    {
        $meeting = Meeting::where('code', $code)->first();

        return response()->json(['data' => [
            'exists' => $meeting !== null,
            'allows_guests' => (bool) $meeting?->allowsGuests(),
            'ended' => $meeting?->status === 'ended',
            'is_locked' => (bool) $meeting?->is_locked,
        ]]);
    }

    public function join(Request $request, string $code): JsonResponse
    {
        $meeting = Meeting::where('code', $code)->first();

        abort_if($meeting === null, 404, 'No meeting is open here.');
        // No password means no guests: there would be nothing to check them
        // against, and the code alone would let in anyone who saw the link.
        // This says so plainly rather than pretending the meeting is not there
        // — the person holding the link was invited by somebody, and "no such
        // meeting" would send them back to the host with the wrong question.
        abort_if(
            ! $meeting->allowsGuests(),
            403,
            'This meeting is for signed-in Netvork members. Ask the host to set a password, or sign in.',
        );
        abort_if($meeting->status === 'ended', 410, 'This meeting has ended.');
        abort_if((bool) $meeting->is_locked, 423, 'This meeting is locked — the host is not letting anyone else in.');

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:50'],
            'passcode' => ['required', 'string', 'max:12'],
        ]);

        abort_unless(
            hash_equals($meeting->passcode, $data['passcode']),
            403,
            'That password is not right.',
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
