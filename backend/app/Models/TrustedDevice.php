<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A device whose owner has already answered a sign-in code on it.
 *
 * The token is generated here, handed to the browser once, and never stored:
 * the row keeps a hash of it. So this table can say "the machine holding
 * token X has been trusted since Tuesday" and can never say what X is.
 */
class TrustedDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'name', 'created_ip', 'last_used_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Trust this device, and hand back the token that proves it next time.
     *
     * @return array{0: self, 1: string} the row, and the plaintext token
     */
    public static function issueFor(User $user, ?string $name, ?string $ip, int $days): array
    {
        $token = Str::random(64);

        $device = static::create([
            'user_id' => $user->id,
            'token_hash' => static::hashFor($token),
            'name' => $name,
            'created_ip' => $ip,
            'last_used_at' => now(),
            'expires_at' => now()->addDays($days),
        ]);

        return [$device, $token];
    }

    /**
     * How many tokens one browser may offer at once.
     *
     * A machine in a shared office holds one per person who signs in on it.
     * Ten covers a front desk without letting the header become a way to
     * make the server hash an unbounded list.
     */
    public const MAX_OFFERED = 10;

    /**
     * The live trust among the tokens this browser offered, if any.
     *
     * A browser holds one token per ACCOUNT that has answered a code on it,
     * not one for itself — trust is a fact about a person on a machine, and
     * a machine two people share has two of them. It offers all it has and
     * this picks out the one belonging to whoever is signing in; the others
     * match no row for this user and are simply ignored.
     *
     * Sending them together is what avoids naming the accounts. The
     * alternative — filing each token under the address that earned it —
     * would write a list of everyone who uses the machine into the browser,
     * and would still ask for a code whenever somebody signed in by
     * username having signed up by e-mail.
     */
    public static function findLive(User $user, ?string $tokens): ?self
    {
        $hashes = collect(explode(',', (string) $tokens))
            ->map(fn ($token) => trim($token))
            ->filter()
            ->unique()
            ->take(self::MAX_OFFERED)
            ->map(fn ($token) => static::hashFor($token))
            ->all();

        if ($hashes === []) {
            return null;
        }

        return static::where('user_id', $user->id)
            ->whereIn('token_hash', $hashes)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Used today, so trusted a while longer.
     *
     * The window slides from the last sign-in rather than the first, so a
     * machine somebody uses daily is not turned back into a stranger two
     * months after it was first trusted. Nothing was renewing it before,
     * and last_used_at was written once at creation and never again — so
     * the column said when trust began while claiming to say when it was
     * last exercised.
     */
    public function renew(int $days): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'expires_at' => now()->addDays(max(1, $days)),
        ])->save();
    }
}
