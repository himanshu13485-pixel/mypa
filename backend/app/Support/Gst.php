<?php

namespace App\Support;

/**
 * Which GST lines a document may carry.
 *
 * A sale within one state carries CGST and SGST; a sale across a state line
 * carries IGST. It is one or the other, never both and never a choice — and
 * the state each side is in is the first two digits of its GST number, so
 * the issuing company's state code and the client's GSTIN settle it between
 * them.
 *
 * Any other line — a company's own, or the Other tax line beside ours — is
 * untouched by this. Only the three that answer the same question are.
 *
 * Where either side is unknown, nothing is ruled out: a client with no GSTIN
 * is unregistered, and a company whose state code was never filled in has
 * not said where it is. Guessing there would grey out whichever line the
 * accountant actually needed, and a document nobody can raise is worse than
 * one they have to think about.
 *
 * The invoice form carries the same rule in TypeScript (crm/gst.ts) so that
 * it can grey the boxes as they are filled; this is the one that decides
 * what a document is saved with.
 */
final class Gst
{
    /** Ours that answer the intra/inter-state question, and nothing else. */
    public const PLACE_OF_SUPPLY_LINES = ['cgst', 'sgst', 'igst'];

    /**
     * The money lines this pairing cannot carry, by key.
     *
     * @return array<int, string>
     */
    public static function unavailableTaxes(?string $companyStateCode, ?string $clientGstin): array
    {
        $mine = self::stateCode($companyStateCode);
        $theirs = self::stateCode($clientGstin);

        if ($mine === null || $theirs === null) {
            return [];
        }

        return $mine === $theirs ? ['igst'] : ['cgst', 'sgst'];
    }

    /**
     * The two-digit state code at the front of a GST number — or of a state
     * code given on its own, which is the same two digits.
     *
     * "6" typed for Haryana is the same state as "06", and a GSTIN pasted
     * with spaces or in lower case is the same GSTIN.
     */
    public static function stateCode(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if ($digits === '' || $digits === null) {
            return null;
        }

        $code = str_pad(substr($digits, 0, 2), 2, '0', STR_PAD_LEFT);

        // 00 is not a state; 01–38 are, and a code beyond them is a typo we
        // would rather read as "not said" than as a state of its own.
        return $code === '00' || (int) $code > 38 ? null : $code;
    }
}
