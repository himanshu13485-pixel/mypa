<?php

namespace Tests\Unit;

use App\Support\DialCode;
use PHPUnit\Framework\TestCase;

/**
 * Putting a country in front of every number already in the records.
 *
 * This rule rewrites every phone number a business owns, once, during a
 * deploy. There is no undo worth having - afterwards nothing can tell a code
 * this wrote from one somebody typed - so the cases it has to get right are
 * worth stating one at a time.
 */
class DialCodeTest extends TestCase
{
    public function test_a_bare_number_is_given_the_country_it_was_typed_in(): void
    {
        $this->assertSame('+919310325393', DialCode::addDefault('9310325393'));
    }

    public function test_the_trunk_zero_goes(): void
    {
        // Dialling inside India you press 0 first; from outside you do not.
        // Keeping it would make a number nobody can ring.
        $this->assertSame('+919310325393', DialCode::addDefault('09310325393'));
        $this->assertSame('+911123456789', DialCode::addDefault('011-2345 6789'));
    }

    public function test_however_somebody_spaced_it(): void
    {
        $this->assertSame('+919310325393', DialCode::addDefault('93103 25393'));
        $this->assertSame('+919310325393', DialCode::addDefault('(93103) 25-393'));
        $this->assertSame('+919310325393', DialCode::addDefault('  9310325393  '));
    }

    public function test_a_number_that_already_says_where_it_is_is_left_alone(): void
    {
        // null means "do not write this row" - which is what keeps a backfill
        // from touching rows it has no business in.
        $this->assertNull(DialCode::addDefault('+971501234567'));
        $this->assertNull(DialCode::addDefault('+91 93103 25393'));
    }

    public function test_an_empty_field_stays_empty(): void
    {
        $this->assertNull(DialCode::addDefault(null));
        $this->assertNull(DialCode::addDefault(''));
        $this->assertNull(DialCode::addDefault('   '));
    }

    public function test_something_too_short_to_be_a_phone_number_is_left_alone(): void
    {
        // An extension, half a number, a note to self. A country code in
        // front would only make nonsense look official.
        $this->assertNull(DialCode::addDefault('1234'));
        $this->assertNull(DialCode::addDefault('n/a'));
        $this->assertNull(DialCode::addDefault('-'));
    }

    public function test_the_last_ten_digits_are_unchanged_so_nothing_stops_matching(): void
    {
        /*
         * Clients and leads are matched against each other on the last ten
         * digits of a number. If this changed those, every record in the
         * system would stop recognising its own duplicates on the day it ran.
         */
        $before = '9310325393';
        $after = (string) DialCode::addDefault($before);

        $this->assertSame(substr($before, -10), substr(preg_replace('/\D/', '', $after), -10));
    }

    public function test_another_country_can_be_named(): void
    {
        $this->assertSame('+971501234567', DialCode::addDefault('501234567', '+971'));
    }
}
