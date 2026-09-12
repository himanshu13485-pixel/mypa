<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Where a report lands, and who may act on it.
 *
 * Between two people in the same company: that company's Admin, who can
 * dismiss, warn, take the message down, or hand it up. Anybody else: Netvork.
 * Netvork sees every report either way, and escalated ones first.
 */
class ReportRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $alice;
    protected User $bob;
    protected User $outsider;
    protected User $superAdmin;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $appIds = app(\App\Services\AppIdService::class);

        $make = function (string $name) use ($appIds) {
            $u = User::factory()->create(['name' => $name]);
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            $appIds->generateFor($u);

            return $u;
        };

        $this->adminUser = $make('Company Admin');
        $this->alice = $make('Alice');
        $this->bob = $make('Bob');
        $this->outsider = $make('Stranger');
        $this->superAdmin = $make('Netvork');
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        foreach ([[$this->adminUser, 'admin'], [$this->alice, 'employee'], [$this->bob, 'employee']] as [$u, $role]) {
            Member::create(['organization_id' => $this->org->id, 'user_id' => $u->id, 'crm_role' => $role, 'status' => 'active']);
        }
    }

    private function report(User $by, User $about): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($by)->postJson('/api/v1/reports', [
            'identifier' => $about->appId->app_id,
            'reason' => 'spam',
        ]);
    }

    public function test_a_report_inside_a_company_goes_to_its_admin(): void
    {
        Notification::fake();

        $this->report($this->alice, $this->bob)->assertCreated()
            ->assertJsonPath('message', 'Report sent to your company admin, who will review it.');

        $report = Report::firstOrFail();
        $this->assertSame($this->org->id, $report->organization_id);

        // The Admin is told, and sees it in the company's queue.
        Notification::assertSentTo($this->adminUser, \App\Notifications\CrmNotification::class);

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/reports-queue')
            ->assertOk()
            ->assertJsonPath('data.0.reporter.name', 'Alice')
            ->assertJsonPath('data.0.reported_user.name', 'Bob');

        // An employee does not.
        $this->actingAs($this->bob)->getJson('/api/v1/crm/reports-queue')->assertForbidden();

        // Netvork sees it too, labelled with the company.
        $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/reports')
            ->assertOk()->assertJsonPath('data.0.organization.name', 'Acme Pvt Ltd');
    }

    public function test_a_report_from_outside_the_company_goes_to_netvork(): void
    {
        $this->report($this->outsider, $this->bob)->assertCreated()
            ->assertJsonPath('message', 'Report submitted. Netvork will review it — thank you for keeping the community safe.');

        $this->assertNull(Report::firstOrFail()->organization_id);

        // Not the company's business: it does not appear in their queue.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/reports-queue')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_company_can_dismiss_or_warn_but_hands_suspension_up(): void
    {
        $this->report($this->alice, $this->bob)->assertCreated();
        $uuid = Report::firstOrFail()->uuid;

        // A company has no suspend - the account is not the company's.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/reports-queue/{$uuid}/act", ['action' => 'suspend'])
            ->assertStatus(422);

        // Escalating needs a reason, because it is what Netvork reads first.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/reports-queue/{$uuid}/act", ['action' => 'escalate'])
            ->assertStatus(422);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/reports-queue/{$uuid}/act", [
            'action' => 'escalate',
            'note' => 'Repeated scam links to our clients.',
        ])->assertOk()->assertJsonPath('data.escalated_by', 'Company Admin');

        // Still open - nothing has been done yet - and no longer the company's to act on.
        $this->assertSame('open', Report::firstOrFail()->status);
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/reports-queue/{$uuid}/act", ['action' => 'warn'])
            ->assertStatus(409);

        // Netvork's escalated view leads with it.
        $this->actingAs($this->superAdmin)->getJson('/api/v1/admin/reports?status=escalated')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $uuid)
            ->assertJsonPath('data.0.escalation_note', 'Repeated scam links to our clients.');
    }

    public function test_warning_closes_the_report_and_tells_both_sides(): void
    {
        Notification::fake();

        $this->report($this->alice, $this->bob)->assertCreated();
        $uuid = Report::firstOrFail()->uuid;

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/reports-queue/{$uuid}/act", [
            'action' => 'warn', 'note' => 'Keep the office group for work.',
        ])->assertOk();

        $report = Report::firstOrFail();
        $this->assertSame('actioned', $report->status);
        $this->assertSame('warned', $report->action_taken);

        Notification::assertSentTo($this->bob, \App\Notifications\SocialNotification::class,
            fn ($n) => $n->kind === 'moderation_warning');
        Notification::assertSentTo($this->alice, \App\Notifications\SocialNotification::class,
            fn ($n) => $n->kind === 'report_resolved');
    }
}
