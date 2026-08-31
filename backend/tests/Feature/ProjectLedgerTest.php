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

    public function test_password_lock_and_admin_reset_flow(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $admin = User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::where('slug', 'admin')->first()->id);

        // Lock the project with a password.
        $project = $this->actingAs($this->user)->postJson('/api/v1/projects', [
            'name' => 'Vault', 'password' => 'secret99',
        ])->assertCreated()->json('data');
        $this->assertTrue($project['has_password']);

        // Without the password: 423 locked. With it: open. Wrong: locked.
        $this->actingAs($this->user)->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertStatus(423);
        $this->actingAs($this->user)
            ->withHeader('X-Project-Password', 'wrong')
            ->getJson("/api/v1/projects/{$project['uuid']}/summary")->assertStatus(423);
        $this->actingAs($this->user)
            ->withHeader('X-Project-Password', 'secret99')
            ->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertOk();
        $this->actingAs($this->user)
            ->withHeader('X-Project-Password', 'secret99')
            ->postJson("/api/v1/projects/{$project['uuid']}/entries", [
                'entry_date' => now()->toDateString(), 'description' => 'Locked entry', 'direction' => 'debit', 'amount' => 100,
            ])->assertCreated();

        // Owner forgot: request pings admins; only an admin can issue the code.
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/request-password-reset")->assertOk();
        $this->assertEquals('project_reset_request', $admin->notifications()->first()->data['kind']);
        $this->actingAs($this->user)->postJson("/api/v1/admin/projects/{$project['uuid']}/send-password-reset")->assertForbidden();

        $this->actingAs($admin)->postJson("/api/v1/admin/projects/{$project['uuid']}/send-password-reset")->assertOk();
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\ProjectPasswordResetCode::class);

        // Wrong code rejected; the real one resets the password.
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/reset-password", [
            'code' => '000000', 'new_password' => 'fresh123',
        ])->assertStatus(422);

        $code = null;
        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\ProjectPasswordResetCode::class, function ($mail) use (&$code) {
            $code = $mail->code;
            return true;
        });
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/reset-password", [
            'code' => $code, 'new_password' => 'fresh123',
        ])->assertOk();
        $this->actingAs($this->user)
            ->withHeader('X-Project-Password', 'fresh123')
            ->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertOk();
        $this->actingAs($this->user)
            ->withHeader('X-Project-Password', 'secret99')
            ->getJson("/api/v1/projects/{$project['uuid']}/entries")->assertStatus(423);
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

    public function test_the_summary_splits_by_person_and_the_two_views_agree(): void
    {
        $appIds = app(\App\Services\AppIdService::class);
        $mate = User::factory()->create(['name' => 'Harsh', 'username' => 'harsh1']);
        foreach ([$this->user, $mate] as $u) {
            $appIds->generateFor($u);
        }

        $project = $this->actingAs($this->user)->postJson('/api/v1/projects', ['name' => 'Home work'])->json('data');
        $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/share", [
            'app_id' => 'harsh1', 'permission' => 'edit',
        ])->assertOk();

        $add = fn (User $who, string $direction, float $amount) => $this->actingAs($who)
            ->postJson("/api/v1/projects/{$project['uuid']}/entries", [
                'entry_date' => now()->toDateString(), 'description' => 'Person entry',
                'direction' => $direction, 'amount' => $amount, 'currency' => 'INR', 'mode' => 'cash',
            ])->assertCreated();

        $add($mate, 'credit', 8000);
        $add($this->user, 'debit', 4000);
        $add($this->user, 'debit', 2000);

        $inr = collect($this->actingAs($this->user)->getJson("/api/v1/projects/{$project['uuid']}/summary")
            ->assertOk()->json('data'))->firstWhere('currency', 'INR');

        // The whole project, as before.
        $this->assertEquals(8000.0, $inr['credit']);
        $this->assertEquals(6000.0, $inr['debit']);
        $this->assertEquals(2000.0, $inr['net']);

        // The same money, per person - two people, named, with their own sides.
        $people = collect($inr['people'])->keyBy('name');
        $this->assertCount(2, $people);
        $this->assertEquals(8000.0, $people['Harsh']['credit']);
        $this->assertEquals(0.0, $people['Harsh']['debit']);
        $this->assertEquals(6000.0, $people[$this->user->name]['debit']);
        $this->assertSame(2, $people[$this->user->name]['entries']);

        // The point of the second box: it adds up to the first one.
        $this->assertEquals($inr['credit'], collect($inr['people'])->sum('credit'));
        $this->assertEquals($inr['debit'], collect($inr['people'])->sum('debit'));
        $this->assertEquals($inr['net'], collect($inr['people'])->sum('net'));

        // And a filter narrows both halves together.
        $filtered = collect($this->actingAs($this->user)
            ->getJson("/api/v1/projects/{$project['uuid']}/summary?direction=debit")
            ->assertOk()->json('data'))->firstWhere('currency', 'INR');
        $this->assertCount(1, $filtered['people']);
        $this->assertEquals($filtered['debit'], collect($filtered['people'])->sum('debit'));
    }

    public function test_the_ledger_can_be_read_as_one_persons_or_severals(): void
    {
        $appIds = app(\App\Services\AppIdService::class);
        $mate = User::factory()->create(['name' => 'Harsh', 'username' => 'harsh2']);
        $third = User::factory()->create(['name' => 'Third', 'username' => 'third2']);
        foreach ([$this->user, $mate, $third] as $u) {
            $appIds->generateFor($u);
        }

        $project = $this->actingAs($this->user)->postJson('/api/v1/projects', ['name' => 'Whose money'])->json('data');
        foreach (['harsh2', 'third2'] as $appId) {
            $this->actingAs($this->user)->postJson("/api/v1/projects/{$project['uuid']}/share", [
                'app_id' => $appId, 'permission' => 'edit',
            ])->assertOk();
        }

        $add = fn (User $who, float $amount) => $this->actingAs($who)
            ->postJson("/api/v1/projects/{$project['uuid']}/entries", [
                'entry_date' => now()->toDateString(), 'description' => 'Entry',
                'direction' => 'credit', 'amount' => $amount, 'currency' => 'INR', 'mode' => 'cash',
            ])->assertCreated();

        $add($mate, 8000);
        $add($third, 500);
        $add($this->user, 1500);

        $url = "/api/v1/projects/{$project['uuid']}";

        // One person: their entries, and totals that are only theirs.
        $one = $this->actingAs($this->user)->getJson($url . '/summary?people=' . $mate->uuid)
            ->assertOk()->json();
        $this->assertEquals(8000.0, collect($one['data'])->firstWhere('currency', 'INR')['credit']);
        $this->assertCount(1, $this->actingAs($this->user)->getJson($url . '/entries?people=' . $mate->uuid)->json('data'));

        // Two people at once - the pair read together, the third left out.
        $two = collect($this->actingAs($this->user)
            ->getJson($url . '/summary?people=' . $mate->uuid . ',' . $third->uuid)
            ->assertOk()->json('data'))->firstWhere('currency', 'INR');
        $this->assertEquals(8500.0, $two['credit']);
        $this->assertCount(2, $two['people']);
        $this->assertEquals($two['credit'], collect($two['people'])->sum('credit'));

        // The picker keeps everybody, so a chosen name can be unchosen.
        $this->assertCount(3, $one['contributors']);
    }
}
