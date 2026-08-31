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

    /** The market INR rate for one unit of the currency, or null offline. */
    public function marketRate(string $currency): ?float
    {
        $currency = strtoupper($currency);
        if ($currency === 'INR') {
            return 1.0;
        }

        return Cache::remember('fx-inr-' . $currency, now()->addHours(6), function () use ($currency) {
            try {
                $response = Http::timeout(8)->get('https://open.er-api.com/v6/latest/' . $currency);
                $rate = $response->json('rates.INR');

                return $rate ? round((float) $rate, 4) : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** Market rate less the margin — what the invoice converts at. */
    public function effectiveRate(string $currency): ?float
    {
        $market = $this->marketRate($currency);
        if ($market === null) {
            return null;
        }
        if (strtoupper($currency) === 'INR') {
            return 1.0;
        }

        return round(max(0, $market - $this->marginInr()), 4);
    }
}
