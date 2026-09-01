<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\PresenceChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The browser saying what it knows about the person in front of it.
 *
 * Nothing on the server can tell a person reading from a person who left the
 * tab open and went to lunch: both look like a chat screen polling every
 * twenty seconds. The browser can — it watches for a key, a pointer, a tab
 * being hidden — so it is the one that says which of the three it is, and
 * this is where it says it.
 *
 * Two writes, kept apart on purpose. `presence_state` is the claim, and
 * `last_active_at` — "the app made a request" — is only advanced when the
 * claim is 'online'. Advancing it while away would mean an idle tab kept
 * itself out of the offline bracket for ever, which is exactly the bug this
 * whole endpoint exists to end.
 */
class PresenceController extends Controller
{
    public function beat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', Rule::in(User::PRESENCE_STATES)],
        ]);

        $me = $request->user();
        $state = $data['state'];

        // What the world saw a moment ago, asked before anything is written.
        $before = $me->presenceState();

        $columns = [
            'presence_state' => $state,
            'presence_updated_at' => now(),
        ];
        if ($state === 'online') {
            $columns['last_active_at'] = now();
        }

        // Straight through the query builder, like TrackActivity: these are
        // not fillable and must never become so — presence is something the
        // app reports about itself, not a field a profile update can set.
        User::withoutGlobalScopes()->whereKey($me->id)->update($columns);

        $me->forceFill($columns);

        if ($me->presenceState() !== $before) {
            $this->tell($me, $me->presenceState());
        }

        return response()->json(['data' => ['state' => $me->presenceState()]]);
    }

    /**
     * The tab is closing.
     *
     * Sent from `pagehide` with fetch(keepalive), which outlives the page and
     * still carries the bearer token — sendBeacon cannot set headers, and this
     * route is behind auth like every other. Separate from beat() because a
     * request nobody will ever read the response of should have nothing in it
     * that can fail: no body, no validation, one word written.
     */
    public function leaving(Request $request): JsonResponse
    {
        $me = $request->user();
        $before = $me->presenceState();

        DB::table('users')->where('id', $me->id)->update([
            'presence_state' => 'offline',
            'presence_updated_at' => now(),
        ]);

        $me->forceFill(['presence_state' => 'offline', 'presence_updated_at' => now()]);

        if ($before !== 'offline') {
            $this->tell($me, 'offline');
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * Say it to everybody who can see it — if there is anybody.
     *
     * The empty check is not tidiness. An audience is empty for somebody with
     * no connections and no conversations, and for anybody who has hidden
     * their status at all; broadcasting to no channels is not a no-op but a
     * request to the broadcaster with an empty channel list, which is the sort
     * of thing that throws on a brand new account's very first heartbeat.
     */
    protected function tell(User $me, string $state): void
    {
        $audience = $me->presenceAudience();
        if ($audience === []) {
            return;
        }

        Realtime::send(new PresenceChanged($me->uuid, $state, $audience));
    }
}
