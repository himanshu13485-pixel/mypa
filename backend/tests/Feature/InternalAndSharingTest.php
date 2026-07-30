<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Current batch: folder sharing, universal email/username resolver,
 * Internal Work module, salesperson role, audit subject names, plan column.
 */
class InternalAndSharingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $friend;
    protected User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->owner = User::factory()->create(['name' => 'Owner', 'username' => 'owner1']);
        $this->friend = User::factory()->create(['name' => 'Friend', 'username' => 'friend1', 'email' => 'friend@mypa.local']);
        $this->stranger = User::factory()->create(['name' => 'Stranger', 'username' => 'stranger1']);
        foreach ([$this->owner, $this->friend, $this->stranger] as $u) {
            $appIds->generateFor($u);
        }
    }

    private function makeStaff(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('slug', $roleSlug)->first()->id);

        return $user;
    }

    // --- Folder sharing ------------------------------------------------------

    public function test_folder_share_grants_access_to_all_files_inside(): void
    {
        Storage::fake('local');

        $folder = $this->actingAs($this->owner)->postJson('/api/v1/folders', ['name' => 'Contracts'])
            ->assertCreated()->json('data');

        $file = $this->actingAs($this->owner)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('deal.pdf', 50, 'application/pdf')],
            'folder_uuid' => $folder['uuid'],
        ])->assertCreated()->json('data.0');

        // Share the folder with friend by USERNAME (not App ID).
        $this->actingAs($this->owner)->postJson("/api/v1/folders/{$folder['uuid']}/share", [
            'app_id' => 'friend1',
        ])->assertOk();

        // Friend sees the shared folder and can list + download its files.
        $shared = $this->actingAs($this->friend)->getJson('/api/v1/files/shared-with-me')->assertOk()->json();
        $this->assertCount(1, $shared['shared_folders']);
        $this->assertEquals('Contracts', $shared['shared_folders'][0]['name']);

        $this->actingAs($this->friend)
            ->getJson("/api/v1/folders/{$folder['uuid']}/shared-files")
            ->assertOk()
            ->assertJsonCount(1, 'data.files');
        $this->actingAs($this->friend)->get("/api/v1/files/{$file['uuid']}/download")->assertOk();

        // Stranger gets nothing.
        $this->actingAs($this->stranger)
            ->getJson("/api/v1/folders/{$folder['uuid']}/shared-files")
            ->assertForbidden();
        $this->actingAs($this->stranger)->get("/api/v1/files/{$file['uuid']}/download")->assertForbidden();
    }

    // --- Universal resolver (email / username everywhere) --------------------

    public function test_note_can_be_shared_by_email_and_task_assigned_by_username(): void
    {
        $note = $this->actingAs($this->owner)->postJson('/api/v1/notes', [
            'title' => 'Plan', 'body' => 'Q3 plan',
        ])->assertCreated()->json('data');

        $this->actingAs($this->owner)->postJson("/api/v1/notes/{$note['uuid']}/share", [
            'app_id' => 'friend@mypa.local',
            'permission' => 'view',
        ])->assertOk();
        $this->actingAs($this->friend)->getJson("/api/v1/notes/{$note['uuid']}")->assertOk();
    }

    public function test_mobile_number_is_never_searchable(): void
    {
        $this->friend->forceFill(['mobile' => '9876543210'])->save();

        $this->actingAs($this->owner)->postJson('/api/v1/connections', [
            'app_id' => '9876543210',
        ])->assertStatus(404);
    }

    // --- Internal Work -------------------------------------------------------

    public function test_salesperson_can_use_internal_work_module(): void
    {
        $sales = $this->makeStaff('salesperson');

        // Lookup by username, then post and read a note thread.
        $found = $this->actingAs($sales)->postJson('/api/v1/admin/internal/lookup', [
            'identifier' => 'friend1',
        ])->assertOk()->json('data');
        $this->assertEquals($this->friend->uuid, $found['uuid']);

        $this->actingAs($sales)->postJson("/api/v1/admin/internal/users/{$found['uuid']}/notes", [
            'body' => 'Interested in the Pro plan.',
        ])->assertCreated();

        $this->actingAs($sales)->getJson('/api/v1/admin/internal/threads')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($sales)->getJson("/api/v1/admin/internal/users/{$found['uuid']}/notes")
            ->assertOk()->assertJsonCount(1, 'data.notes');
    }

    public function test_regular_user_cannot_touch_internal_work(): void
    {
        $this->actingAs($this->owner)->getJson('/api/v1/admin/internal/threads')->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/v1/admin/internal/users/{$this->friend->uuid}/notes", [
            'body' => 'nope',
        ])->assertForbidden();
    }

    public function test_salesperson_cannot_access_other_admin_modules(): void
    {
        $sales = $this->makeStaff('salesperson');
        $this->actingAs($sales)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    // --- Audit trail ---------------------------------------------------------

    public function test_registration_is_audited_and_activity_shows_subject_names(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Person',
            'email' => 'new.person@mypa.local',
            'username' => 'newperson',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'timezone' => 'Asia/Kolkata',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.registered']);

        $admin = $this->makeStaff('admin');
        $rows = $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs')->assertOk()->json('data');
        $registered = collect($rows)->firstWhere('action', 'user.registered');
        $this->assertEquals('New Person', $registered['subject_name']);
    }

    // --- Admin users list carries the plan -----------------------------------

    public function test_admin_users_index_includes_plan(): void
    {
        $admin = $this->makeStaff('admin');
        $rows = $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk()->json('data');
        $this->assertArrayHasKey('plan', $rows[0]);
    }
}
