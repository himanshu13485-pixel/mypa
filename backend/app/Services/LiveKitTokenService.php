<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;

/**
 * Join tokens for the LiveKit SFU.
 *
 * A LiveKit token is an ordinary HS256 JWT whose "video" claim says which room
 * the bearer may enter and what they may do once inside. Signing one is a
 * hash_hmac and two base64 encodes, which is why there is no SDK here: pulling
 * in a dependency to concatenate three strings would be more code to keep in
 * step, not less.
 *
 * The token is the whole authorisation. LiveKit never calls back to ask
 * whether someone is still allowed in, so everything the server decides —
 * which room, whether they may publish, whether they may moderate — has to be
 * settled here, before it is signed.
 */
class LiveKitTokenService
{
    public function configured(): bool
    {
        return (bool) config('livekit.enabled')
            && (bool) config('livekit.url')
            && (bool) config('livekit.api_key')
            && (bool) config('livekit.api_secret');
    }

    /**
     * Should this meeting run on the SFU rather than the mesh?
     *
     * Small rooms are genuinely better peer-to-peer: no server in the middle
     * means lower latency and no bandwidth to pay for. The SFU earns its keep
     * once the mesh's per-peer upload starts to hurt.
     */
    public function shouldUseFor(Meeting $meeting): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $meshUpTo = config('livekit.mesh_up_to');
        if ($meshUpTo === null) {
            return true;
        }

        // Counts who is in the room now, not the plan's ceiling: a meeting
        // allowed fifty people but attended by three should still be direct.
        return $meeting->participants()->wherePivot('status', 'joined')->count() >= $meshUpTo;
    }

    /**
     * A token letting this person into this meeting's room.
     *
     * Identity is the user's uuid, which is what every other part of the
     * meeting already uses to name a participant — the roster, the signalling,
     * the host controls. Keeping it the same means LiveKit's view of who is in
     * the room and ours cannot drift apart.
     */
    public function tokenFor(Meeting $meeting, User $user, string $displayName, bool $canModerate): string
    {
        $now = time();
        $ttl = max(1, (int) config('livekit.token_ttl_minutes')) * 60;

        return $this->sign([
            'iss' => config('livekit.api_key'),
            'sub' => $user->uuid,
            'nbf' => $now - 10,     // a little slack for clock skew
            'exp' => $now + $ttl,
            'name' => $displayName,
            'video' => [
                'room' => $this->roomFor($meeting),
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                // Used for reactions, hands and chat if they ever move off the
                // API and onto the data channel.
                'canPublishData' => true,
                /*
                 * Moderation is the one thing that must be settled here rather
                 * than trusted from the client. A host muting somebody is a
                 * server-side act on LiveKit; without this grant the request
                 * is simply refused, which is exactly what should happen to
                 * anyone who is not a host.
                 */
                'roomAdmin' => $canModerate,
            ],
        ]);
    }

    /**
     * The room name on the LiveKit side.
     *
     * The meeting code, which is already unique and already the thing people
     * type. Prefixed so a LiveKit server shared with anything else cannot have
     * its rooms collide with ours.
     */
    public function roomFor(Meeting $meeting): string
    {
        return 'meeting-'.$meeting->code;
    }

    /** HS256, by hand — see the class note. */
    protected function sign(array $claims): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(
            json_encode($part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ), '+/', '-_'), '=');

        $body = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);
        $signature = hash_hmac('sha256', $body, (string) config('livekit.api_secret'), true);

        return $body.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
