<?php

namespace App\Support;

/**
 * Putting a country in front of a number that never had one.
 *
 * Every number in the records was typed into a plain box, by people who all
 * worked in one country and had no reason to say so: "9310325393", "093103
 * 25393", "011-2345 6789". The forms ask for a country now, which makes the
 * old rows the odd ones out - a list where some numbers say where they are
 * and some do not is worse than one where none of them do.
 *
 * So they are given the country they were always in. This is the rule that
 * does it, kept here rather than written out inside a migration, because a
 * rule that rewrites every phone number a business owns is worth being able
 * to read on its own and worth being able to test.
 */
class DialCode
{
    /** Where every existing record was typed. */
    public const DEFAULT = '+91';

    /**
     * The stored number, with a country code on the front.
     *
     * Left exactly as it is when there is nothing to do: an empty field, or
     * a number that already says which country it belongs to. Returning null
     * for those is how the caller knows not to write the row at all, which
     * keeps a backfill from touching millions of rows it has no business in.
     */
    public static function addDefault(?string $stored, string $code = self::DEFAULT): ?string
    {
        $raw = trim((string) $stored);

        // Nothing there, or somebody has already said where it is.
        if ($raw === '' || str_starts_with($raw, '+')) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw);

        /*
         * The trunk zero, which is not part of the number.
         *
         * Dialling within India you press 0 first; dialling from outside you
         * do not. "09310325393" and "+919310325393" are the same phone, and
         * keeping the zero would make a number nobody can ring.
         */
        $digits = ltrim((string) $digits, '0');

        /*
         * Too short to be a phone number at all - an extension, a note to
         * self, half of something. Left alone rather than decorated with a
         * country code that would make nonsense look official.
         */
        if (strlen($digits) < 6) {
            return null;
        }

        return $code . $digits;
    }
}
