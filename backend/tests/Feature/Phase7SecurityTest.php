<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase7SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, PlanSeeder::class]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);
        $this->user = User::factory()->create();
    }

    public function test_forced_password_change_flag_flows_through_login_and_clears(): void
    {
        $this->user->update(['password' => 'Default123', 'force_password_change' => true]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'Default123',
        ]);
        $login->assertOk()->assertJsonPath('must_change_password', true);

        $token = $login->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertJsonPath('data.must_change_password', true);

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'Default123',
            'password' => 'BrandNew123',
            'password_confirmation' => 'BrandNew123',
        ])->assertOk();

        $this->assertFalse($this->user->fresh()->force_password_change);
    }

    public function test_admin_actions_create_audit_logs(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/suspend")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $this->superAdmin->id,
            'action' => 'user.suspended',
            'subject_id' => $this->user->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/users/{$this->user->uuid}/plan", ['plan_slug' => 'family'])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'subscription.assigned']);
    }

    public function test_audit_log_endpoint_admin_only(): void
    {
        AuditLog::record($this->superAdmin, 'user.suspended', $this->user);

        $this->actingAs($this->user)->getJson('/api/v1/admin/audit-logs')->assertForbidden();

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'user.suspended');
    }

    public function test_security_headers_present(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertNotNull($response->headers->get('Referrer-Policy'));
    }

    public function test_auth_endpoints_are_rate_limited(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password-1',
            ]);
        }

        $response->assertStatus(429);
    }
}
