<?php

namespace App\Services\Crm;

use App\Models\Crm\Organization;
use Illuminate\Http\Request;

/**
 * Where a punch came from, established rather than asked.
 *
 * A phone in a pocket can open the app anywhere, so "I punched in" on its
 * own says nothing about being at work. Three things narrow that down, and
 * they are deliberately in order of how hard each is to fake:
 *
 *   The device kind, read from the browser's own user agent. Cheap to
 *   spoof by anyone who wants to, but it is never asked of the client —
 *   the client cannot simply declare itself a desktop.
 *
 *   The IP, which the request carries and cannot choose. An office
 *   network is one address; a phone on mobile data is visibly not it.
 *
 *   The place, when the company asks for it and the person allows it,
 *   measured against the office the company registered. This is the only
 *   one that answers the actual question, which is why the policy can
 *   require it.
 *
 * None of it is a verdict. It is what the register can honestly say, put in
 * front of whoever reads the report so they can ask the person about it.
 */
class PunchOrigin
{
    public const KINDS = ['app', 'mobile', 'desktop'];

    /**
     * What kind of thing made this request.
     *
     * The app identifies itself with a header it sets at build time; a
     * browser is told apart by the user agent it sends anyway. Anything
     * unrecognised is called a desktop rather than guessed at, because a
     * wrong specific answer reads as fact where a plain one reads as plain.
     */
    public function deviceKind(Request $request): string
    {
        if ($request->header('X-Netvork-App')) {
            return 'app';
        }

        $agent = mb_strtolower((string) $request->userAgent());

        return preg_match('/android|iphone|ipad|ipod|windows phone|mobile safari|opera mini/i', $agent)
            ? 'mobile'
            : 'desktop';
    }

    /** The office this company registered, or null if it never did. */
    public function office(Organization $org): ?array
    {
        $policy = $org->hrPolicy();
        $lat = $policy['office_lat'] ?? null;
        $lng = $policy['office_lng'] ?? null;

        return ($lat === null || $lng === null || $lat === '' || $lng === '')
            ? null
            : [
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'radius_m' => (int) ($policy['office_radius_m'] ?? 200),
                'required' => (bool) ($policy['punch_needs_location'] ?? false),
            ];
    }

    /**
     * Metres between two points on the earth, by the haversine formula.
     *
     * Straight-line distance, not walking distance: the question is "is
     * this person at the office", and a hundred metres away is a hundred
     * metres away whichever way the pavement runs.
     */
    public function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
