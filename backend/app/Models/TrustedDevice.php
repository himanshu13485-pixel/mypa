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

    /** The live trust for this token, if there is one. */
    public static function findLive(User $user, ?string $token): ?self
    {
        if (! $token) {
            return null;
        }

        return static::where('user_id', $user->id)
            ->where('token_hash', static::hashFor($token))
            ->where('expires_at', '>', now())
            ->first();
    }
}
