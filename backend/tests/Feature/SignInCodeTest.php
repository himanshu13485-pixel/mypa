<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\MobileOtp;
use App\Models\TrustedDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Signing in with a password and a code.
 *
 * The password says the right thing is known; the code says the right
 * person knows it. The device that answers a code is remembered, so this
 * is a check at the moment a new machine claims to be you rather than a
 * toll on every sign-in — which is the version people keep switched on.
 */
class SignInCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create([
            'email' => 'harsh@grapmail.test',
            'username' => 'harshgrapout',
            'password' => 'Password123',
            'email_verified_at' => now(),
        ]);
        $this->user->settings()->create([]);
        $this->user->profile()->create(['timezone' => 'Asia/Kolkata']);
    }

    private function login(array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($headers)->postJson('/api/v1/auth/login', [
            'identifier' => 'harshgrapout',
            'password' => 'Password123',
        ]);
    }

    public function test_a_new_device_is_asked_for_a_code_before_it_gets_a_token(): void
    {
        Notification::fake();

        $res = $this->login()->assertStatus(202);

        // No token yet: the password alone has not signed anybody in.
        $this->assertNull($res->json('token'));
        $this->assertTrue($res->json('otp_required'));

        // The address is named enough to recognise, not enough to attack.
        $this->assertSame('h***h@grapmail.test', $res->json('sent_to'));

        Notification::assertSentTo($this->user, \App\Notifications\SignInCodeNotification::class);
    }

    public function test_the_code_completes_the_sign_in_and_the_device_is_remembered(): void
    {
        $this->login()->assertStatus(202);
        $code = MobileOtp::where('user_id', $this->user->id)->where('purpose', 'login')->value('code');

        $done = $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => $code,
        ])->assertOk();

        $this->assertNotNull($done->json('token'));
        $deviceToken = $done->json('device_token');
        $this->assertNotNull($deviceToken);

        // Stored as a hash — a copy of the table unlocks nobody.
        $this->assertDatabaseMissing('trusted_devices', ['token_hash' => $deviceToken]);
        $this->assertNotNull(TrustedDevice::findLive($this->user, $deviceToken));

        // Signing in again from that device is one step, as promised.
        $this->login(['X-Device-Token' => $deviceToken])
            ->assertOk()
            ->assertJsonStructure(['token']);

        // A different machine is asked all over again.
        $this->login(['X-Device-Token' => 'not-a-real-token'])->assertStatus(202);
    }

    public function test_a_wrong_or_stale_code_signs_nobody_in(): void
    {
        $this->login()->assertStatus(202);

        $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => '000000',
        ])->assertStatus(422);

        // The real code, after it has expired, is no better.
        $otp = MobileOtp::where('user_id', $this->user->id)->where('purpose', 'login')->first();
        $otp->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => $otp->code,
        ])->assertStatus(422);
    }

    public function test_a_code_that_has_run_out_can_be_replaced_without_starting_over(): void
    {
        Notification::fake();

        $this->login()->assertStatus(202);
        $first = MobileOtp::where('user_id', $this->user->id)->where('purpose', 'login')->firstOrFail();

        // The code lasts ten minutes and somebody came back after lunch.
        // Asking again is the same call: the password is what earns a code,
        // which is why there is no endpoint that will post one to whoever is
        // named — that would be a way to pester anybody by e-mail address.
        $this->travel(11)->minutes();
        $again = $this->login()->assertStatus(202);
        $this->assertTrue($again->json('otp_required'));

        $second = MobileOtp::where('user_id', $this->user->id)
            ->where('purpose', 'login')
            ->whereNull('consumed_at')
            ->firstOrFail();

        $this->assertNotSame($first->id, $second->id);

        // Exactly one live code at a time: the old one is retired as the new
        // one is issued, so a queue of codes cannot build up behind a slow
        // mail server with any of them still working.
        $this->assertNotNull($first->fresh()->consumed_at);
        $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => $first->code,
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => $second->code,
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_local_bypass_code_only_works_in_the_local_environment(): void
    {
        $this->login()->assertStatus(202);

        // The suite runs as APP_ENV=testing, so this must fail exactly like
        // any other wrong guess — the bypass has no effect here at all.
        $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => '123456',
        ])->assertStatus(422);

        // Forced to 'local' for one request: now it works, and it consumes
        // the real row rather than inventing a session out of nothing — a
        // second attempt with the same bypass code finds no active code left.
        app()->detectEnvironment(fn () => 'local');
        try {
            $this->postJson('/api/v1/auth/login/verify', [
                'identifier' => 'harshgrapout', 'code' => '123456',
            ])->assertOk()->assertJsonStructure(['token']);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_a_shared_computer_can_refuse_to_be_remembered(): void
    {
        $this->login()->assertStatus(202);
        $code = MobileOtp::where('user_id', $this->user->id)->where('purpose', 'login')->value('code');

        $done = $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => $code, 'remember_device' => false,
        ])->assertOk();

        $this->assertNull($done->json('device_token'));
        $this->assertSame(0, TrustedDevice::where('user_id', $this->user->id)->count());
    }

    public function test_the_wrong_password_never_reaches_the_code_step(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'harshgrapout', 'password' => 'not-the-password',
        ])->assertStatus(422);

        // Nothing sent: a code to the real owner every time somebody guesses
        // would be a way to pester them, and tells the guesser the account exists.
        Notification::assertNothingSent();
    }

    public function test_the_company_can_ask_every_time_or_not_at_all(): void
    {
        AppSetting::set('login_otp_mode', 'off');
        $this->login()->assertOk()->assertJsonStructure(['token']);

        AppSetting::set('login_otp_mode', 'always');
        $this->login()->assertStatus(202);
        $code = MobileOtp::where('user_id', $this->user->id)->where('purpose', 'login')->value('code');
        $done = $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => 'harshgrapout', 'code' => $code,
        ])->assertOk();

        // Even a remembered device is asked again while 'always' is set.
        $this->login(['X-Device-Token' => $done->json('device_token')])->assertStatus(202);
    }

    public function test_an_account_without_an_email_cannot_sign_in_at_all(): void
    {
        $orphan = User::factory()->create([
            'email' => null, 'username' => 'noaddress', 'password' => 'Password123',
        ]);
        $orphan->settings()->create([]);

        // The password is right, and it still does not open the door: an
        // account with nowhere to send a code cannot be secured or recovered.
        $refused = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'noaddress', 'password' => 'Password123',
        ])->assertStatus(422);
        $this->assertStringContainsString('no e-mail address', $refused->json('message'));

        // Nor by the passwordless door, which would have nowhere to send to.
        $this->postJson('/api/v1/auth/otp/login', [
            'identifier' => 'noaddress', 'code' => '123456',
        ])->assertStatus(422);

        // Given an address, the ordinary two-step sign-in works.
        $orphan->update(['email' => 'now@has.test']);
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'noaddress', 'password' => 'Password123',
        ])->assertStatus(202)->assertJsonPath('otp_required', true);
    }

    public function test_an_employee_hears_from_their_employer_not_the_platform(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        // Signed up on Netvork, not yet anybody's employee: the platform
        // sends the code, through its own mail channel.
        $this->assertNull(\App\Services\Crm\CompanyMailer::forStaff($this->user));

        // A company takes them on, and sets up its own mailbox.
        $org = \App\Models\Crm\Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $company = \App\Models\Crm\IssuingCompany::create([
            'organization_id' => $org->id, 'name' => 'Acme India', 'pays_salary' => true,
        ]);
        \App\Models\Crm\Member::create([
            'organization_id' => $org->id, 'user_id' => $this->user->id, 'crm_role' => 'employee',
        ]);
        $org->update(['settings' => ['communication' => [
            'from_name' => 'Acme', 'from_address' => 'hr@acme.test',
            'company_senders' => [(string) $company->id => [
                'from_name' => 'Acme People', 'from_address' => 'people@acme.test',
                'mailer' => 'smtp', 'smtp_host' => 'smtp.acme.test', 'smtp_port' => 587,
            ]],
        ]]]);

        // Now their mail comes from the company that employs them.
        $mailbox = \App\Services\Crm\CompanyMailer::forStaff($this->user->fresh());
        $this->assertSame('people@acme.test', $mailbox['address']);
        $this->assertSame('Acme People', $mailbox['name']);

        // A company that has switched its own mail off does not switch off
        // its employees sign-in codes - the platform sends those.
        $settings = $org->fresh()->settings;
        $settings['communication']['email_enabled'] = false;
        $org->update(['settings' => $settings]);
        $this->assertNull(\App\Services\Crm\CompanyMailer::forStaff($this->user->fresh()));
    }

    public function test_the_report_sender_decides_which_mailbox_staff_hear_from(): void
    {
        $org = \App\Models\Crm\Organization::create(['name' => 'Group Ltd', 'code' => 'GRP']);
        $payroll = \App\Models\Crm\IssuingCompany::create([
            'organization_id' => $org->id, 'name' => 'Group Payroll', 'pays_salary' => true,
        ]);
        $head = \App\Models\Crm\IssuingCompany::create([
            'organization_id' => $org->id, 'name' => 'Group Head Office',
        ]);
        \App\Models\Crm\Member::create([
            'organization_id' => $org->id, 'user_id' => $this->user->id, 'crm_role' => 'employee',
        ]);

        $senders = [
            (string) $payroll->id => ['from_address' => 'payroll@group.test', 'mailer' => 'smtp', 'smtp_host' => 'smtp.group.test'],
            (string) $head->id => ['from_address' => 'admin@group.test', 'mailer' => 'smtp', 'smtp_host' => 'smtp.group.test'],
        ];
        $org->update(['settings' => ['communication' => ['company_senders' => $senders]]]);

        // With nothing marked, the company that pays the salaries answers.
        $this->assertSame('payroll@group.test',
            \App\Services\Crm\CompanyMailer::forStaff($this->user->fresh())['address']);

        // Marked, the Admin's own choice wins over that inference.
        $senders[(string) $head->id]['is_report_sender'] = true;
        $org->update(['settings' => ['communication' => ['company_senders' => $senders]]]);
        $this->assertSame('admin@group.test',
            \App\Services\Crm\CompanyMailer::forStaff($this->user->fresh())['address']);
    }
}
