<?php

namespace App\Models\Concerns;

use Carbon\Carbon;

/**
 * Whatever timezone a writer hands us, the row stores the office clock.
 *
 * The datetime cast reads naive database values in the app timezone, so a
 * writer passing a UTC instant (the booking flow does) would come back
 * shifted by the office offset. Normalising at write time keeps every
 * diary row on one clock; a writer already on the app timezone is
 * untouched, and so is a plain string, which the cast owns.
 */
trait StoresOfficeClock
{
    protected function officeClock(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface
            ? Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
            : $value;
    }
}
