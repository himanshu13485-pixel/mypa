<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One agreed meeting, booked by somebody with no account.
 *
 * Holds its own start and end rather than deriving them from the page, so
 * changing your availability or your meeting length never rewrites history.
 * Cancelled rows are kept rather than deleted: the slot is freed by the status,
 * and "that was booked and then called off" is worth being able to see.
 */
class Booking extends Model
{
    use \App\Models\Concerns\StoresOfficeClock;

    public function setStartsAtAttribute($value): void
    {
        $this->attributes['starts_at'] = $this->officeClock($value);
    }

    public function setEndsAtAttribute($value): void
    {
        $this->attributes['ends_at'] = $this->officeClock($value);
    }

    use HasUuids;

    protected $fillable = [
        'booking_page_id', 'host_id', 'meeting_id', 'meeting_url', 'event_id',
        'name', 'email', 'note', 'guest_timezone',
        'starts_at', 'ends_at', 'manage_token',
        'status', 'cancelled_at', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(BookingPage::class, 'booking_page_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Bookings that still occupy their slot. */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * What the guest presents instead of a session.
     *
     * 64 characters of the alphabet Str::random uses, which is far past
     * guessing. It is the only credential they will ever have, so it is
     * generated once and never shown to anybody else.
     */
    public static function newManageToken(): string
    {
        do {
            $token = Str::random(64);
        } while (static::where('manage_token', $token)->exists());

        return $token;
    }
}
