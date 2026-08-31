<?php

namespace App\Support;

/**
 * House style for names and identifiers, applied on the way in so the data
 * reads the same however it was typed (the old CRM was full of SHOUTED
 * company names pasted from spreadsheets).
 *
 *   Company / person names → Title Case
 *   Email addresses        → lower case
 *   Tax and bank numbers   → UPPER CASE, no spaces
 *
 * A short leading acronym survives title casing, because that is the brand:
 * "CGPL CORPINESS GLOBAL PVT LTD" becomes "CGPL Corpiness Global Pvt Ltd",
 * not "Cgpl Corpiness …".
 */
class TextCase
{
    /** Corporate forms that are always written in capitals. */
    private const ALWAYS_UPPER = [
        'LLC', 'LLP', 'PLC', 'INC', 'LLLP', 'PLLC',
        'NV', 'BV', 'SA', 'AG', 'AS', 'OY', 'AB',
        'FZE', 'FZCO', 'DMCC', 'JSC', 'PJSC', 'SARL', 'SRL', 'SPA',
        'UK', 'USA', 'UAE', 'HK', 'KSA',
    ];

    /** Roman numerals are capitals wherever they appear: "Artis - II". */
    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /** How many leading characters may stay capitalised as a brand mark. */
    private const BRAND_ACRONYM_MAX = 5;

    public static function name(?string $value, bool $brandAcronym = false): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }

        // "Everything is capitals" is the case worth rescuing; a name typed
        // with any lower case at all is assumed deliberate, so its own
        // short capitalised words (M.A., LLC, R&D) are left alone.
        $isShouted = mb_strtoupper($value) === $value;

        $words = explode(' ', $value);

        foreach ($words as $i => $word) {
            $letters = preg_replace('/[^\p{L}]/u', '', $word);

            if ($letters === '') {
                continue; // "&", "-", "3" and friends stay as they are
            }

            $bare = mb_strtoupper(preg_replace('/[^\p{L}\p{N}]/u', '', $word));

            if (in_array(mb_strtoupper($letters), self::ALWAYS_UPPER, true)
                || in_array($bare, self::ROMAN, true)) {
                $words[$i] = mb_strtoupper($word);

                continue;
            }

            // Codes that mix letters and digits — B2B, 24X7, A1 — are read
            // as written; title casing would turn "B2B" into "B2b".
            if (mb_strtoupper($word) === $word && preg_match('/\p{N}/u', $word)) {
                $words[$i] = mb_strtoupper($word);

                continue;
            }

            $isAcronym = mb_strtoupper($word) === $word && mb_strlen($letters) <= self::BRAND_ACRONYM_MAX;

            // Keep it capitalised when it is the brand mark at the front, or
            // when the writer capitalised it deliberately amid normal text.
            if ($isAcronym && (($brandAcronym && $i === 0) || ! $isShouted)) {
                $words[$i] = mb_strtoupper($word);

                continue;
            }

            $words[$i] = self::titleWord($word);
        }

        return implode(' ', $words);
    }

    /** A company name: the leading acronym is the brand and survives. */
    public static function company(?string $value): ?string
    {
        return self::name($value, brandAcronym: true);
    }

    public static function email(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }

    /** GSTIN, PAN, IFSC, Aadhaar: capitals, no spaces. */
    public static function code(?string $value): ?string
    {
        $value = mb_strtoupper(preg_replace('/\s+/u', '', (string) $value));

        return $value === '' ? null : $value;
    }

    /** Title-cases one word, respecting hyphens, apostrophes and dots. */
    private static function titleWord(string $word): string
    {
        $lower = mb_strtolower($word);

        return preg_replace_callback(
            '/(^|[-\'’.\/])(\p{L})/u',
            fn ($m) => $m[1] . mb_strtoupper($m[2]),
            $lower,
        );
    }
}
