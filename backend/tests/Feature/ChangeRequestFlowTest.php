<?php

namespace Tests\Feature;

use App\Models\ChangeRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The identity approval loop: a member asks for a new username or email,
 * an admin approves it, and the login identity actually changes.
 */
class ChangeRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->settings()->create([]);
        $admin->profile()->create(['timezone' => 'UTC']);
        $admin->roles()->attach(Role::where('slug', 'admin')->first()->id, ['assigned_by' => $admin->id]);

        return $admin;
    }

    public function test_an_approved_username_change_really_changes_the_username(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = $this->admin();

        // An account registered by the company carries no username yet —
        // exactly the shape the CRM register flow creates.
        $person = User::factory()->create(['username' => null, 'email' => 'crm@grapout.test']);
        $person->settings()->create([]);
        $person->profile()->create(['timezone' => 'UTC']);

        $this->actingAs($person)->postJson('/api/v1/me/change-requests', [
            'type' => 'username', 'new_value' => 'grapoutcrm',
        ])->assertCreated();

        $req = ChangeRequest::where('user_id', $person->id)->firstOrFail();
        $this->actingAs($admin)->postJson('/api/v1/admin/change-requests/' . $req->getRouteKey(), [
            'action' => 'approve',
        ])->assertOk();

        $this->assertSame('grapoutcrm', $person->fresh()->username);

        // And the identity screen reads it straight off /me.
        $this->actingAs($person->fresh())->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.username', 'grapoutcrm');
    }
}
