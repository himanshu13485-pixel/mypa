<?php

namespace Tests\Feature;

use App\Models\Crm\Organization;
use App\Services\Crm\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a foreign-currency invoice converts at.
 *
 * Two decisions live in this service and both are about being wrong in the
 * right direction. The margin comes off because the bank takes a cut on
 * conversion, and the half-rupee goes the same way: a rate quoted higher than
 * the money will actually arrive at is a rate that costs the company on every
 * invoice raised against it.
 */
class CrmFxRateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    /** What the upstream is currently answering; null means unreachable. */
    private ?float $upstreamRate = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        /*
         * One stub, reading a property, registered once.
         *
         * Http::fake() pushes onto the stub list rather than replacing it, and
         * the first pattern that matches wins — so calling it a second time
         * inside a test looks like changing the answer and quietly does not.
         * A single closure over mutable state is the honest way to say "the
         * rate moved" or "the network went away" halfway through.
         */
        Http::fake(function () {
            if ($this->upstreamRate === null) {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 77');
            }

            return Http::response(['result' => 'success', 'rates' => ['INR' => $this->upstreamRate]]);
        });
    }

    private function upstream(?float $inr): void
    {
        $this->upstreamRate = $inr;
    }

    private function fx(float $margin = 2): FxService
    {
        $this->org->update(['settings' => ['fx' => ['margin_inr' => $margin]]]);

        return new FxService($this->org->fresh());
    }

    public function test_the_rate_is_rounded_down_to_the_nearest_half_rupee(): void
    {
        // The live figure on the day this was written, and the worked example
        // from the screen: ₹94.8924 less a ₹2 margin is ₹92.8924 → ₹92.50.
        $this->upstream(94.892446);

        $this->assertSame(94.8924, $this->fx()->marketRate('USD'));
        $this->assertSame(92.5, $this->fx()->effectiveRate('USD'));
    }

    public function test_it_rounds_down_and_never_up(): void
    {
        /*
         * The direction is the point. To the nearest, 92.8924 would become
         * 93.00 — a rate above what the bank will actually pay, which loses
         * money on every invoice rather than protecting the margin the
         * setting exists to protect.
         */
        $this->upstream(96.99);
        $this->assertSame(94.5, $this->fx()->effectiveRate('USD'));

        Cache::flush();
        $this->upstream(96.00);
        $this->assertSame(94.0, $this->fx()->effectiveRate('USD'), 'an exact half stays put');
    }

    public function test_a_currency_worth_pennies_is_not_rounded_into_nothing(): void
    {
        // A yen is worth about sixty paise. Rounding that down to the nearest
        // half rupee takes a fifth of it, and the flat margin takes the rest.
        $this->upstream(0.63);

        $this->assertSame(0.0, $this->fx()->effectiveRate('JPY'), 'the ₹2 margin already swallows it');

        Cache::flush();
        $this->upstream(0.63);
        $this->assertSame(0.53, $this->fx(0.1)->effectiveRate('JPY'), 'and what is left is not half-rupeed away');
    }

    public function test_rupees_convert_at_one(): void
    {
        $this->assertSame(1.0, $this->fx()->effectiveRate('INR'));
        Http::assertNothingSent();
    }

    public function test_a_failed_fetch_is_not_cached_as_no_rate(): void
    {
        /*
         * The bug this replaced. Cache::remember() stores whatever the closure
         * returns, null included — so one timeout, or a certificate the box
         * could not verify, blanked the rate for six hours. Every invoice
         * raised in that window went out with no INR equivalent, and nothing
         * looked broken: the cache was doing as it was told.
         */
        $this->upstream(null);
        $this->assertNull($this->fx()->marketRate('USD'));

        // The very next call tries again rather than serving a cached nothing.
        $this->upstream(94.892446);
        $this->assertSame(92.5, $this->fx()->effectiveRate('USD'));
    }

    public function test_a_rate_that_worked_this_morning_beats_no_rate_at_all(): void
    {
        $this->upstream(94.892446);
        $this->assertSame(92.5, $this->fx()->effectiveRate('USD'));

        // Six hours on, the upstream is unreachable. The document still needs
        // a number, and this morning's is a far better answer than none —
        // the upstream only publishes once a day in any case.
        Cache::forget('fx-inr-USD');
        $this->upstream(null);

        $this->assertSame(92.5, $this->fx()->effectiveRate('USD'));
    }
}
