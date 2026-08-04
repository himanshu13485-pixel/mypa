<?php

namespace App\Services\Voice;

use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns a spoken name ("call Rahul", "message priya sharma") into the actual
 * connections it could mean. Speech recognition mangles names constantly, so
 * matching is deliberately forgiving — and when more than one person fits, the
 * caller is asked rather than guessed at.
 */
class ContactResolver
{
    /** Words that ride along with a name and must not be matched against it. */
    protected const NOISE = [
        'the', 'to', 'with', 'a', 'an', 'my', 'please', 'now', 'sir', 'madam',
        'को', 'से', 'का', 'की', 'के',
    ];

    /**
     * @return Collection<int, User> best matches first; empty when nothing fits
     */
    public function resolve(User $me, string $spokenName): Collection
    {
        $name = $this->clean($spokenName);

        if ($name === '') {
            return collect();
        }

        $candidates = $this->connectionsOf($me);

        // Exact-ish match on username or App ID wins outright — those are typed
        // or spelled out, never approximated.
        $exact = $candidates->filter(fn (User $u) => mb_strtolower($u->username ?? '') === $name
            || mb_strtolower(optional($u->appId)->app_id ?? '') === $name);

        if ($exact->isNotEmpty()) {
            return $exact->values();
        }

        $scored = $candidates
            ->map(fn (User $u) => ['user' => $u, 'score' => $this->score($u, $name)])
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return collect();
        }

        // Keep everything close to the best score: a clear winner returns one
        // person, a tie returns the tie so the user can pick.
        $best = $scored->first()['score'];

        return $scored
            ->filter(fn (array $row) => $row['score'] >= $best - 5)
            ->map(fn (array $row) => $row['user'])
            ->take(5)
            ->values();
    }

    /** Everyone this user is actually connected to (accepted both ways). */
    public function connectionsOf(User $me): Collection
    {
        return Connection::with(['requester.appId', 'addressee.appId'])
            ->where('status', 'accepted')
            ->where(fn ($q) => $q->where('requester_id', $me->id)->orWhere('addressee_id', $me->id))
            ->get()
            ->map(fn (Connection $c) => $c->requester_id === $me->id ? $c->addressee : $c->requester)
            ->filter()
            ->filter(fn (User $u) => $u->status === 'active')
            ->unique('id')
            ->values();
    }

    protected function clean(string $name): string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        $words = array_filter(
            explode(' ', $name),
            fn (string $w) => $w !== '' && ! in_array($w, self::NOISE, true),
        );

        return implode(' ', $words);
    }

    /**
     * 100 = the whole name matches, down to a few points for a loose
     * resemblance. Anything at 0 is not offered at all.
     */
    protected function score(User $user, string $name): int
    {
        $full = mb_strtolower($user->name ?? '');
        $username = mb_strtolower($user->username ?? '');

        if ($full === $name) {
            return 100;
        }

        // "call rahul" against "Rahul Sharma" — a spoken first name is the
        // single most common form, so it scores near the top.
        $parts = explode(' ', $full);
        if (in_array($name, $parts, true)) {
            return 90;
        }

        if ($full !== '' && str_contains($full, $name)) {
            return 80;
        }
        if ($username !== '' && str_contains($username, $name)) {
            return 70;
        }

        // Last resort: phonetic-ish similarity, for names the recognizer spelled
        // differently to the way they are stored ("priyah" vs "priya").
        similar_text($full, $name, $percent);

        return $percent >= 70 ? (int) round($percent / 2) : 0;
    }
}
