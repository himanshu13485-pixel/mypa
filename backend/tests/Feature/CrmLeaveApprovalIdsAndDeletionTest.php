<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Approval;
use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalarySlip;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leave and approval ids people can quote, and the Company Admin removing
 * the ones made by mistake or for a trial.
 */
class CrmLeaveApprovalIdsAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Member> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);

        foreach ([
            'admin' => 'admin',
            'priyanshu' => 'subadmin',
            'satish' => 'employee',
        ] as $key => $role) {
            $user = User::factory()->create(['name' => ucfirst($key)]);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'Asia/Kolkata']);

            $this->users[$key] = $user;
            $this->members[$key] = Member::create([
                'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => 'active',
                // Named with every right a Subadmin could hold - deleting is still not one of them.
                'capabilities' => $role === 'subadmin' ? ['leaves.manage_all', 'approvals.manage_all'] : null,
            ]);
        }
    }

    private function leave(): string
    {
        return $this->actingAs($this->users['satish'])->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'full',
            'date_from' => '2026-10-01', 'date_to' => '2026-10-01', 'reason' => 'Trial entry',
        ])->assertCreated()->json('data.uuid');
    }

    private function approval(array $overrides = []): Approval
    {
        return Approval::create($overrides + [
            'organization_id' => $this->org->id, 'type' => 'Office Recharge', 'scope' => 'general',
            'approval_date' => '2026-09-14', 'amount' => 379, 'details' => 'Trial',
            'requested_by' => $this->members['satish']->id,
        ]);
    }

    public function test_leaves_and_approvals_carry_an_id(): void
    {
        $uuid = $this->leave();
        $leave = Leave::where('uuid', $uuid)->firstOrFail();
        $approval = $this->approval();

        $row = collect($this->actingAs($this->users['admin'])->getJson('/api/v1/crm/leaves')->json('data'))->firstWhere('uuid', $uuid);
        $this->assertSame('LV-' . str_pad((string) $leave->id, 6, '0', STR_PAD_LEFT), $row['leave_no']);

        $row = collect($this->actingAs($this->users['admin'])->getJson('/api/v1/crm/approvals')->json('data'))->firstWhere('uuid', $approval->uuid);
        $this->assertSame('APR-' . str_pad((string) $approval->id, 6, '0', STR_PAD_LEFT), $row['approval_no']);
    }

    public function test_only_the_company_admin_deletes_a_leave_and_the_days_go_back(): void
    {
        $uuid = $this->leave();
        $this->actingAs($this->users['admin'])->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])->assertOk();
        $leaveId = Leave::where('uuid', $uuid)->value('id');

        $this->actingAs($this->users['satish'])->deleteJson("/api/v1/crm/leaves/{$uuid}/permanent")->assertForbidden();
        $this->actingAs($this->users['priyanshu'])->deleteJson("/api/v1/crm/leaves/{$uuid}/permanent")->assertForbidden();

        $this->actingAs($this->users['admin'])->deleteJson("/api/v1/crm/leaves/{$uuid}/permanent")->assertOk();

        $this->assertNull(Leave::find($leaveId));
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('crm_leave_ledger')->where('leave_id', $leaveId)->count());
        $this->assertTrue(ActivityLog::where('action', 'leave.deleted')->exists());
    }

    public function test_only_the_company_admin_deletes_an_approval_and_never_one_already_paid(): void
    {
        $approval = $this->approval();

        $this->actingAs($this->users['satish'])->deleteJson("/api/v1/crm/approvals/{$approval->uuid}")->assertForbidden();
        $this->actingAs($this->users['priyanshu'])->deleteJson("/api/v1/crm/approvals/{$approval->uuid}")->assertForbidden();

        $this->actingAs($this->users['admin'])->deleteJson("/api/v1/crm/approvals/{$approval->uuid}")->assertOk();
        $this->assertNull(Approval::find($approval->id));
        $this->assertTrue(ActivityLog::where('action', 'approval.deleted')->exists());

        // Paid back through salary: the slip has to go first.
        $slip = SalarySlip::create([
            'organization_id' => $this->org->id, 'member_id' => $this->members['satish']->id,
            'year' => 2026, 'month' => 9, 'monthly_salary' => 20000, 'payable' => 20000, 'net_salary' => 20379,
        ]);
        $paid = $this->approval(['status' => 'approved', 'reimbursed_slip_id' => $slip->id]);

        $this->actingAs($this->users['admin'])->deleteJson("/api/v1/crm/approvals/{$paid->uuid}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'APR-' . str_pad((string) $paid->id, 6, '0', STR_PAD_LEFT)
                . ' was paid back on the September 2026 salary slip. Delete or rebuild that slip first.']);
        $this->assertNotNull(Approval::find($paid->id));
    }
}
