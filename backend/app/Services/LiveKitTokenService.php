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
     * Which transport this meeting is on: 'mesh' or 'sfu'.
     *
     * Small rooms are genuinely better peer-to-peer — no server in the middle
     * means lower latency and no bandwidth to pay for — and the SFU earns its
     * keep once the mesh's per-peer upload starts to hurt. So a meeting starts
     * direct and escalates when it outgrows that.
     *
     * Two rules make escalation safe, and without either one it is worse than
     * not doing it at all:
     *
     *   The answer is written down. It used to be recomputed from the live
     *   headcount every time anybody asked, and each person is told which
     *   transport to use exactly once, on the way in — so a threshold of four
     *   told the first four "mesh" and the fifth "sfu", and the room silently
     *   became two rooms. Recorded, everyone is told the same thing on their
     *   next heartbeat and moves together.
     *
     *   And it only ever goes one way. If it could fall back when somebody
     *   left, a room hovering at the threshold would migrate every time
     *   anybody came or went. The SFU handles four people perfectly well; the
     *   saving from going back is not worth a room that flickers.
     */
    public function settleTransport(Meeting $meeting): string
    {
        if (! $this->configured()) {
            // Nothing to escalate to. Not recorded either — turning LiveKit on
            // later should find the meeting undecided rather than stuck.
            return 'mesh';
        }

        if ($meeting->transport === 'sfu') {
            return 'sfu';
        }

        $meshUpTo = config('livekit.mesh_up_to');

        // Strictly above: mesh_up_to is the largest room the mesh may carry,
        // so a threshold of four escalates when the fifth person is in.
        $outgrown = $meshUpTo === null
            || $meeting->participants()->wherePivot('status', 'joined')->count() > (int) $meshUpTo;

        if (! $outgrown) {
            return 'mesh';
        }

        // forceFill, because transport is not fillable — see the note on the
        // model. This is the only place that writes it.
        $meeting->forceFill(['transport' => 'sfu'])->save();

        return 'sfu';
    }

    /**
     * What this meeting is on, without deciding anything.
     *
     * Escalation belongs to the two endpoints where somebody is actually in the
     * room — joining, and beating. Listing meetings must not trigger it: the
     * index serialises up to fifty of them, and letting that path escalate
     * would mean fifty headcount queries on a page that is only showing titles,
     * and a meeting changing transport because somebody who is not in it opened
     * a list.
     */
    public function transportFor(Meeting $meeting): string
    {
        if (! $this->configured()) {
            return 'mesh';
        }

        // Undecided and no threshold configured means the SFU, which is what
        // the first person to actually join will be told and record.
        return $meeting->transport === 'sfu' || config('livekit.mesh_up_to') === null ? 'sfu' : 'mesh';
    }

    /** @deprecated Prefer transportFor(); kept for callers that only want the flag. */
    public function shouldUseFor(Meeting $meeting): bool
    {
        return $this->transportFor($meeting) === 'sfu';
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

    /**
     * Tear the room down on the LiveKit side.
     *
     * Nothing did this, and the cost was invisible until WHM showed
     * livekit-server at twenty percent CPU a quarter of an hour after the
     * meeting had ended. Our "ended" is a row in our database and a signal to
     * whoever is listening — but a phone that is asleep, a tab left in the
     * recents screen, a browser that crashed mid-meeting: none of those hear
     * it, and every one of them keeps its WebRTC session up and publishing to
     * a room the app considers finished. LiveKit will happily carry that for
     * ever.
     *
     * Deleting the room disconnects every straggler at once, server-side,
     * with no cooperation needed from any client.
     *
     * Best-effort by design: ending a meeting must never fail because the SFU
     * is down — a meeting that cannot end is worse than a room that leaks.
     */
    public function closeRoom(Meeting $meeting): void
    {
        if (! $this->configured()) {
            return;
        }

        $now = time();
        $token = $this->sign([
            'iss' => config('livekit.api_key'),
            'sub' => 'netvork-server',
            'nbf' => $now - 10,
            'exp' => $now + 60,
            // DeleteRoom is gated on roomCreate — the create/destroy pair is
            // one permission in LiveKit's model.
            'video' => ['roomCreate' => true],
        ]);

        try {
            \Illuminate\Support\Facades\Http::withToken($token)
                ->timeout(2)
                // The loopback, not the public wss:// URL: this is
                // server-to-server on the same box, and Apache need not be in
                // the middle of it.
                ->post(rtrim(config('livekit.api_url'), '/').'/twirp/livekit.RoomService/DeleteRoom', [
                    'room' => $this->roomFor($meeting),
                ]);
        } catch (\Throwable $e) {
            // Including "no such room", which is the normal case for a meeting
            // nobody ever joined.
            \Illuminate\Support\Facades\Log::info('livekit: could not close room', [
                'room' => $this->roomFor($meeting), 'error' => $e->getMessage(),
            ]);
        }
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
