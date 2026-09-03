<?php

namespace Tests;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Registration's ->uncompromised() rule, answered locally.
     *
     * That rule asks haveibeenpwned.com whether the chosen password appears in
     * a breach corpus, which is exactly right in production and wrong in a
     * test: it puts a network call in the middle of every sign-up assertion,
     * makes the suite fail on a train, and adds a second or so to each one.
     *
     * It also hid itself for as long as it has existed. Laravel's verifier
     * fails OPEN — a password it cannot check is treated as uncompromised —
     * and the PHP on the machine this was written on had a broken CA path, so
     * every one of these calls errored and every test passed without the rule
     * ever running. Repairing the certificate path is what made five tests
     * start failing, on passwords like Password123 that are of course in the
     * corpus. They were not passing because they were right.
     *
     * So the verifier is answered here instead. Production keeps the real one;
     * the tests keep their obvious passwords and stop depending on somebody
     * else's uptime for a result that has nothing to do with what they assert.
     */
    /**
     * Off for the tests that mean to exercise the real check.
     *
     * SignupGuardTest fakes the range endpoint itself, with a body it changes
     * per test — so it needs the genuine verifier to reach that fake. Stubbing
     * the container underneath it would bypass the HTTP layer entirely and
     * quietly turn its assertions into nothing.
     */
    protected bool $stubBreachCheck = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->stubBreachCheck) {
            return;
        }

        $this->app->bind(UncompromisedVerifier::class, fn () => new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return true;
            }
        });
    }
}
