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
