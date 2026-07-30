<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Conversation;
use App\Models\LoginHistory;
use App\Models\Report;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminOpsTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;
    protected User $bob;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $u) {
            $appIds->generateFor($u);
            $u->settings()->create([]);
        }
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('slug', 'admin')->first()->id);
    }

    // --- Social notifications -------------------------------------------------

    public function test_connection_request_and_acceptance_notify_both_sides(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/connections', [
            'app_id' => $this->bob->appId->app_id,
        ])->assertCreated();

        $bobNotification = $this->bob->notifications()->first();
        $this->assertEquals('connection_request', $bobNotification->data['kind']);
        $this->assertStringContainsString('Alice', $bobNotification->data['message']);

        $uuid = Connection::first()->uuid;
        $this->actingAs($this->bob)->putJson("/api/v1/connections/{$uuid}", ['action' => 'accept'])->assertOk();

        // Bob's request notification auto-cleared on attend.
        $this->assertEquals(0, $this->bob->unreadNotifications()->where('data->kind', 'connection_request')->count());

        // Alice hears about the acceptance.
        $this->assertEquals(1, $this->alice->unreadNotifications()->where('data->kind', 'connection_accepted')->count());
    }

    public function test_task_assignment_notifies_assignee(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/tasks', [
            'title' => 'Review report',
            'assignees' => [$this->bob->appId->app_id],
        ])->assertCreated();

        $this->assertEquals(1, $this->bob->unreadNotifications()->where('data->kind', 'task_assigned')->count());
    }

    public function test_read_kinds_endpoint_clears_section_notifications(): void
    {
        $this->actingAs($this->alice)->postJson('/api/v1/connections', [
            'app_id' => $this->bob->appId->app_id,
        ])->assertCreated();

        $this->actingAs($this->bob)
            ->postJson('/api/v1/notifications/read-kinds', ['kinds' => ['connection_request', 'connection_accepted']])
            ->assertOk();

        $this->assertEquals(0, $this->bob->unreadNotifications()->count());
    }

    public function test_email_channel_only_when_verified_and_enabled(): void
    {
        // No email → database only (the factory seeds one, so clear it first).
        $this->bob->forceFill(['email' => null, 'email_verified_at' => null])->save();
        $this->assertFalse(\App\Notifications\SocialNotification::wantsMail($this->bob->fresh()->load('settings')));

        // Email set but unverified → still no mail.
        $this->bob->forceFill(['email' => 'bob@example.com', 'email_verified_at' => null])->save();
        $this->assertFalse(\App\Notifications\SocialNotification::wantsMail($this->bob->fresh()));

        // Verified → mail.
        $this->bob->forceFill(['email_verified_at' => now()])->save();
        $this->assertTrue(\App\Notifications\SocialNotification::wantsMail($this->bob->fresh()->load('settings')));

        // Preference off → no mail.
        $this->bob->settings->update(['notification_preferences' => ['email' => false]]);
        $this->assertFalse(\App\Notifications\SocialNotification::wantsMail($this->bob->fresh()->load('settings')));
    }

    // --- Task created-date filters --------------------------------------------

    public function test_task_created_date_filters(): void
    {
        $old = Task::create(['user_id' => $this->alice->id, 'title' => 'Old task']);
        $old->timestamps = false;
        $old->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        Task::create(['user_id' => $this->alice->id, 'title' => 'New task']);

        $this->actingAs($this->alice)
            ->getJson('/api/v1/tasks?created_from=' . now()->subDay()->toDateString())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'New task');

        $this->actingAs($this->alice)
            ->getJson('/api/v1/tasks?created_to=' . now()->subDays(5)->toDateString())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Old task');
    }

    // --- Moderation -----------------------------------------------------------

    protected function makeChatMessage(): \App\Models\Message
    {
        $conversation = Conversation::create(['type' => 'direct', 'created_by' => $this->alice->id]);
        $conversation->members()->attach([$this->alice->id, $this->bob->id]);

        return $conversation->messages()->create(['user_id' => $this->bob->id, 'body' => 'Offensive spam']);
    }

    public function test_report_and_moderation_flow(): void
    {
        $message = $this->makeChatMessage();

        // Alice reports Bob's message.
        $this->actingAs($this->alice)->postJson('/api/v1/reports', [
            'message_uuid' => $message->uuid,
            'reason' => 'spam',
            'details' => 'Unsolicited advertising',
        ])->assertCreated();

        // Duplicate open report blocked.
        $this->actingAs($this->alice)->postJson('/api/v1/reports', [
            'message_uuid' => $message->uuid, 'reason' => 'spam',
        ])->assertConflict();

        // Admin sees the queue and deletes the message.
        $report = Report::first();
        $this->actingAs($this->admin)->getJson('/api/v1/admin/reports')
            ->assertOk()->assertJsonPath('data.0.reason', 'spam');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/reports/{$report->uuid}/act", [
            'action' => 'delete_message',
        ])->assertOk();

        $this->assertNotNull($message->fresh()->deleted_at);
        $this->assertEquals('actioned', $report->fresh()->status);
        // Reporter is informed.
        $this->assertEquals(1, $this->alice->unreadNotifications()->where('data->kind', 'report_resolved')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'moderation.delete_message']);
    }

    public function test_moderation_suspend_and_warn(): void
    {
        Report::create([
            'reporter_id' => $this->alice->id,
            'reported_user_id' => $this->bob->id,
            'reason' => 'harassment',
        ]);
        $report = Report::first();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/reports/{$report->uuid}/act", [
            'action' => 'warn', 'note' => 'First warning',
        ])->assertOk();

        $this->assertEquals(1, $this->bob->unreadNotifications()->where('data->kind', 'moderation_warning')->count());

        // Second report → suspend.
        $report2 = Report::create([
            'reporter_id' => $this->alice->id,
            'reported_user_id' => $this->bob->id,
            'reason' => 'harassment',
        ]);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/reports/{$report2->uuid}/act", [
            'action' => 'suspend',
        ])->assertOk();

        $this->assertEquals('suspended', $this->bob->fresh()->status);
    }

    // --- Subadmin module rights ------------------------------------------------

    public function test_subadmin_rights_default_and_granted(): void
    {
        $subadmin = User::factory()->create();
        $subadmin->roles()->attach(Role::where('slug', 'subadmin')->first()->id);

        // Default: approvals only.
        $this->actingAs($subadmin)->getJson('/api/v1/admin/change-requests')->assertOk();
        $this->actingAs($subadmin)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($subadmin)->getJson('/api/v1/admin/reports')->assertForbidden();
        $this->actingAs($subadmin)->getJson('/api/v1/admin/active-members')->assertForbidden();

        // Admin grants users view + edit (no delete) and moderation view.
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$subadmin->uuid}/module-permissions", [
            'permissions' => [
                'users' => ['can_view' => true, 'can_edit' => true, 'can_delete' => false],
                'moderation' => ['can_view' => true, 'can_edit' => false, 'can_delete' => false],
            ],
        ])->assertOk();

        $this->actingAs($subadmin)->getJson('/api/v1/admin/users')->assertOk();
        $this->actingAs($subadmin)->postJson("/api/v1/admin/users/{$this->bob->uuid}/activate")->assertOk();
        // Suspend needs delete rights → forbidden.
        $this->actingAs($subadmin)->postJson("/api/v1/admin/users/{$this->bob->uuid}/suspend")->assertForbidden();

        // Moderation: view yes, act no.
        $this->actingAs($subadmin)->getJson('/api/v1/admin/reports')->assertOk();
        $report = Report::create([
            'reporter_id' => $this->alice->id, 'reported_user_id' => $this->bob->id, 'reason' => 'spam',
        ]);
        $this->actingAs($subadmin)->postJson("/api/v1/admin/reports/{$report->uuid}/act", ['action' => 'dismiss'])
            ->assertForbidden();

        // Regular admin cannot be given/needs no grants — full access remains.
        $this->actingAs($this->admin)->getJson('/api/v1/admin/active-members')->assertOk();
    }

    // --- Active members & summary ----------------------------------------------

    public function test_active_members_and_user_summary(): void
    {
        LoginHistory::create([
            'user_id' => $this->alice->id,
            'ip_address' => '10.1.2.3',
            'device_name' => 'web',
            'logged_in_at' => now()->subMinutes(10),
        ]);
        LoginHistory::create([
            'user_id' => $this->bob->id,
            'ip_address' => '10.9.9.9',
            'device_name' => 'web',
            'logged_in_at' => now()->subDays(3), // outside 24h window
        ]);

        $active = $this->actingAs($this->admin)->getJson('/api/v1/admin/active-members');
        $active->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alice')
            ->assertJsonPath('data.0.ip_address', '10.1.2.3')
            ->assertJsonPath('data.0.is_online', true);

        Task::create(['user_id' => $this->alice->id, 'title' => 'Done', 'status' => 'completed']);
        Task::create(['user_id' => $this->alice->id, 'title' => 'Open']);

        $summary = $this->actingAs($this->admin)->getJson("/api/v1/admin/users/{$this->alice->uuid}/summary");
        $summary->assertOk()
            ->assertJsonPath('data.tasks.total', 2)
            ->assertJsonPath('data.tasks.completed', 1)
            ->assertJsonPath('data.last_login.ip', '10.1.2.3')
            ->assertJsonPath('data.plan', 'free');
    }
}
