<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The last heads: Leaves, Tasks, Approvals, Invoice Updates, Newsletters,
 * CMS, Reports/User Log. What matters: computed leave days, nobody deciding
 * their own request, the task approval loop, an invoice changing only
 * through an approved diff, honest newsletter counts, and drafts staying
 * invisible to non-editors.
 */
class CrmFinalModulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $employeeUser;
    protected Organization $org;
    protected Member $adminMember;
    protected Member $employeeMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->employeeUser = $this->makeUser('emp@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->employeeMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->employeeUser->id, 'crm_role' => 'employee',
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    public function test_leave_days_are_computed_and_nobody_decides_their_own(): void
    {
        // 3-day span at half day = 1.5 days.
        $leave = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Sick Leave',
            'duration' => 'half',
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-03',
            'reason' => 'Fever',
        ])->assertCreated();
        $uuid = $leave->json('data.uuid');
        $this->assertEquals(1.5, $leave->json('data.days'));

        // The employee cannot decide anything (no leaves,edit)…
        $this->actingAs($this->employeeUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])
            ->assertForbidden();

        // …the admin can, but not on their own requests.
        $own = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'full',
            'date_from' => '2026-09-05', 'date_to' => '2026-09-05',
        ])->json('data.uuid');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leaves/{$own}/decide", ['status' => 'approved'])
            ->assertStatus(422);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", [
            'status' => 'approved', 'note' => 'Get well soon',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        // The employee's list shows only their own; summary counts ride along.
        $mine = $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/leaves')->assertOk();
        $this->assertSame(1, $mine->json('total'));
        $this->assertEquals(1.5, $mine->json('summary.approved_days'));
    }

    public function test_the_task_approval_loop(): void
    {
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'Prepare August MIS',
            'assigned_member_uuid' => $this->employeeMember->uuid,
            'priority' => 'high',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()->json('data.uuid');

        // Employees cannot create tasks.
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'X', 'assigned_member_uuid' => $this->adminMember->uuid,
        ])->assertForbidden();

        // The assignee works it, submits, and only then can it be reviewed.
        $this->actingAs($this->employeeUser)->postJson("/api/v1/crm/tasks/{$uuid}/progress", ['status' => 'in_progress'])->assertOk();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/tasks/{$uuid}/review", ['verdict' => 'approve'])->assertStatus(422);
        $this->actingAs($this->employeeUser)->postJson("/api/v1/crm/tasks/{$uuid}/progress", [
            'status' => 'submitted', 'note' => 'MIS ready in the shared drive',
        ])->assertOk();

        // Rejected → reopened; resubmitted → approved → done.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/tasks/{$uuid}/review", [
            'verdict' => 'reject', 'note' => 'Numbers missing for week 3',
        ])->assertOk()->assertJsonPath('data.status', 'reopened');
        $this->actingAs($this->employeeUser)->postJson("/api/v1/crm/tasks/{$uuid}/progress", ['status' => 'submitted'])->assertOk();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/tasks/{$uuid}/review", ['verdict' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'done');
    }

    public function test_an_invoice_changes_only_through_an_approved_diff(): void
    {
        $client = Client::create(['organization_id' => $this->org->id, 'company_name' => 'Diff Client']);
        $company = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);
        $invoice = Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => 'INV-77',
            'issuing_company_id' => $company->id, 'client_id' => $client->id,
            // Credited to the employee: since invoices are scoped to their
            // own ledger, a document belonging to nobody is not theirs to
            // propose changes to.
            'member_id' => $this->employeeMember->id,
            'invoice_date' => now()->toDateString(), 'total' => 1000, 'dispatch_status' => 'pending',
        ]);

        // Request with a non-editable field smuggled in: it is stripped.
        $req = $this->actingAs($this->employeeUser)->postJson("/api/v1/crm/invoices/{$invoice->uuid}/update-request", [
            'changes' => ['dispatch_status' => 'dispatched', 'total' => 1],
            'reason' => 'Courier delivered on the 20th',
        ])->assertCreated();
        $this->assertSame(['dispatch_status' => 'dispatched'], $req->json('data.changes'));
        $uuid = $req->json('data.uuid');

        // A second pending request for the same invoice is refused.
        $this->actingAs($this->employeeUser)->postJson("/api/v1/crm/invoices/{$invoice->uuid}/update-request", [
            'changes' => ['notes' => 'x'],
        ])->assertStatus(422);

        // Nothing changed yet; approval applies the diff.
        $this->assertSame('pending', $invoice->fresh()->dispatch_status);
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoice-updates/{$uuid}/decide", ['status' => 'approved'])->assertOk();
        $this->assertSame('dispatched', $invoice->fresh()->dispatch_status);
        $this->assertEquals(1000, (float) $invoice->fresh()->total);

        // The approvals inbox counted it while pending — now it is zero.
        $inbox = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/approvals')->json('inbox');
        $this->assertSame(0, $inbox['invoice_updates']);
    }

    public function test_approval_register_and_inbox_counts(): void
    {
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'Executive Error',
            'approval_date' => now()->toDateString(),
            'amount' => 2500,
            'details' => 'Wrong plan billed, needs write-off',
        ])->assertCreated();
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'full',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
        ]);

        $res = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/approvals')->assertOk();
        $this->assertSame(1, $res->json('summary.pending'));
        $this->assertEquals(2500, $res->json('summary.pending_amount'));
        $this->assertSame(1, $res->json('inbox.leaves'));

        // Decide it; the employee sees only their own requests either way.
        $uuid = $res->json('data.0.uuid');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/approvals/{$uuid}/decide", [
            'status' => 'approved', 'note' => 'One-time write-off',
        ])->assertOk();
        $this->assertSame(1, $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/approvals')->json('total'));
    }

    public function test_newsletter_sends_to_deduplicated_client_emails(): void
    {
        Mail::fake();
        Client::create(['organization_id' => $this->org->id, 'company_name' => 'A', 'email' => 'a@x.test']);
        Client::create(['organization_id' => $this->org->id, 'company_name' => 'B', 'email' => 'A@x.test']); // dupe, case
        Client::create(['organization_id' => $this->org->id, 'company_name' => 'C', 'email' => 'c@x.test', 'status' => 'inactive']);
        Client::create(['organization_id' => $this->org->id, 'company_name' => 'D']); // no email

        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/newsletters', [
            'subject' => 'August offers',
            'body' => '<h1>Hello</h1><p>New plans are live.</p>',
            'audience' => 'active_clients',
        ])->assertCreated()->json('data.uuid');

        $sent = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/newsletters/{$uuid}/send")->assertOk();
        $this->assertSame(1, $sent->json('data.sent_count')); // a@x.test deduped, inactive + empty skipped
        $this->assertSame('sent', $sent->json('data.status'));

        // Sent newsletters are frozen.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/newsletters/{$uuid}", [
            'subject' => 'x', 'body' => 'y', 'audience' => 'leads',
        ])->assertStatus(422);
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/newsletters/{$uuid}/send")->assertStatus(422);
    }

    public function test_cms_drafts_hide_from_non_editors_and_reports_need_rights(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/cms', [
            'title' => 'Diwali holidays', 'body' => 'Office closed 29-31 Oct.', 'kind' => 'holiday', 'status' => 'published',
        ])->assertCreated();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/cms', [
            'title' => 'Draft policy', 'body' => 'WIP', 'kind' => 'policy', 'status' => 'draft',
        ])->assertCreated();

        // The employee sees only the published post and cannot post.
        $list = $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/cms')->assertOk();
        $this->assertSame(1, $list->json('total'));
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/cms', [
            'title' => 'x', 'body' => 'y', 'kind' => 'news', 'status' => 'published',
        ])->assertForbidden();

        // Reports and the user log are rights-gated; the admin gets data.
        $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/reports/overview')->assertForbidden();
        $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/user-log')->assertForbidden();
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/reports/overview')->assertOk()
            ->assertJsonStructure(['data' => ['monthly', 'totals', 'invoice_status', 'lead_funnel']]);
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/user-log')->assertOk();
    }
}
