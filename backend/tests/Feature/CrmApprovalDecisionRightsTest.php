<?php

namespace Tests\Feature;

use App\Models\Crm\Approval;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deciding other people's approval requests.
 *
 * The Company Admin, and a Subadmin the Admin named with approvals.manage_all.
 * Nobody else - not an employee with every approvals tick, and not a Subadmin
 * by being one. They see, and act on, their own requests.
 */
class CrmApprovalDecisionRightsTest extends TestCase
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
            'admin' => ['admin', null],
            'priyanshu' => ['subadmin', ['approvals' => ['view', 'create', 'edit'], 'invoices' => ['view', 'edit']]],
            'satish' => ['employee', ['approvals' => ['view', 'create', 'edit'], 'invoices' => ['view', 'edit']]],
            'vishal' => ['employee', ['approvals' => ['view', 'create']]],
        ] as $key => [$role, $rights]) {
            $user = User::factory()->create(['name' => ucfirst($key)]);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'Asia/Kolkata']);

            $this->users[$key] = $user;
            $this->members[$key] = Member::create([
                'organization_id' => $this->org->id, 'user_id' => $user->id,
                'crm_role' => $role, 'status' => 'active', 'rights' => $rights,
            ]);
        }
    }

    private function vishalsRequest(): Approval
    {
        return Approval::create([
            'organization_id' => $this->org->id, 'type' => 'Office Recharge', 'scope' => 'general',
            'approval_date' => '2026-09-14', 'amount' => 379, 'details' => '2 SIM recharge',
            'requested_by' => $this->members['vishal']->id,
        ]);
    }

    public function test_an_employee_with_every_approvals_tick_sees_and_decides_only_their_own(): void
    {
        $request = $this->vishalsRequest();

        $this->assertCount(0, $this->actingAs($this->users['satish'])->getJson('/api/v1/crm/approvals')->assertOk()->json('data'));
        $this->actingAs($this->users['satish'])
            ->postJson("/api/v1/crm/approvals/{$request->uuid}/decide", ['status' => 'rejected'])
            ->assertForbidden();
        $this->assertFalse($this->actingAs($this->users['satish'])->getJson('/api/v1/crm/me')->json('data.member.can_decide_approvals'));

        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_a_subadmin_decides_once_the_admin_names_them(): void
    {
        $request = $this->vishalsRequest();
        $priyanshu = $this->users['priyanshu'];

        // Unnamed: only their own - and they have none.
        $this->assertCount(0, $this->actingAs($priyanshu)->getJson('/api/v1/crm/approvals')->json('data'));
        $this->actingAs($priyanshu)->postJson("/api/v1/crm/approvals/{$request->uuid}/decide", ['status' => 'approved'])
            ->assertForbidden();

        $this->members['priyanshu']->update(['capabilities' => ['approvals.manage_all']]);

        $this->assertCount(1, $this->actingAs($priyanshu)->getJson('/api/v1/crm/approvals')->json('data'));
        $this->actingAs($priyanshu)->postJson("/api/v1/crm/approvals/{$request->uuid}/decide", ['status' => 'approved'])
            ->assertOk();
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertTrue($this->actingAs($priyanshu)->getJson('/api/v1/crm/me')->json('data.member.can_decide_approvals'));
    }

    public function test_the_admin_sees_and_decides_everyone(): void
    {
        $request = $this->vishalsRequest();

        $this->assertCount(1, $this->actingAs($this->users['admin'])->getJson('/api/v1/crm/approvals')->json('data'));
        $this->actingAs($this->users['admin'])->postJson("/api/v1/crm/approvals/{$request->uuid}/decide", ['status' => 'rejected'])
            ->assertOk();

        // And the requester still sees their own, decided.
        $mine = $this->actingAs($this->users['vishal'])->getJson('/api/v1/crm/approvals')->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame('rejected', $mine[0]['status']);
    }
}
