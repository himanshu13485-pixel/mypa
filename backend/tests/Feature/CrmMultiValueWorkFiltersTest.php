<?php

namespace Tests\Feature;

use App\Models\Crm\Approval;
use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkbox filters on the work and HR screens: several values at once, the
 * older single value still accepted, and nothing ticked matching nothing.
 */
class CrmMultiValueWorkFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private Member $admin;

    private Member $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = User::factory()->create(['name' => 'Boss']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);
        $this->staff = $this->member('Priyanshu', 'employee', 'active');
    }

    private function member(string $name, string $role, string $status): Member
    {
        $user = User::factory()->create(['name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => $status,
        ]);
    }

    /** @return list<string> */
    private function pluck(string $url, string $key): array
    {
        return collect($this->actingAs($this->adminUser)->getJson($url)->assertOk()->json('data'))
            ->pluck($key)->sort()->values()->all();
    }

    public function test_tasks_filter_by_several_statuses_and_priorities(): void
    {
        foreach ([['A', 'open', 'low'], ['B', 'in_progress', 'high'], ['C', 'done', 'urgent']] as [$title, $status, $priority]) {
            Task::create([
                'organization_id' => $this->org->id,
                'title' => $title,
                'assigned_member_id' => $this->staff->id,
                'assigned_by' => $this->admin->id,
                'kind' => 'task',
                'priority' => $priority,
                'status' => $status,
            ]);
        }

        $this->assertSame(['A', 'B', 'C'], $this->pluck('/api/v1/crm/tasks', 'title'));
        $this->assertSame(['A', 'C'], $this->pluck('/api/v1/crm/tasks?' . http_build_query(['status' => ['open', 'done']]), 'title'));
        $this->assertSame(['B'], $this->pluck('/api/v1/crm/tasks?status=in_progress', 'title'));
        $this->assertSame(['B', 'C'], $this->pluck('/api/v1/crm/tasks?' . http_build_query(['priority' => ['high', 'urgent']]), 'title'));
        $this->assertSame([], $this->pluck('/api/v1/crm/tasks?' . http_build_query(['status' => ['__none__']]), 'title'));
    }

    public function test_leaves_filter_by_several_statuses_and_categories_for_the_company_admin(): void
    {
        foreach ([['sick', 'Sick Leave', 'pending'], ['casual', 'Casual Leave', 'approved'], ['earned', 'Earned Leave', 'rejected']] as [$reason, $category, $status]) {
            Leave::create([
                'organization_id' => $this->org->id,
                'member_id' => $this->staff->id,
                'category' => $category,
                'duration' => 'full',
                'date_from' => '2026-09-20',
                'date_to' => '2026-09-20',
                'days' => 1,
                'reason' => $reason,
                'status' => $status,
            ]);
        }

        $this->assertSame(['casual', 'earned', 'sick'], $this->pluck('/api/v1/crm/leaves', 'reason'));
        $this->assertSame(['casual', 'sick'], $this->pluck('/api/v1/crm/leaves?' . http_build_query(['status' => ['pending', 'approved']]), 'reason'));
        $this->assertSame(['earned', 'sick'], $this->pluck('/api/v1/crm/leaves?' . http_build_query(['category' => ['Sick Leave', 'Earned Leave']]), 'reason'));
        $this->assertSame(['casual'], $this->pluck('/api/v1/crm/leaves?category=Casual%20Leave', 'reason'));
        $this->assertSame([], $this->pluck('/api/v1/crm/leaves?' . http_build_query(['category' => ['__none__']]), 'reason'));
    }

    public function test_employees_filter_by_several_roles_and_statuses(): void
    {
        $this->member('Sub', 'subadmin', 'active');
        $this->member('Gone', 'employee', 'inactive');

        $codes = fn (array $query) => collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees?' . http_build_query($query))->assertOk()->json('data'))
            ->map(fn ($m) => $m['crm_role'] . ':' . $m['status'])->sort()->values()->all();

        $this->assertSame(
            ['employee:active', 'subadmin:active'],
            $codes(['crm_role' => ['employee', 'subadmin'], 'status' => ['active']]),
        );
        $this->assertSame(
            ['employee:active', 'employee:inactive'],
            $codes(['crm_role' => 'employee', 'status' => ['active', 'inactive']]),
        );
        $this->assertSame([], $codes(['crm_role' => ['__none__']]));
    }

    public function test_approvals_filter_by_several_statuses_and_types(): void
    {
        foreach ([['Travel', 'pending', 'one'], ['Error', 'approved', 'two'], ['Travel', 'rejected', 'three']] as [$type, $status, $details]) {
            Approval::create([
                'organization_id' => $this->org->id,
                'type' => $type,
                'scope' => 'general',
                'approval_date' => '2026-09-10',
                'amount' => 100,
                'details' => $details,
                'requested_by' => $this->staff->id,
                'status' => $status,
            ]);
        }

        $this->assertSame(['one', 'three', 'two'], $this->pluck('/api/v1/crm/approvals', 'details'));
        $this->assertSame(['one', 'two'], $this->pluck('/api/v1/crm/approvals?' . http_build_query(['status' => ['pending', 'approved']]), 'details'));
        $this->assertSame(['one', 'three'], $this->pluck('/api/v1/crm/approvals?type=Travel', 'details'));
        $this->assertSame([], $this->pluck('/api/v1/crm/approvals?' . http_build_query(['type' => ['__none__']]), 'details'));
    }
}
