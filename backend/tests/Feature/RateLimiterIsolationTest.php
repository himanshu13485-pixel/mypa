<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A tight limit must count only its own requests.
 *
 * `throttle:20,1` reads as "twenty of these a minute". It is not: Laravel
 * keys an inline throttle on the user alone, so every inline throttle a
 * request passes shares one counter — and the API group spends that counter
 * on 180 ordinary requests a minute. A tighter inline limit inside it
 * therefore refuses as soon as the shared count passes its own number,
 * whatever the request was.
 *
 * It showed as "Too Many Attempts" on the first click of Call from a CRM
 * screen, the page's own polling having already spent twenty requests. The
 * same fault sat under the master key reset, which would have refused after
 * ten API calls of any kind in an hour.
 *
 * Named limiters are keyed on md5(name . key), so each has a counter of its
 * own. This test is what keeps somebody from writing throttle:5,1 in the
 * route file again and quietly capping an endpoint at five of everything.
 */
class RateLimiterIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->me = User::factory()->create();
        $this->me->profile()->create(['timezone' => 'UTC']);
        $this->me->settings()->create([]);
    }

    public function test_ordinary_traffic_does_not_spend_the_dial_allowance(): void
    {
        /*
         * Thirty unrelated calls first — comfortably past the dial route's
         * own limit of twenty, and the exact situation a CRM screen creates
         * for itself by polling before anybody clicks anything.
         */
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->me)->getJson('/api/v1/notifications')->assertOk();
        }

        $this->actingAs($this->me)
            ->postJson('/api/v1/dial', ['number' => '9876543210'])
            ->assertOk();
    }

    public function test_the_dial_limit_still_bites_on_dialling(): void
    {
        // Its own counter, and a real one: the twenty-first is refused.
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($this->me)
                ->postJson('/api/v1/dial', ['number' => '9876543210'])
                ->assertOk();
        }

        $this->actingAs($this->me)
            ->postJson('/api/v1/dial', ['number' => '9876543210'])
            ->assertStatus(429);
    }

    /**
     * And no route is left carrying the shape that caused it.
     *
     * An inline `throttle:n,m` inside the authenticated group is the bug —
     * it cannot help but share the group's counter. Anything that needs to
     * be tighter than the group belongs in a named limiter.
     */
    public function test_no_authenticated_route_carries_a_tighter_inline_throttle(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            // Only the routes that sit behind the shared API allowance.
            if (! in_array('throttle:180,1', $middleware, true)) {
                continue;
            }

            /*
             * A route may drop the group's throttle deliberately and put its
             * own back — presence does, because a heartbeat every forty-five
             * seconds from every open tab has no business spending the same
             * allowance as everything else. Having removed the shared
             * counter, its inline limit is its own and is fine.
             */
            if (in_array('throttle:180,1', $route->excludedMiddleware(), true)) {
                continue;
            }

            foreach ($middleware as $layer) {
                if (! preg_match('/^throttle:(\d+),(\d+)$/', (string) $layer, $m)) {
                    continue;
                }
                if ((int) $m[1] < 180) {
                    $offenders[] = $route->uri() . ' (' . $layer . ')';
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['These share the group counter and will refuse early. Use a named limiter:'],
            $offenders,
        )));
    }
}
