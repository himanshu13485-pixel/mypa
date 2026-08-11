<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LiveKit (SFU)
    |--------------------------------------------------------------------------
    |
    | Meetings run as a full mesh by default: everybody connects to everybody,
    | which costs nothing to host and stops being usable at six or eight people
    | because each person uploads their own picture once per peer.
    |
    | An SFU turns that around. Everyone sends one stream to the server and the
    | server copies it out, so a participant's upload no longer grows with the
    | room. That is how a fifty-person meeting is possible at all — and it moves
    | the cost from the participants' connections onto ours.
    |
    | Off unless a key and secret are configured, so an installation that has
    | not set one up keeps the mesh rather than failing to find a server.
    |
    */

    'enabled' => env('LIVEKIT_ENABLED', false),

    /** wss:// URL of the LiveKit server the browser connects to. */
    'url' => env('LIVEKIT_URL'),

    'api_key' => env('LIVEKIT_API_KEY'),

    'api_secret' => env('LIVEKIT_API_SECRET'),

    /**
     * How long a join token is good for.
     *
     * Only needs to outlive the walk from "press Join" to "connected" — the
     * connection it opens is not re-checked against it afterwards, so a short
     * life costs nothing and limits what a leaked token is worth.
     */
    'token_ttl_minutes' => (int) env('LIVEKIT_TOKEN_TTL_MINUTES', 10),

    /**
     * The largest room the mesh is asked to carry.
     *
     * Up to this many people a meeting runs peer-to-peer, where it is genuinely
     * better: no server in the middle means lower latency and no bandwidth
     * bill. The person who arrives after it escalates the whole room to the
     * SFU, everybody together and mid-meeting.
     *
     * Null means "always the SFU when enabled".
     *
     * The escalation is one-way and written down — see settleTransport(). Both
     * matter. Recomputing it per request split rooms in half, and letting it
     * fall back when somebody left would make a room hovering at the threshold
     * migrate every time anybody came or went.
     */
    /*
     * Absent and empty both mean null.
     *
     * They read identically in a .env and used to behave differently: absent
     * gave null, while `LIVEKIT_MESH_UP_TO=` gave the empty string, which
     * casts to 0 — "the mesh carries nobody". That happened to come out as
     * always-the-SFU, which is the same thing null means, so it worked by
     * luck rather than by intent.
     */
    'mesh_up_to' => ($meshUpTo = env('LIVEKIT_MESH_UP_TO')) === null || $meshUpTo === ''
        ? null
        : (int) $meshUpTo,

];
