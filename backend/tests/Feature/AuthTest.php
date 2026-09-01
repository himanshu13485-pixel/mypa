<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_user_can_register_and_receives_app_id(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'username' => 'testuser1',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'mobile' => '+919812345678',
            'timezone' => 'Asia/Kolkata',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['uuid', 'name', 'username', 'app_id', 'roles'], 'token'])
            ->assertJsonPath('email_verification_pending', true);

        $this->assertStringStartsWith('NV-', $response->json('data.app_id'));
        $this->assertContains('user', $response->json('data.roles'));
        $this->assertDatabaseHas('users', ['mobile' => '+919812345678', 'username' => 'testuser1']);
        $this->assertDatabaseHas('user_profiles', []);
        $this->assertDatabaseHas('user_settings', []);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dupe',
            'email' => 'dupe@example.com',
            'username' => 'dupeuser',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);
        $user->settings()->create([]);

        // A device this account has never signed in on is asked for a code
        // as well as the password, so the password alone yields no token.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertStatus(202)->assertJsonPath('otp_required', true);

        $code = \App\Models\MobileOtp::where('user_id', $user->id)
            ->where('purpose', 'login')->value('code');

        $this->postJson('/api/v1/auth/login/verify', [
            'identifier' => $user->email,
            'code' => $code,
        ])->assertOk()->assertJsonStructure(['token', 'device_token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'Password123']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword1',
        ])->assertUnprocessable();
    }

    public function test_suspended_user_cannot_login(): void
    {
        $user = User::factory()->create(['password' => 'Password123', 'status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_fetch_me(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.uuid', $user->uuid);
    }

    public function test_guest_cannot_access_protected_routes(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    public function test_user_can_change_password(): void
    {
        // Not about sign-in codes: this test signs in only to get at what
        // it is really checking, so the second step is switched off.
        \App\Models\AppSetting::set('login_otp_mode', 'off');

        $user = User::factory()->create(['password' => 'OldPassword1']);
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'OldPassword1',
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewPassword1',
        ])->assertOk();
    }
}
