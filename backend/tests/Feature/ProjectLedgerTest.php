<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_full_ledger_flow_with_filters_summary_and_export(): void
    {
        $project = $this->actingAs($this->user)->postJson('/api/v1/projects', [
            'name' => 'Site A construction',
            'purpose' => 'construction',
            'base_currency' => 'INR',
        ])->assertCreated()->json('data');

        $mk = fn (array $extra) => $this->actingAs($this->user)
            ->postJson("/api/v1/projects/{$project['uuid']}/entries", $extra + [
                'entry_date' => now()->toDateString(),
                'description' => 'Cement 50 bags',
                'direction' => 'debit',
                'amount' => 25000,
                'currency' => 'INR',
                'mode' => 'cash',
            ])->assertCreated();

        $mk(['counterparty' => 'ABC Suppliers']);
        $mk(['description' => 'Advance from client', 'direction' => 'credit', 'amount' => 100000, 'mode' => 'bank', 'bank_account' => 'HDFC 1234']);
        $mk(['description' => 'Labour payment', 'amount' => 15000, 'entry_date' => now()->subDays(10)->toDateString()]);
        $mk(['description' => 'USD tools', 'amount' => 200, 'currency' => 'USD']);

        // All entries listed, newest date first.
        $list = $this->actingAs($this->user)->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertOk();
        $this->assertCount(4, $list->json('data'));

        // Date filter drops the 10-day-old entry.
        $recent = $this->actingAs($this->user)
            ->getJson("/api/v1/projects/{$project['uuid']}/entries?date_from=" . now()->subDay()->toDateString())
            ->json('data');
        $this->assertCount(3, $recent);

        // Mode and direction filters.
        $this->assertCount(1, $this->actingAs($this->user)->getJson("/api/v1/projects/{$project['uuid']}/entries?mode=bank")->json('data'));
        $this->assertCount(1, $this->actingAs($this->user)->getJson("/api/v1/projects/{$project['uuid']}/entries?direction=credit")->json('data'));
        $this->assertCount(1, $this->actingAs($this->user)->getJson("/api/v1/projects/{$project['uuid']}/entries?q=labour")->json('data'));

        // Summary: INR credit 100000, debit 40000, net +60000; USD net -200.
        $summary = collect($this->actingAs($this->user)->getJson("/api/v1/projects/{$project['uuid']}/summary")->json('data'));
        $inr = $summary->firstWhere('currency', 'INR');
        $this->assertEquals(100000.0, $inr['credit']);
        $this->assertEquals(40000.0, $inr['debit']);
        $this->assertEquals(60000.0, $inr['net']);
        $this->assertEquals(-200.0, $summary->firstWhere('currency', 'USD')['net']);

        // CSV export streams with the right headers.
        $export = $this->actingAs($this->user)->get("/api/v1/projects/{$project['uuid']}/export");
        $export->assertOk();
        $this->assertStringContainsString('text/csv', $export->headers->get('content-type'));
        $csv = $export->streamedContent();
        $this->assertStringContainsString('Cement 50 bags', $csv);
        $this->assertStringContainsString('NET TOTAL', $csv);

        // Strangers get nothing.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/v1/projects/{$project['uuid']}/entries", [])->assertForbidden();
    }

    public function test_project_sharing_view_and_edit_rights(): void
    {
        $appIds = app(\App\Services\AppIdService::class);
        $viewer = User::factory()->create(['name' => 'Viewer', 'username' => 'viewer1']);
        $editor = User::factory()->create(['name' => 'Editor', 'username' => 'editor1']);
        foreach ([$this->user, $viewer, $editor] as $u) {
            $appIds->generateFor($u);
        }

        $project = $this->actingAs($this->user)->postJson('/api/v1/projects', ['name' => 'Shared site'])->json('data');
        $entry = $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/entries", [
            'entry_date' => now()->toDateString(), 'description' => 'Bricks', 'direction' => 'debit', 'amount' => 9000,
        ])->json('data');

        // Share by username: viewer gets view, editor gets edit. Both are notified.
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/share", [
            'app_id' => 'viewer1', 'permission' => 'view',
        ])->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/share", [
            'app_id' => 'editor1', 'permission' => 'edit',
        ])->assertOk();
        $this->assertEquals('project_shared', $viewer->notifications()->first()->data['kind']);

        // Both see the project in their list and can read the live ledger.
        $this->assertCount(1, $this->actingAs($viewer)->getJson('/api/v1/projects')->json('data'));
        $this->actingAs($viewer)->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertOk();
        $this->actingAs($viewer)->getJson("/api/v1/projects/{$project['uuid']}/summary")->assertOk();

        // View-only cannot write anything.
        $this->actingAs($viewer)->postJson("/api/v1/projects/{$project['uuid']}/entries", [
            'entry_date' => now()->toDateString(), 'description' => 'x', 'direction' => 'debit', 'amount' => 1,
        ])->assertForbidden();

        // Editor can add and edit — and the entry records their name.
        $added = $this->actingAs($editor)->postJson("/api/v1/projects/{$project['uuid']}/entries", [
            'entry_date' => now()->toDateString(), 'description' => 'Sand', 'direction' => 'debit', 'amount' => 3000,
        ])->assertCreated();
        $this->assertEquals('Editor', $added->json('data.created_by'));

        $edited = $this->actingAs($editor)->putJson("/api/v1/projects/{$project['uuid']}/entries/{$entry['uuid']}", [
            'amount' => 9500,
        ])->assertOk();
        $this->assertEquals('Editor', $edited->json('data.updated_by'));

        // But editors can NEVER delete — entries or the project. Creator only.
        $this->actingAs($editor)->deleteJson("/api/v1/projects/{$project['uuid']}/entries/{$entry['uuid']}")->assertForbidden();
        $this->actingAs($editor)->deleteJson("/api/v1/projects/{$project['uuid']}")->assertForbidden();
        $this->actingAs($editor)->postJson("/api/v1/projects/{$project['uuid']}/share", [
            'app_id' => 'viewer1', 'permission' => 'edit',
        ])->assertForbidden();

        // Creator revokes the viewer's access.
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/unshare", [
            'user_uuid' => $viewer->uuid,
        ])->assertOk();
        $this->actingAs($viewer)->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertForbidden();
    }

    public function test_daily_report_mails_only_on_days_with_changes(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $project = $this->actingAs($this->user)->postJson('/api/v1/projects', [
            'name' => 'Reported site', 'daily_report' => true, 'report_format' => 'excel',
        ])->assertCreated()->json('data');
        $this->assertTrue($project['daily_report']);

        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/entries", [
            'entry_date' => now()->toDateString(), 'description' => 'Steel', 'direction' => 'debit', 'amount' => 7000,
        ])->assertCreated();

        // Day with changes -> report goes out with the CSV attached.
        $this->assertEquals(0, \Illuminate\Support\Facades\Artisan::call('mypa:project-daily-reports'));

        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\ProjectDailyReport::class, function ($mail) {
            return $mail->changesCount === 1
                && str_contains($mail->fileName, '.csv')
                && str_contains($mail->fileContents, 'Steel');
        });

        // No further changes -> silent next run.
        $this->assertEquals(0, \Illuminate\Support\Facades\Artisan::call('mypa:project-daily-reports'));
        \Illuminate\Support\Facades\Mail::assertQueuedCount(1);

        // PDF format produces a PDF attachment. Travel forward so the new
        // entry is unambiguously AFTER the first report (second-level clocks).
        $this->travel(1)->minutes();
        $this->actingAs($this->user)->putJson("/api/v1/projects/{$project['uuid']}", ['report_format' => 'pdf'])->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/entries", [
            'entry_date' => now()->toDateString(), 'description' => 'Paint', 'direction' => 'debit', 'amount' => 2000,
        ])->assertCreated();
        $this->assertEquals(0, \Illuminate\Support\Facades\Artisan::call('mypa:project-daily-reports'));
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\ProjectDailyReport::class, function ($mail) {
            return str_contains($mail->fileName, '.pdf') && str_starts_with($mail->fileContents, '%PDF');
        });
    }

    public function test_entry_reminder_rings_once(): void
    {
        $project = Project::create(['user_id' => $this->user->id, 'name' => 'Shop', 'purpose' => 'business']);
        $project->entries()->create([
            'entry_date' => now()->toDateString(),
            'description' => 'Pending payment from Ramesh',
            'direction' => 'credit',
            'amount' => 5000,
            'currency' => 'INR',
            'mode' => 'cash',
            'counterparty' => 'Ramesh',
            'reminder_at' => now()->subMinute(),
        ]);

        $this->artisan('mypa:project-reminders')->assertSuccessful();
        $this->assertEquals(1, $this->user->notifications()->count());
        $this->assertStringContainsString('Ramesh', $this->user->notifications()->first()->data['message']);

        $this->artisan('mypa:project-reminders')->assertSuccessful();
        $this->assertEquals(1, $this->user->notifications()->count());
    }
}
