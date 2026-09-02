<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserProfile extends Model
{
    /*
     * The table's defaults, stated on the model too.
     *
     * A column default is applied by the database on insert and never read
     * back, so a row made by firstOrCreate() or create([]) hands back null for
     * every one of these until something reloads it. That is not theoretical:
     * it is exactly how the booking page came to show the first option of each
     * dropdown instead of its real defaults, on the one load where it mattered.
     *
     * Nothing reads language or account_type yet, so this is insurance rather than a repair —
     * but the trap is invisible until somebody does, and by then it looks like
     * a bug in whatever they wrote.
     */
    protected $attributes = [
        'timezone' => 'Asia/Kolkata',
        'language' => 'en',
        'account_type' => 'personal',
    ];

    protected $fillable = [
        'user_id', 'photo_path', 'avatar', 'date_of_birth', 'gender', 'country',
        'timezone', 'language', 'account_type', 'bio', 'referral_app_id',
        'invite_code', 'status_text',
    ];

    /**
     * The code in this person's invite link, made the first time they ask.
     *
     * Random rather than derived from anything: the page it opens is public,
     * so a code somebody could guess from a name or an App ID would be a
     * directory with the door left open.
     */
    public static function inviteCodeFor(User $user): string
    {
        $profile = $user->profile ?: $user->profile()->create([]);

        if (! $profile->invite_code) {
            do {
                $code = Str::lower(Str::random(16));
            } while (static::where('invite_code', $code)->exists());

            $profile->update(['invite_code' => $code]);
        }

        return $profile->invite_code;
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
