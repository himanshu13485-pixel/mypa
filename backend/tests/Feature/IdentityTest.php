<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Call;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    protected function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Asha Kumar',
            'email' => 'asha@example.com',
            'username' => 'ashak',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides));
    }

    // --- Registration & OTP ---------------------------------------------------

    public function test_registration_issues_email_otp_and_verifies(): void
    {
        $response = $this->register(['mobile' => '+919876543210']);
        $response->assertCreated()->assertJsonPath('email_verification_pending', true);

        $user = User::where('username', 'ashak')->first();
        $this->assertEquals('asha@example.com', $user->email);
        $this->assertEquals('+919876543210', $user->mobile); // records only
        $this->assertNull($user->email_verified_at);

        // The code is emailed (log mailer in tests); read it from storage.
        $code = \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'verify_email')->latest()->first()->code;

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verify-otp', ['code' => '000000'])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verify-otp', ['code' => $code])
            ->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_registration_requires_email_and_password(): void
    {
        $this->register(['email' => null])->assertUnprocessable();
        $this->register(['password' => null, 'password_confirmation' => null])->assertUnprocessable();
    }

    public function test_username_rules_and_uniqueness(): void
    {
        $this->register(['username' => 'ab'])->assertUnprocessable();          // too short
        $this->register(['username' => 'has space'])->assertUnprocessable();   // invalid chars
        $this->register(['username' => 'special_char'])->assertUnprocessable();

        $this->register()->assertCreated();
        // Same username (different case), different email → rejected.
        $this->register(['username' => 'AshaK', 'email' => 'other@example.com'])
            ->assertUnprocessable();
        // Same email → rejected.
        $this->register(['username' => 'someoneelse', 'email' => 'asha@example.com'])
            ->assertUnprocessable();
        // Duplicate mobile is fine — it's a records-only field now.
        $this->register([
            'username' => 'someoneelse', 'email' => 'other@example.com', 'mobile' => '+911111111111',
        ])->assertCreated();
        $this->register([
            'username' => 'thirduser', 'email' => 'third@example.com', 'mobile' => '+911111111111',
        ])->assertCreated();
    }

    public function test_login_by_username_and_email_but_not_mobile(): void
    {
        $this->register(['mobile' => '+919876543210'])->assertCreated();

        foreach (['ashak', 'ASHAK', 'asha@example.com'] as $identifier) {
            $this->postJson('/api/v1/auth/login', [
                'identifier' => $identifier,
                'password' => 'Password123',
            ])->assertOk();
        }

        // Mobile is records-only: it is NOT a login identity.
        $this->postJson('/api/v1/auth/login', [
            'identifier' => '+919876543210',
            'password' => 'Password123',
        ])->assertUnprocessable();

        // Legacy email field still works.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'asha@example.com',
            'password' => 'Password123',
        ])->assertOk();
    }

    public function test_search_by_username_but_never_by_mobile(): void
    {
        $this->register(['mobile' => '+919876543210'])->assertCreated();

        $viewer = User::factory()->create();
        $viewer->settings()->create([]);

        $this->actingAs($viewer)
            ->getJson('/api/v1/app-id/search?q=ashak')
            ->assertOk()
            ->assertJsonPath('data.username', 'ashak');

        // Mobile numbers are records-only — searching one finds nothing.
        $this->actingAs($viewer)
            ->getJson('/api/v1/app-id/search?q=%2B919876543210')
            ->assertNotFound();

        $this->actingAs($viewer)
            ->postJson('/api/v1/connections', ['app_id' => 'ashak'])
            ->assertCreated();
    }

    public function test_registration_accepts_legacy_timezone_identifiers(): void
    {
        // Windows browsers report backward-compat zones like Asia/Calcutta.
        $this->register(['timezone' => 'Asia/Calcutta'])->assertCreated();
    }

    public function test_username_suggestion_derives_from_name_and_dedupes(): void
    {
        $first = $this->getJson('/api/v1/auth/suggest-username?name=' . urlencode('Himanshu Sachdeva'));
        $first->assertOk()->assertJsonPath('data.suggestion', 'himanshusachdeva');

        // Take the suggestion, then the next person with the same name gets a suffix.
        $this->register(['username' => 'himanshusachdeva', 'mobile' => '9812340001'])->assertCreated();

        $second = $this->getJson('/api/v1/auth/suggest-username?name=' . urlencode('Himanshu Sachdeva'));
        $second->assertOk()->assertJsonPath('data.suggestion', 'himanshusachdeva1');

        // Availability check endpoint.
        $this->getJson('/api/v1/auth/suggest-username?username=himanshusachdeva')
            ->assertOk()->assertJsonPath('data.available', false);
        $this->getJson('/api/v1/auth/suggest-username?username=freshname9')
            ->assertOk()->assertJsonPath('data.available', true);
        $this->getJson('/api/v1/auth/suggest-username?username=bad_name!')
            ->assertOk()->assertJsonPath('data.valid', false);
    }

    public function test_email_change_via_otp_after_approval(): void
    {
        $this->register()->assertCreated();
        $user = User::where('username', 'ashak')->first();
        $this->assertEquals('asha@example.com', $user->email);

        // User requests changing the email from the profile.
        $this->actingAs($user)->postJson('/api/v1/me/change-requests', [
            'type' => 'email', 'new_value' => 'asha.new@example.com',
        ])->assertCreated();

        $admin = $this->makeApprover('admin');
        $uuid = $this->actingAs($admin)->getJson('/api/v1/admin/change-requests')->json('data.0.uuid');
        $this->actingAs($admin)->postJson("/api/v1/admin/change-requests/{$uuid}", ['action' => 'approve'])->assertOk();

        // Approval alone does NOT switch the email — the OTP mailed to the NEW
        // address must be entered first.
        $this->assertEquals('asha@example.com', $user->fresh()->email);

        $otp = \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'verify_email')->whereNull('consumed_at')->latest()->first();
        $this->assertEquals('asha.new@example.com', $otp->mobile);

        // Wrong code rejected; right code activates + allows email login.
        $this->actingAs($user)->postJson('/api/v1/auth/email/verify-otp', ['code' => '000000'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/api/v1/auth/email/verify-otp', ['code' => $otp->code])->assertOk();

        $fresh = $user->fresh();
        $this->assertEquals('asha.new@example.com', $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'asha.new@example.com', 'password' => 'Password123',
        ])->assertOk();
    }

    // --- Passwordless accounts & OTP login ------------------------------------

    protected function registerSecond(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Kiran Rao',
            'email' => 'kiran@example.com',
            'username' => 'kiranrao',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ], $overrides));
    }

    public function test_otp_login_still_available_alongside_password(): void
    {
        $this->registerSecond()->assertCreated();
        $user = User::where('username', 'kiranrao')->first();

        // Unknown identifiers get a uniform response (no account enumeration).
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'ghostuser'])->assertOk();

        // OTP login: request → code lands in the app inbox → exchange for a token.
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'kiranrao'])->assertOk();

        $code = \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'login')->whereNull('consumed_at')->latest()->first()->code;

        $this->postJson('/api/v1/auth/otp/login', [
            'identifier' => 'kiranrao', 'code' => '000000',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/otp/login', [
            'identifier' => 'kiranrao', 'code' => $code,
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_mobile_is_editable_directly_in_profile(): void
    {
        $this->registerSecond()->assertCreated();
        $user = User::where('username', 'kiranrao')->first();

        // Records-only: direct profile update, no approval, no OTP.
        $this->actingAs($user)->putJson('/api/v1/me/profile', [
            'mobile' => '+918888877777',
        ])->assertOk();
        $this->assertEquals('+918888877777', $user->fresh()->mobile);

        // Mobile change requests are no longer a thing.
        $this->actingAs($user)->postJson('/api/v1/me/change-requests', [
            'type' => 'mobile', 'new_value' => '9000011111',
        ])->assertUnprocessable();
    }

    // --- Change requests ------------------------------------------------------

    protected function makeApprover(string $role = 'subadmin'): User
    {
        $approver = User::factory()->create();
        $approver->roles()->attach(Role::where('slug', $role)->first()->id);

        return $approver;
    }

    public function test_username_change_needs_cooldown_and_approval(): void
    {
        $this->register()->assertCreated();
        $user = User::where('username', 'ashak')->first();
        $user->update(['username_changed_at' => now()->subDays(5)]);
        AppSetting::set('username_change_days', '30');

        // Too soon → rejected client-side by API.
        $this->actingAs($user)->postJson('/api/v1/me/change-requests', [
            'type' => 'username', 'new_value' => 'ashanew',
        ])->assertUnprocessable();

        // Cooldown passed → request created as pending (username unchanged yet).
        $user->update(['username_changed_at' => now()->subDays(31)]);
        $this->actingAs($user)->postJson('/api/v1/me/change-requests', [
            'type' => 'username', 'new_value' => 'ashanew',
        ])->assertCreated();
        $this->assertEquals('ashak', $user->fresh()->username);

        // Duplicate pending request blocked.
        $this->actingAs($user)->postJson('/api/v1/me/change-requests', [
            'type' => 'username', 'new_value' => 'other',
        ])->assertConflict();

        // Subadmin approves → applied + cooldown restarts.
        $subadmin = $this->makeApprover('subadmin');
        $pending = $this->actingAs($subadmin)->getJson('/api/v1/admin/change-requests');
        $pending->assertOk();
        $uuid = $pending->json('data.0.uuid');

        $this->actingAs($subadmin)
            ->postJson("/api/v1/admin/change-requests/{$uuid}", ['action' => 'approve'])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertEquals('ashanew', $fresh->username);
        $this->assertTrue($fresh->username_changed_at->isToday());
        $this->assertDatabaseHas('audit_logs', ['action' => 'change_request.approved']);
    }

    public function test_rejection_does_not_apply_and_regular_user_cannot_review(): void
    {
        $this->register()->assertCreated();
        $user = User::where('username', 'ashak')->first();

        $this->actingAs($user)->postJson('/api/v1/me/change-requests', [
            'type' => 'email', 'new_value' => 'new@example.com',
        ])->assertCreated();

        // Regular user cannot access the review queue.
        $this->actingAs($user)->getJson('/api/v1/admin/change-requests')->assertForbidden();

        $admin = $this->makeApprover('admin');
        $uuid = $this->actingAs($admin)->getJson('/api/v1/admin/change-requests')->json('data.0.uuid');
        $this->actingAs($admin)->postJson("/api/v1/admin/change-requests/{$uuid}", [
            'action' => 'reject', 'note' => 'Suspicious',
        ])->assertOk();

        $this->assertEquals('asha@example.com', $user->fresh()->email);
    }

    public function test_admin_can_view_and_resend_otp_and_edit_settings(): void
    {
        $this->register()->assertCreated();
        $user = User::where('username', 'ashak')->first();
        $superAdmin = $this->makeApprover('super_admin');

        $view = $this->actingAs($superAdmin)->getJson("/api/v1/admin/users/{$user->uuid}/otp");
        $view->assertOk();
        $this->assertNotNull($view->json('data.code'));

        $this->actingAs($superAdmin)
            ->postJson("/api/v1/admin/users/{$user->uuid}/otp/resend")
            ->assertOk();

        $this->actingAs($superAdmin)
            ->putJson('/api/v1/admin/settings', ['username_change_days' => 45])
            ->assertOk();
        $this->assertEquals('45', AppSetting::get('username_change_days'));

        // Non-super-admin admin cannot edit settings.
        $admin = $this->makeApprover('admin');
        $this->actingAs($admin)
            ->putJson('/api/v1/admin/settings', ['username_change_days' => 10])
            ->assertForbidden();
    }

    public function test_otp_login_security_properties(): void
    {
        // App-level caps are under test here, not the HTTP rate limiter.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->register()->assertCreated();
        $user = User::where('username', 'ashak')->first();

        // 1. Requesting a code never returns it, and the response is uniform
        //    whether or not the account exists (no user enumeration).
        $real = $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'ashak']);
        $ghost = $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'nosuchuser999']);
        $real->assertOk();
        $ghost->assertOk();
        $this->assertEquals($real->json('message'), $ghost->json('message'));

        // 2. The code lands ONLY in the target account's own inbox.
        $this->assertTrue(
            $user->notifications()->get()->contains(fn ($n) => ($n->data['purpose'] ?? '') === 'login'),
        );

        // 3. Five wrong guesses kill the code — even the right code fails after.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/login', [
                'identifier' => 'ashak', 'code' => '000000',
            ])->assertUnprocessable();
        }
        $realCode = \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'login')->latest()->first()->code;
        $this->postJson('/api/v1/auth/otp/login', ['identifier' => 'ashak', 'code' => $realCode])
            ->assertUnprocessable();

        // 4. Per-account issue cap: at most 3 codes per 15 minutes.
        foreach (range(1, 4) as $i) {
            $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'ashak'])->assertOk();
        }
        $issued = \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'login')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();
        $this->assertEquals(3, $issued, 'The fourth+ code must not be issued');
    }

    // --- Sidebar badges -------------------------------------------------------

    public function test_badges_report_and_clear(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        foreach ([$alice, $bob] as $u) {
            $u->settings()->create([]);
        }

        // Unread message for Bob.
        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $alice->id]);
        $conversation->members()->attach([$alice->id, $bob->id]);
        $conversation->messages()->create(['user_id' => $alice->id, 'body' => 'Hi Bob']);

        // Missed call for Bob.
        $call = Call::create([
            'conversation_id' => $conversation->id,
            'caller_id' => $alice->id,
            'type' => 'audio',
            'status' => 'missed',
            'started_at' => now(),
        ]);
        $call->participants()->attach([
            $alice->id => ['status' => 'left', 'joined_at' => now()],
            $bob->id => ['status' => 'invited', 'joined_at' => null],
        ]);

        // Pending connection request for Bob.
        $carol = User::factory()->create();
        Connection::create(['requester_id' => $carol->id, 'addressee_id' => $bob->id]);

        $badges = $this->actingAs($bob)->getJson('/api/v1/badges');
        $badges->assertOk()
            ->assertJsonPath('data.messages', 1)
            ->assertJsonPath('data.calls', 1)
            ->assertJsonPath('data.connections', 1);

        // Attending clears each: read the conversation, see calls, answer request.
        $this->actingAs($bob)->postJson("/api/v1/conversations/{$conversation->uuid}/read");
        $this->actingAs($bob)->postJson('/api/v1/calls/seen');
        Connection::first()->update(['status' => 'accepted']);

        $this->actingAs($bob)->getJson('/api/v1/badges')
            ->assertJsonPath('data.messages', 0)
            ->assertJsonPath('data.calls', 0)
            ->assertJsonPath('data.connections', 0);
    }
}
