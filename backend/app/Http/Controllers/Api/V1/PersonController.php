<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Somebody else's profile, as you are allowed to see it.
 *
 * A name in a chat header or a connections row was the end of the line: there
 * was nowhere to tap. Everything on this page already existed somewhere in
 * the app; what was missing was one screen that answers "who is this".
 *
 * What it shows narrows with the relationship. A stranger gets what a search
 * result already gives away - name, handle, picture. Somebody you are
 * connected to also gets the way to reach them, because you have both agreed
 * to that. The account's own state and anybody's private settings stay here.
 */
class PersonController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $person = User::with(['profile', 'settings', 'appId'])
            ->where('uuid', $uuid)->first();

        abort_unless($person && $person->status === 'active', 404, 'No such person.');

        /*
         * Blocking is mutual silence.
         *
         * Either direction hides the profile: somebody you blocked should not
         * be lookupable, and somebody who blocked you should not be told
         * anything by a screen you can still open.
         */
        abort_if($person->hasBlocked($me) || $me->hasBlocked($person), 404, 'No such person.');

        $connected = $person->id !== $me->id && Connection::where('status', 'accepted')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('requester_id', $me->id)->where('addressee_id', $person->id))
                ->orWhere(fn ($w) => $w->where('requester_id', $person->id)->where('addressee_id', $me->id)))
            ->exists();

        $itsMe = $person->id === $me->id;

        // A private account is not a 404 to somebody it is not private from.
        abort_unless(
            $itsMe || $connected
                || ($person->settings?->privacyValue('who_can_find_me') ?? 'everyone') === 'everyone',
            404,
            'No such person.',
        );

        $photoHidden = $person->settings?->privacyValue('profile_photo_visibility') === 'nobody'
            || (($person->settings?->privacyValue('profile_photo_visibility') ?? 'connections') === 'connections'
                && ! $connected && ! $itsMe);

        $pending = Connection::whereIn('status', ['pending'])
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('requester_id', $me->id)->where('addressee_id', $person->id))
                ->orWhere(fn ($w) => $w->where('requester_id', $person->id)->where('addressee_id', $me->id)))
            ->first();

        return response()->json(['data' => [
            'uuid' => $person->uuid,
            'name' => $person->name,
            'username' => $person->username,
            'app_id' => $person->appId?->app_id,

            // The line about what they are up to, which is the thing most
            // people open a profile for.
            'status' => $person->profile?->status_text,
            'bio' => $person->profile?->bio,
            'country' => $person->profile?->country,

            'avatar' => $photoHidden ? null : $person->profile?->avatar,
            'photo_path' => $photoHidden ? null : $person->profile?->photo_path,

            'presence' => $person->presenceFor($me),

            /*
             * Contact details are the part of a profile that is only ever
             * shared on purpose, so they follow the connection rather than
             * the lookup.
             */
            'email' => $connected || $itsMe ? $person->email : null,
            'mobile' => $connected || $itsMe ? $person->mobile : null,

            'is_me' => $itsMe,
            'is_connected' => $connected,
            'request_status' => $pending
                ? ($pending->requester_id === $me->id ? 'sent' : 'received')
                : null,
            'member_since' => $person->created_at?->toIso8601String(),
        ]]);
    }
}
