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
     * The room size the mesh can carry. A meeting whose plan allows more than
     * this uses the SFU; one that can never grow past it stays peer-to-peer,
     * where it is genuinely better — no server in the middle means lower
     * latency and no bandwidth bill.
     *
     * Null means "always the SFU when enabled", which is the safe setting.
     *
     * Compared against the host's plan ceiling, deliberately, and not against
     * how many people are in the room at the moment somebody asks. A headcount
     * moves while a meeting is being joined, and each person is told which
     * transport to use exactly once, on the way in — so a threshold of four
     * gave the first four the mesh and the fifth the SFU, split the room down
     * the middle, and reported nothing to anyone. A ceiling cannot move like
     * that, so everybody gets the same answer from the first arrival to the
     * last.
     */
    'mesh_up_to' => env('LIVEKIT_MESH_UP_TO') === null
        ? null
        : (int) env('LIVEKIT_MESH_UP_TO'),

];
