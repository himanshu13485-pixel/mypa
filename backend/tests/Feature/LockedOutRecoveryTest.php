<?php

namespace Tests\Feature;

use App\Models\MobileOtp;
use App\Models\User;
use App\Notifications\SignInCodeNotification;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Getting back in when you are locked out.
 *
 * Both of these are read by somebody who cannot sign in, which rules out
 * every delivery route that requires being signed in.
 */
class LockedOutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'locked.out@netvork.test',
            'username' => 'lockedout',
            'mobile' => '+919800000001',
            'email_verified_at' => now(),
        ]);
        $this->user->profile()->create(['timezone' => 'UTC']);
        $this->user->settings()->create([]);
    }

    /**
     * The bug this test exists for.
     *
     * "Sign in with a code" used to deliver only to the database and the
     * broadcast channel - the in-app bell. You have to be signed in to read
     * the bell, and everybody pressing that button is by definition not.
     */
    public function test_a_sign_in_code_is_emailed_not_only_belled(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'locked.out@netvork.test'])
            ->assertOk();

        $this->assertSame(1, MobileOtp::where('purpose', 'login')->count());

        /*
         * 'mail' has to be among the channels. It is the whole point: the
         * previous notification carried ['database', 'broadcast'] and nothing
         * else, so the code reached only the in-app bell.
         */
        Notification::assertSentTo(
            $this->user,
            SignInCodeNotification::class,
            fn ($notification, array $channels) => in_array('mail', $channels, true),
        );
    }

    public function test_the_emailed_code_actually_signs_you_in(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'locked.out@netvork.test'])
            ->assertOk();

        $code = MobileOtp::where('purpose', 'login')->whereNull('consumed_at')->latest('id')->value('code');

        $this->postJson('/api/v1/auth/otp/login', [
            'identifier' => 'locked.out@netvork.test',
            'code' => $code,
            'device_name' => 'test',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    /** A username works as the identifier too, not only an email. */
    public function test_a_username_is_an_identifier(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/otp/request', ['identifier' => $this->user->username])
            ->assertOk();

        $this->assertSame(1, MobileOtp::where('purpose', 'login')->count());
    }

    /** The response must not say whether the account exists. */
    public function test_an_unknown_identifier_answers_the_same_way(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'locked.out@netvork.test']);
        $unknown = $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'nobody@netvork.test']);

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(1, MobileOtp::where('purpose', 'login')->count());
    }

    /** Three codes per quarter hour, so an inbox cannot be flooded. */
    public function test_the_per_account_cap_still_holds(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'locked.out@netvork.test'])
                ->assertOk();
        }

        $this->assertSame(3, MobileOtp::where('purpose', 'login')->count());
    }

    public function test_a_password_reset_link_is_emailed(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'locked.out@netvork.test'])
            ->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
    }

    public function test_forgot_password_does_not_leak_whether_the_email_exists(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'locked.out@netvork.test']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@netvork.test']);

        $this->assertSame($known->json('message'), $unknown->json('message'));
    }
}
