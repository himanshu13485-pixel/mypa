<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The link somebody hands out so other people can book them.
 *
 * One per person. Everything on it is configuration — how long a meeting is,
 * when you are willing to have one, how much warning you need — and none of it
 * is a fact about any particular booking, which is why bookings record their
 * own times rather than deriving them from here.
 */
class BookingPage extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'slug', 'title', 'description',
        'duration_minutes', 'buffer_minutes', 'min_notice_minutes', 'max_days_ahead',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'max_days_ahead' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BookingHour::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** The timezone the weekly hours are written in: the host's own. */
    public function timezone(): string
    {
        return $this->user?->profile?->timezone ?: config('app.timezone');
    }

    /**
     * A URL-safe name derived from whatever the person is called.
     *
     * Taken once, at creation, and then theirs. Collisions get a short numeric
     * suffix rather than a random string, because a booking link is something
     * people read aloud and type — "ayan-2" survives that and "ayan-x7fq" does
     * not.
     */
    public static function slugFor(User $user): string
    {
        $base = Str::slug($user->username ?: $user->name ?: 'user') ?: 'user';
        $base = Str::limit($base, 40, '');

        $slug = $base;
        for ($n = 2; static::where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
