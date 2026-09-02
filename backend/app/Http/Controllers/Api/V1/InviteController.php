<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inviting somebody who is not on Netvork yet.
 *
 * Every other way in assumed the other person already had an account. This
 * is the one link that works when they do not: it opens a page naming who
 * invited them, and carries that person through the sign-up so the two are
 * joined up at the end of it instead of having to find each other again.
 */
class InviteController extends Controller
{
    /** My own invite link, made the first time I ask for it. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        $code = UserProfile::inviteCodeFor($user);

        return response()->json(['data' => [
            'code' => $code,
            'url' => rtrim((string) config('mypa.frontend_url'), '/') . '/i/' . $code,
        ]]);
    }

    /**
     * Who is behind an invite link. Public, so it says as little as it can.
     *
     * A name and a handle are what the page needs to say "Ayan invited you",
     * and are what the person sending the link was always going to tell them
     * anyway. Their email, App ID, and everything else stays here.
     */
    public function show(string $code): JsonResponse
    {
        $profile = UserProfile::with('user')->where('invite_code', $code)->first();
        $inviter = $profile?->user;

        abort_unless($inviter && $inviter->status === 'active', 404, 'This invite link is not valid.');

        return response()->json(['data' => [
            'name' => $inviter->name,
            'username' => $inviter->username,
            'avatar' => $profile->avatar,
        ]]);
    }
}
