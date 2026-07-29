<?php

namespace App\Support;

/**
 * Integer minor-unit (paise) money helpers. String parsing only — the billing
 * path never touches floating point.
 */
class Money
{
    /** "199.50" → 19950. Accepts int|string decimal values from decimal columns. */
    public static function toPaise(int|string $decimal): int
    {
        $decimal = (string) $decimal;
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '0');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $paise = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$paise : $paise;
    }

    /** 19950 → "199.50" */
    public static function toDecimalString(int $paise): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);

        return ($negative ? '-' : '') . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Percentage in basis points (1800 = 18%), floor-rounded to whole paise. */
    public static function percentOf(int $paise, int $basisPoints): int
    {
        return intdiv($paise * $basisPoints, 10000);
    }
}
