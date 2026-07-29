<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase3Test extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->user = User::factory()->create();
        $this->other = User::factory()->create();
        $appIds->generateFor($this->user);
        $appIds->generateFor($this->other);
    }

    // --- Notes --------------------------------------------------------------

    public function test_note_crud_with_versions(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/notes', [
            'title' => 'Shopping ideas',
            'body' => 'Buy a new desk',
        ]);
        $response->assertCreated();
        $uuid = $response->json('data.uuid');

        $this->actingAs($this->user)
            ->putJson("/api/v1/notes/{$uuid}", ['body' => 'Buy a new desk and chair'])
            ->assertOk();

        // A version snapshot of the previous content exists.
        $this->actingAs($this->user)
            ->getJson("/api/v1/notes/{$uuid}/versions")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Buy a new desk');

        $this->actingAs($this->user)->deleteJson("/api/v1/notes/{$uuid}")->assertOk();
    }

    public function test_password_protected_note_hides_content(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/notes', [
            'title' => 'Secret',
            'body' => 'PIN is 1234',
            'password' => 'openme',
        ]);
        $uuid = $response->json('data.uuid');

        // Without password → 423, no body.
        $blocked = $this->actingAs($this->user)->getJson("/api/v1/notes/{$uuid}");
        $blocked->assertStatus(423);
        $this->assertNull($blocked->json('data.body'));

        // With wrong password → still locked.
        $this->actingAs($this->user)
            ->withHeader('X-Note-Password', 'wrong')
            ->getJson("/api/v1/notes/{$uuid}")
            ->assertStatus(423);

        // With correct password → content.
        $this->actingAs($this->user)
            ->withHeader('X-Note-Password', 'openme')
            ->getJson("/api/v1/notes/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.body', 'PIN is 1234');
    }

    public function test_note_sharing_and_permissions(): void
    {
        $note = Note::create(['user_id' => $this->user->id, 'title' => 'Shared', 'body' => 'Hello']);

        // Stranger cannot see it.
        $this->actingAs($this->other)->getJson("/api/v1/notes/{$note->uuid}")->assertForbidden();

        // Share view-only.
        $this->actingAs($this->user)->postJson("/api/v1/notes/{$note->uuid}/share", [
            'app_id' => $this->other->appId->app_id,
            'permission' => 'view',
        ])->assertOk();

        $this->actingAs($this->other)->getJson("/api/v1/notes/{$note->uuid}")->assertOk();
        $this->actingAs($this->other)
            ->putJson("/api/v1/notes/{$note->uuid}", ['body' => 'Hijack'])
            ->assertForbidden();

        // Upgrade to edit.
        $this->actingAs($this->user)->postJson("/api/v1/notes/{$note->uuid}/share", [
            'app_id' => $this->other->appId->app_id,
            'permission' => 'edit',
        ])->assertOk();

        $this->actingAs($this->other)
            ->putJson("/api/v1/notes/{$note->uuid}", ['body' => 'Edited by friend'])
            ->assertOk();
    }

    // --- Files --------------------------------------------------------------

    public function test_file_upload_download_and_trash_flow(): void
    {
        Storage::fake('local');

        $upload = $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json']);
        $upload->assertCreated();
        $uuid = $upload->json('data.0.uuid');

        // Download own file.
        $this->actingAs($this->user)->get("/api/v1/files/{$uuid}/download")->assertOk();

        // Stranger cannot download.
        $this->actingAs($this->other)->get("/api/v1/files/{$uuid}/download")->assertForbidden();

        // Trash → restore → force delete.
        $this->actingAs($this->user)->deleteJson("/api/v1/files/{$uuid}")->assertOk();
        $this->actingAs($this->user)->getJson('/api/v1/files/trash')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->user)->postJson("/api/v1/files/{$uuid}/restore")->assertOk();
        $this->actingAs($this->user)->deleteJson("/api/v1/files/{$uuid}")->assertOk();
        $this->actingAs($this->user)->deleteJson("/api/v1/files/{$uuid}/force")->assertOk();
        $this->assertDatabaseMissing('files', ['uuid' => $uuid]);
    }

    public function test_blocked_extension_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('virus.exe', 10)],
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_file_sharing(): void
    {
        Storage::fake('local');

        $upload = $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('shared.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json']);
        $uuid = $upload->json('data.0.uuid');

        $this->actingAs($this->user)->postJson("/api/v1/files/{$uuid}/share", [
            'app_id' => $this->other->appId->app_id,
        ])->assertOk();

        $this->actingAs($this->other)->get("/api/v1/files/{$uuid}/download")->assertOk();
        $this->actingAs($this->other)
            ->getJson('/api/v1/files/shared-with-me')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_folder_browse_and_nesting(): void
    {
        $folder = $this->actingAs($this->user)->postJson('/api/v1/folders', ['name' => 'Documents']);
        $folder->assertCreated();
        $folderUuid = $folder->json('data.uuid');

        $this->actingAs($this->user)->postJson('/api/v1/folders', [
            'name' => 'Invoices',
            'parent_uuid' => $folderUuid,
        ])->assertCreated();

        $browse = $this->actingAs($this->user)->getJson("/api/v1/files/browse?folder={$folderUuid}");
        $browse->assertOk()
            ->assertJsonPath('data.folder.name', 'Documents')
            ->assertJsonCount(1, 'data.folders')
            ->assertJsonPath('data.breadcrumb.0.name', 'Documents');
    }

    // --- Groups -------------------------------------------------------------

    public function test_group_lifecycle_and_member_roles(): void
    {
        $create = $this->actingAs($this->user)->postJson('/api/v1/groups', [
            'name' => 'My Family',
            'type' => 'family',
        ]);
        $create->assertCreated()->assertJsonPath('data.my_role', 'owner');
        $uuid = $create->json('data.uuid');

        // Add a member.
        $this->actingAs($this->user)->postJson("/api/v1/groups/{$uuid}/members", [
            'app_id' => $this->other->appId->app_id,
            'role' => 'member',
        ])->assertCreated();

        // Member sees the group but cannot manage members.
        $this->actingAs($this->other)->getJson("/api/v1/groups/{$uuid}")->assertOk();
        $third = User::factory()->create();
        app(AppIdService::class)->generateFor($third);
        $this->actingAs($this->other)->postJson("/api/v1/groups/{$uuid}/members", [
            'app_id' => $third->appId->app_id,
        ])->assertForbidden();

        // Role change by owner.
        $this->actingAs($this->user)
            ->putJson("/api/v1/groups/{$uuid}/members/{$this->other->uuid}", ['role' => 'admin'])
            ->assertOk();

        // Member can leave.
        $this->actingAs($this->other)
            ->deleteJson("/api/v1/groups/{$uuid}/members/{$this->other->uuid}")
            ->assertOk();

        // Only owner deletes the group.
        $this->actingAs($this->other)->deleteJson("/api/v1/groups/{$uuid}")->assertForbidden();
        $this->actingAs($this->user)->deleteJson("/api/v1/groups/{$uuid}")->assertOk();
    }

    public function test_group_tasks_visible_to_members(): void
    {
        $group = Group::create(['owner_id' => $this->user->id, 'name' => 'Team', 'type' => 'team']);
        $group->members()->attach([$this->user->id => ['role' => 'owner'], $this->other->id => ['role' => 'member']]);

        // Owner creates a group task.
        $this->actingAs($this->user)->postJson('/api/v1/tasks', [
            'title' => 'Team task',
            'group_uuid' => $group->uuid,
        ])->assertCreated();

        // Member sees it in the general list and the group endpoint.
        $this->actingAs($this->other)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Team task');
        $this->actingAs($this->other)
            ->getJson("/api/v1/groups/{$group->uuid}/tasks")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Non-member cannot use the group.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson('/api/v1/tasks', [
            'title' => 'Sneak in',
            'group_uuid' => $group->uuid,
        ])->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/v1/groups/{$group->uuid}/tasks")->assertForbidden();
    }

    // --- Reports ------------------------------------------------------------

    public function test_reports_summary_and_productivity(): void
    {
        Task::create(['user_id' => $this->user->id, 'title' => 'A', 'status' => 'completed', 'completed_at' => now(), 'priority' => 'high']);
        Task::create(['user_id' => $this->user->id, 'title' => 'B', 'status' => 'in_progress', 'priority' => 'low']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.total', 2)
            ->assertJsonPath('data.totals.completed', 1)
            ->assertJsonPath('data.totals.completion_rate', 50);

        $productivity = $this->actingAs($this->user)->getJson('/api/v1/reports/productivity?days=7');
        $productivity->assertOk()->assertJsonCount(7, 'data');
        $today = collect($productivity->json('data'))->firstWhere('date', now()->toDateString());
        $this->assertEquals(1, $today['completed']);

        $csv = $this->actingAs($this->user)->get('/api/v1/reports/export.csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Title', $content);
        $this->assertStringContainsString('A', $content);
    }
}
