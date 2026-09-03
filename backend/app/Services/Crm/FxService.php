<?php

namespace App\Services\Crm;

use App\Models\Crm\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Foreign currency to rupees, the way the bank actually pays it.
 *
 * The market rate comes from the open exchange-rate API and is cached for
 * six hours; the company's own margin (₹2 by default — banks take their
 * conversion cut) comes off the top. A USD invoice at a market 96 therefore
 * converts at 94, and that effective rate is what the universal INR figure
 * on the document is computed with.
 */
class FxService
{
    public function __construct(private Organization $org)
    {
    }

    /** The rupees knocked off the market rate for bank charges. */
    public function marginInr(): float
    {
        return (float) data_get($this->org->settings, 'fx.margin_inr', 2);
    }

    /**
     * The market INR rate for one unit of the currency, or null offline.
     *
     * Not Cache::remember(), and the difference matters. remember() stores
     * whatever the closure returns — including the null a failed fetch
     * produces — so a single blip, an expired certificate or a minute of bad
     * network blanked the rate for the next six hours. Every invoice raised
     * in that window went out without its INR equivalent, and nothing in the
     * app looked broken: the cache was doing exactly what it was told.
     *
     * So only a real rate is ever written. A failure falls back to the last
     * one that worked, kept for a month under a second key, because a rate
     * from this morning is a far better answer than no rate at all — the
     * upstream only publishes once a day in any case. Null is reserved for
     * the one honest case: a currency this app has never successfully priced.
     */
    public function marketRate(string $currency): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'INR') {
            return 1.0;
        }

        $key = 'fx-inr-' . $currency;
        $lastGood = 'fx-inr-last-' . $currency;

        $cached = Cache::get($key);
        if ($cached !== null) {
            return (float) $cached;
        }

        $rate = $this->fetchRate($currency);

        if ($rate !== null) {
            Cache::put($key, $rate, now()->addHours(6));
            Cache::put($lastGood, $rate, now()->addDays(30));

            return $rate;
        }

        $stale = Cache::get($lastGood);

        return $stale !== null ? (float) $stale : null;
    }

    /** One call upstream. Null for anything that is not a usable number. */
    private function fetchRate(string $currency): ?float
    {
        try {
            $rate = Http::timeout(8)
                ->get('https://open.er-api.com/v6/latest/' . $currency)
                ->json('rates.INR');

            return $rate ? round((float) $rate, 4) : null;
        } catch (\Throwable) {
            // Offline, timed out, or a certificate the box cannot verify.
            // The caller decides what to show; this only reports failure.
            return null;
        }
    }

    /**
     * Below this, a half-rupee step is not a rounding but a mangling.
     *
     * The eight currencies the screen offers are all worth twenty-five rupees
     * or more, so this never fires for any of them. It exists because the
     * field accepts any three letters: a yen is worth about sixty paise, and
     * rounding that down to the nearest half rupee would take a fifth of it —
     * or, once the flat margin had come off, all of it.
     */
    private const ROUND_ABOVE = 10.0;

    /**
     * Market rate less the margin, rounded down to the nearest half rupee.
     *
     * A rate carried to four decimals is a false precision: nobody quotes a
     * client at ₹92.8924 to the dollar, and every figure derived from it
     * inherits a tail that has to be explained. Rounded, the rate is a number
     * somebody can repeat over the phone and arrive at the same total.
     *
     * Down rather than to the nearest, and that is the deliberate part. This
     * rate exists because the bank takes a cut on conversion; erring upwards
     * would mean quoting a rate the money will not actually arrive at, so the
     * half-rupee goes the same way the margin does. ₹94.8924 less a ₹2 margin
     * is ₹92.8924, and the invoice converts at ₹92.50.
     */
    public function effectiveRate(string $currency): ?float
    {
        if (strtoupper($currency) === 'INR') {
            return 1.0;
        }

        $market = $this->marketRate($currency);
        if ($market === null) {
            return null;
        }

        $net = max(0, $market - $this->marginInr());

        return $net >= self::ROUND_ABOVE
            ? floor($net * 2) / 2
            : round($net, 4);
    }
}
