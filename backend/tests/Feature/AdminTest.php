<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $admin;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeWithRole('super_admin');
        $this->admin = $this->makeWithRole('admin');
        $this->user = $this->makeWithRole('user');
    }

    protected function makeWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $slug)->first()->id);

        return $user;
    }

    public function test_regular_user_cannot_access_admin_routes(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/admin/stats')->assertForbidden();
        $this->actingAs($this->user)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_admin_can_view_stats_and_users(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/stats')
            ->assertOk()
            ->assertJsonStructure(['data' => ['users', 'tasks']]);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_admin_can_suspend_and_activate_user(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/suspend")
            ->assertOk();

        $this->assertEquals('suspended', $this->user->fresh()->status);

        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/activate")
            ->assertOk();

        $this->assertEquals('active', $this->user->fresh()->status);
    }

    public function test_admin_cannot_modify_super_admin(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/users/{$this->superAdmin->uuid}/suspend")
            ->assertForbidden();
    }

    public function test_only_super_admin_can_create_admin_accounts(): void
    {
        $payload = [
            'name' => 'New Admin',
            'email' => 'newadmin@example.com',
            'password' => 'Password123',
            'role' => 'admin',
        ];

        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', $payload)->assertForbidden();
        $this->actingAs($this->superAdmin)->postJson('/api/v1/admin/users', $payload)->assertCreated();
    }

    public function test_admin_can_create_subadmin(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/admin/users', [
            'name' => 'Sub',
            'email' => 'sub@example.com',
            'password' => 'Password123',
            'role' => 'subadmin',
        ])->assertCreated()->assertJsonPath('data.roles.0', 'subadmin');
    }

    public function test_super_admin_can_regenerate_app_id(): void
    {
        app(AppIdService::class)->generateFor($this->user);
        $old = $this->user->appId->app_id;

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/app-id/regenerate")
            ->assertOk();

        $this->assertNotEquals($old, $response->json('data.app_id'));
        $this->assertEquals($old, $response->json('data.previous'));
    }

    public function test_suspended_user_is_locked_out_of_api(): void
    {
        $token = $this->user->createToken('web')->plainTextToken;
        $this->user->update(['status' => 'suspended']);

        $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    }
}
