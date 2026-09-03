<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Keeping scripts off the sign-up form.
 *
 * Three layers: a field nobody can see, a clock, and Cloudflare. The first
 * two are always on; the third applies once a key is configured, so the app
 * is protected today and better protected the moment somebody adds one.
 */
class SignupGuardTest extends TestCase
{
    use RefreshDatabase;

    /** What HaveIBeenPwned answers with. Empty means "not in any breach". */
    private string $breachList = '';

    /** This class checks the real rule, through the fake below it. */
    protected bool $stubBreachCheck = false;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Sign-up is throttled at three a minute, which is right in
         * production and wrong here: a dozen tests each registering would
         * start failing on the fourth for a reason none of them is about.
         * The throttle has its own coverage elsewhere.
         */
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        /*
         * The password rule reaches out to HaveIBeenPwned. Faked so the suite
         * neither depends on the network nor leaks a hash prefix from a test
         * run — reading a property rather than a fixed body, because Http
         * stubs are matched in the order they are registered and a second
         * fake() in a test would never be reached.
         */
        Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->breachList)]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'New Person',
            'username' => 'newperson',
            'email' => 'new.person@netvork.test',
            'password' => 'Tr0ubador-Horse-Battery-91',
            'password_confirmation' => 'Tr0ubador-Horse-Battery-91',
        ], $extra);
    }

    private function register(array $extra = [])
    {
        return $this->postJson('/api/v1/auth/register', $this->payload($extra));
    }

    public function test_a_person_can_still_sign_up(): void
    {
        $this->register()->assertCreated();
        $this->assertDatabaseHas('users', ['username' => 'newperson']);
    }

    /** The field nobody can see, that a form-filler fills anyway. */
    public function test_a_filled_honeypot_is_refused(): void
    {
        $this->register(['company_website' => 'http://spam.example'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['username' => 'newperson']);
    }

    /** Nobody reads a form, picks a username and types a password in a second. */
    public function test_a_form_submitted_impossibly_fast_is_refused(): void
    {
        $this->register(['form_started_at' => microtime(true) * 1000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['username' => 'newperson']);
    }

    public function test_a_form_filled_at_human_speed_is_allowed(): void
    {
        $this->register(['form_started_at' => (microtime(true) - 30) * 1000])
            ->assertCreated();
    }

    /**
     * A form left open for an hour is somebody who got distracted.
     *
     * Only a suspiciously short time is refused; refusing a long one would
     * punish the most human behaviour on the page.
     */
    public function test_a_form_left_open_a_long_time_is_allowed(): void
    {
        $this->register(['form_started_at' => (microtime(true) - 7200) * 1000])
            ->assertCreated();
    }

    /** A missing clock is not evidence of anything. The other layers hold. */
    public function test_a_missing_timestamp_does_not_lock_anybody_out(): void
    {
        $this->register()->assertCreated();
    }

    public function test_turnstile_is_skipped_when_no_key_is_configured(): void
    {
        config(['mypa.signup_guard.turnstile_secret' => null]);

        $this->register()->assertCreated();

        // Not merely allowed: never asked, so a missing key costs no latency.
        // Cloudflare specifically — the password rule makes its own call.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'challenges.cloudflare.com'));
    }

    public function test_a_missing_turnstile_token_is_refused_once_a_key_exists(): void
    {
        config(['mypa.signup_guard.turnstile_secret' => 'a-secret']);

        $this->register()->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_token_cloudflare_rejects_is_refused(): void
    {
        config(['mypa.signup_guard.turnstile_secret' => 'a-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->register(['turnstile_token' => 'a-bad-token'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_token_cloudflare_accepts_is_allowed(): void
    {
        config(['mypa.signup_guard.turnstile_secret' => 'a-secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->register(['turnstile_token' => 'a-good-token'])->assertCreated();
    }

    /**
     * Cloudflare being down must not close the door.
     *
     * A verification service that is unreachable would otherwise stop every
     * real sign-up while stopping no attacker already past the other layers.
     */
    public function test_cloudflare_being_unreachable_fails_open(): void
    {
        config(['mypa.signup_guard.turnstile_secret' => 'a-secret']);
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $this->register(['turnstile_token' => 'a-token'])->assertCreated();
    }

    /** Every refusal reads the same: naming the layer is naming the fix. */
    public function test_the_refusal_never_says_which_layer_objected(): void
    {
        $honeypot = $this->register(['company_website' => 'x'])->json('errors.email.0');

        $fast = $this->postJson('/api/v1/auth/register', $this->payload([
            'username' => 'another', 'email' => 'another@netvork.test',
            'form_started_at' => microtime(true) * 1000,
        ]))->json('errors.email.0');

        $this->assertSame($honeypot, $fast);
    }

    /** A password already in a public breach list is the first one tried. */
    public function test_a_breached_password_is_refused(): void
    {
        // The suffix of sha1('password123'), as the range endpoint returns it.
        $this->breachList = 'C6008F9CAB4083784CBD1874F76618D2A97:19999';

        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_the_booking_form_is_guarded_too(): void
    {
        $host = User::factory()->create();
        $host->profile()->create(['timezone' => 'UTC']);
        $host->settings()->create([]);

        $page = \App\Models\BookingPage::create([
            'user_id' => $host->id, 'slug' => 'hostlink', 'duration_minutes' => 30, 'is_active' => true,
        ]);
        \App\Models\BookingHour::create([
            'booking_page_id' => $page->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00',
        ]);

        $this->postJson('/api/v1/book/hostlink', [
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'name' => 'A Script',
            'email' => 'script@example.com',
            'company_website' => 'http://spam.example',
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }
}
