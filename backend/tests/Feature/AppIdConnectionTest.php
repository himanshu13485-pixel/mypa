<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppIdConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;
    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $appIds->generateFor($this->alice);
        $appIds->generateFor($this->bob);
        $this->alice->settings()->create([]);
        $this->bob->settings()->create([]);
    }

    public function test_user_can_search_another_by_app_id(): void
    {
        $this->actingAs($this->alice)
            ->getJson('/api/v1/app-id/search?q=' . $this->bob->appId->app_id)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bob');
    }

    public function test_search_respects_privacy_nobody(): void
    {
        $this->bob->settings->update(['privacy' => ['who_can_find_me' => 'nobody']]);

        $this->actingAs($this->alice)
            ->getJson('/api/v1/app-id/search?q=' . $this->bob->appId->app_id)
            ->assertNotFound();
    }

    public function test_blocked_user_cannot_find_blocker(): void
    {
        $this->bob->blockedUsers()->attach($this->alice->id);

        $this->actingAs($this->alice)
            ->getJson('/api/v1/app-id/search?q=' . $this->bob->appId->app_id)
            ->assertNotFound();
    }

    public function test_connection_request_flow(): void
    {
        // Alice sends a request to Bob
        $response = $this->actingAs($this->alice)->postJson('/api/v1/connections', [
            'app_id' => $this->bob->appId->app_id,
            'message' => 'Hi Bob!',
        ]);
        $response->assertCreated();
        $uuid = $response->json('data.uuid');

        // Duplicate request is rejected
        $this->actingAs($this->alice)->postJson('/api/v1/connections', [
            'app_id' => $this->bob->appId->app_id,
        ])->assertConflict();

        // Only the addressee can respond
        $this->actingAs($this->alice)
            ->putJson("/api/v1/connections/{$uuid}", ['action' => 'accept'])
            ->assertForbidden();

        // Bob accepts
        $this->actingAs($this->bob)
            ->putJson("/api/v1/connections/{$uuid}", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertTrue(app(AppIdService::class)->areConnected($this->alice, $this->bob));
    }

    public function test_my_qr_returns_connect_payload(): void
    {
        $this->actingAs($this->alice)
            ->getJson('/api/v1/me/app-id/qr')
            ->assertOk()
            ->assertJsonPath('data.app_id', $this->alice->appId->app_id);
    }
}
