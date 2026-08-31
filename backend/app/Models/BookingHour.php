<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One window of a weekday somebody is willing to be booked in.
 *
 * Several rows may share a weekday, which is how "mornings and afternoons but
 * not lunch" is expressed. No timestamps: these are edited as a set — the old
 * rows are replaced wholesale — so when a particular row was written is not a
 * question anybody asks.
 */
class BookingHour extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_page_id', 'weekday', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(BookingPage::class, 'booking_page_id');
    }
}
