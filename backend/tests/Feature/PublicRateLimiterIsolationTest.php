<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The same lesson as RateLimiterIsolationTest, on the door rather than inside.
 *
 * An inline `throttle:n,m` on a route nobody has signed in to is keyed on
 * sha1(domain|ip) - the address alone. Every public route therefore shared
 * one counter per visitor, and the tightest number on any of them decided
 * when all of them stopped answering.
 *
 * It showed as "Too many attempts" on somebody's first ever registration.
 * The sign-up form asks for a username suggestion while you type your name;
 * four of those, plus the group's own count, and the register route's
 * "three a minute" was already spent before the person pressed the button.
 * Nothing about it was their third attempt. It was their first.
 */
class PublicRateLimiterIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // The password check reaches out; nothing here is about that.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    }

    /** @param array<string, mixed> $overrides */
    private function signUp(array $overrides = [])
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Himanshu Arora',
            'email' => 'himanshu@example.com',
            'username' => 'himanshu01',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'timezone' => 'Asia/Kolkata',
        ], $overrides));
    }

    public function test_filling_in_the_form_does_not_spend_the_sign_up_allowance(): void
    {
        // What typing a name into the form actually does: one suggestion
        // lookup per pause, then a check of the username itself.
        foreach (['Him', 'Himan', 'Himanshu', 'Himanshu Arora'] as $typed) {
            $this->getJson('/api/v1/auth/suggest-username?name=' . urlencode($typed))->assertOk();
        }
        $this->getJson('/api/v1/auth/suggest-username?username=himanshu01')->assertOk();

        // And then they press the button, for the first time.
        $this->signUp()->assertCreated();
    }

    public function test_a_mistyped_form_does_not_lock_somebody_out_of_signing_up(): void
    {
        // Three rejections is an ordinary sign-up: a username already taken,
        // a password too short, a confirmation that did not match. None of
        // them created an account, so none of them was an attempt at
        // anything - and the next try has to be allowed to work.
        $this->signUp(['username' => 'no'])->assertUnprocessable();
        $this->signUp(['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
        $this->signUp(['password_confirmation' => 'Password124'])->assertUnprocessable();

        $this->signUp()->assertCreated();
    }

    public function test_signing_up_in_bulk_is_still_refused(): void
    {
        // Its own counter, and a real one.
        for ($i = 0; $i < 5; $i++) {
            $this->signUp([
                'email' => "person{$i}@example.com",
                'username' => "person{$i}x",
            ])->assertCreated();
        }

        $this->signUp(['email' => 'person9@example.com', 'username' => 'person9x'])
            ->assertStatus(429);
    }

    /**
     * And no public route is left carrying the shape that caused it.
     *
     * Inside the signed-in group an inline throttle at least shares with
     * that one person's own traffic. Out here it is keyed on the address, so
     * an office behind one NAT, or anyone at all behind a proxy, shares it
     * with strangers. Everything public belongs in a named limiter.
     */
    public function test_no_public_route_carries_an_inline_throttle(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            // The signed-in group is the other test's ground, and its own
            // blanket throttle is the one inline limit that is allowed.
            if (in_array('throttle:180,1', $middleware, true)) {
                continue;
            }

            foreach ($middleware as $layer) {
                if (preg_match('/^throttle:\d+,\d+$/', (string) $layer)) {
                    $offenders[] = $route->uri() . ' (' . $layer . ')';
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), implode("\n", array_merge(
            ['These share one counter per visitor and will refuse early. Use a named limiter:'],
            array_unique($offenders),
        )));
    }
}
