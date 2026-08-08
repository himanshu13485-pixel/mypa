<?php

namespace Tests\Feature;

use App\Models\ClientError;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somewhere for a broken browser to say so.
 *
 * There was no error tracking of any kind, so a white screen produced no
 * signal and you found out when somebody complained.
 */
class ClientErrorTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        app(AppIdService::class)->generateFor($this->admin);
        $this->admin->settings()->create([]);
        $this->admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
    }

    private function report(array $payload = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/client-errors', array_merge([
            'message' => "Cannot read properties of undefined (reading 'name')",
            'stack' => "TypeError: boom\n    at MeetingRoomPage (index-abc.js:12:34)\n    at renderWithHooks",
            'url' => '/meetings/room/abc-defg-hij',
        ], $payload));
    }

    public function test_a_signed_out_browser_can_still_report(): void
    {
        // The errors most worth knowing about are the ones that stop someone
        // signing in, and those have no token to offer.
        $this->report()->assertStatus(202);

        $this->assertDatabaseCount('client_errors', 1);
        $this->assertSame('/meetings/room/abc-defg-hij', ClientError::first()->url);
    }

    public function test_the_same_fault_is_one_row_with_a_count(): void
    {
        $this->report();
        $this->report();
        $this->report();

        $this->assertDatabaseCount('client_errors', 1);
        $this->assertSame(3, ClientError::first()->hits);
    }

    public function test_a_different_fault_is_a_different_row(): void
    {
        $this->report();
        $this->report(['message' => 'Something else entirely']);

        $this->assertDatabaseCount('client_errors', 2);
    }

    public function test_the_same_message_from_a_different_place_is_a_different_row(): void
    {
        $this->report();
        $this->report(['stack' => "TypeError: boom\n    at TasksPage (index-abc.js:99:1)"]);

        $this->assertDatabaseCount('client_errors', 2);
    }

    public function test_a_fault_marked_fixed_that_happens_again_reopens_itself(): void
    {
        $this->report();
        $error = ClientError::first();
        $error->update(['resolved_at' => now()]);

        $this->report();

        $this->assertNull($error->fresh()->resolved_at, 'it is happening again, so it is not fixed');
    }

    public function test_an_overlong_report_is_refused_rather_than_stored(): void
    {
        $this->report(['message' => str_repeat('x', 1001)])->assertStatus(422);
        $this->assertDatabaseCount('client_errors', 0);
    }

    public function test_only_staff_can_read_them(): void
    {
        $this->report();

        $this->getJson('/api/v1/admin/client-errors')->assertUnauthorized();

        $nobody = User::factory()->create();
        app(AppIdService::class)->generateFor($nobody);
        $nobody->settings()->create([]);
        $this->actingAs($nobody)->getJson('/api/v1/admin/client-errors')->assertForbidden();

        $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/client-errors')
            ->assertOk()
            ->assertJsonPath('data.0.hits', 1);
    }

    public function test_an_admin_can_mark_one_fixed_and_unfix_it(): void
    {
        $this->report();
        $id = ClientError::first()->id;

        $this->actingAs($this->admin)->postJson("/api/v1/admin/client-errors/{$id}/resolve")->assertOk();
        $this->assertNotNull(ClientError::find($id)->resolved_at);

        // Open by default; fixed ones are behind the toggle.
        $this->actingAs($this->admin)->getJson('/api/v1/admin/client-errors')->assertJsonCount(0, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/admin/client-errors?resolved=1')->assertJsonCount(1, 'data');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/client-errors/{$id}/resolve")->assertOk();
        $this->assertNull(ClientError::find($id)->resolved_at);
    }

    public function test_who_saw_it_is_recorded_when_they_are_signed_in(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/client-errors', [
            'message' => 'Boom',
        ])->assertStatus(202);

        $this->assertSame($this->admin->id, ClientError::first()->last_user_id);
    }
}
