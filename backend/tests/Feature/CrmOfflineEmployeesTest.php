<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\OfflineSalary;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Offline Employees: paid outside the payroll, counted in the P&L as Offline
 * Salary, and the Company Admin's alone.
 */
class CrmOfflineEmployeesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);

        foreach (['admin' => 'admin', 'priyanshu' => 'subadmin', 'satish' => 'employee'] as $key => $role) {
            $user = User::factory()->create(['name' => ucfirst($key)]);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'Asia/Kolkata']);
            $this->users[$key] = $user;

            Member::create([
                'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => 'active',
                // Even every pay right a Subadmin can hold does not open this screen.
                'capabilities' => $role === 'subadmin' ? ['salary.view_all', 'salary.export'] : null,
            ]);
        }
    }

    private function admin()
    {
        return $this->actingAs($this->users['admin']);
    }

    private function addPerson(string $code, string $name, float $amount, string $status = 'active'): string
    {
        return $this->admin()->postJson('/api/v1/crm/offline-employees', [
            'employee_code' => $code, 'name' => $name, 'monthly_amount' => $amount, 'status' => $status,
        ])->assertCreated()->json('data.uuid');
    }

    public function test_it_is_the_company_admins_alone(): void
    {
        foreach (['priyanshu', 'satish'] as $who) {
            $this->actingAs($this->users[$who])->getJson('/api/v1/crm/offline-employees')->assertForbidden();
            $this->actingAs($this->users[$who])->postJson('/api/v1/crm/offline-employees', [
                'employee_code' => 'OFF-1', 'name' => 'Ramesh', 'monthly_amount' => 9000,
            ])->assertForbidden();
            $this->actingAs($this->users[$who])->getJson('/api/v1/crm/offline-salaries')->assertForbidden();
        }
    }

    public function test_people_months_filters_and_the_p_and_l(): void
    {
        $ramesh = $this->addPerson('OFF-101', 'Ramesh Kumar', 9000);
        $this->addPerson('OFF-102', 'Sunita Devi', 7500);
        $this->addPerson('OFF-103', 'Left Last Year', 5000, 'inactive');

        // Employee IDs are unique within the company.
        $this->admin()->postJson('/api/v1/crm/offline-employees', [
            'employee_code' => 'OFF-101', 'name' => 'Duplicate', 'monthly_amount' => 1,
        ])->assertStatus(422);

        $people = $this->admin()->getJson('/api/v1/crm/offline-employees')->assertOk();
        $this->assertSame(2, $people->json('totals.active'));
        $this->assertEquals(16500, $people->json('totals.monthly'));

        // A month for everybody active, at their monthly amount - and only once.
        $this->admin()->postJson('/api/v1/crm/offline-salaries/generate', ['year' => 2026, 'month' => 8])
            ->assertOk()->assertJsonPath('created', 2);
        $this->admin()->postJson('/api/v1/crm/offline-salaries/generate', ['year' => 2026, 'month' => 8])
            ->assertOk()->assertJsonPath('created', 0);

        // One by hand for September, and a duplicate refused.
        $this->admin()->postJson('/api/v1/crm/offline-salaries', [
            'offline_employee_uuid' => $ramesh, 'year' => 2026, 'month' => 9, 'amount' => 9500, 'note' => 'Diwali extra',
        ])->assertCreated();
        $this->admin()->postJson('/api/v1/crm/offline-salaries', [
            'offline_employee_uuid' => $ramesh, 'year' => 2026, 'month' => 9, 'amount' => 1,
        ])->assertStatus(422);

        // Editing an amount.
        $sunitaAugust = OfflineSalary::whereHas('employee', fn ($e) => $e->where('name', 'Sunita Devi'))->firstOrFail();
        $this->admin()->putJson('/api/v1/crm/offline-salaries/' . $sunitaAugust->uuid, ['amount' => 8000])->assertOk();

        // Filters: a month range, and a name.
        $august = $this->admin()->getJson('/api/v1/crm/offline-salaries?month_from=2026-08&month_to=2026-08')->assertOk();
        $this->assertSame(2, $august->json('totals.count'));
        $this->assertEquals(17000, $august->json('totals.amount'));

        $rameshOnly = $this->admin()->getJson('/api/v1/crm/offline-salaries?month_from=2026-08&month_to=2026-09&search=Ramesh')->assertOk();
        $this->assertSame(2, $rameshOnly->json('totals.count'));
        $this->assertEquals(18500, $rameshOnly->json('totals.amount'));

        // The P&L carries each month under its own head.
        $pl = $this->admin()->getJson('/api/v1/crm/pl?month_from=2026-08&month_to=2026-09')->assertOk();
        $lines = collect($pl->json('data.months'))->mapWithKeys(fn ($m) => [
            $m['month'] => collect($m['expenses'])->firstWhere('label', 'Offline Salary'),
        ]);
        $this->assertEquals(17000, $lines['2026-08']['amount']);
        $this->assertEquals(9500, $lines['2026-09']['amount']);

        // And the payroll never sees them.
        $this->assertCount(0, $this->admin()->getJson('/api/v1/crm/salary?year=2026&month=8')->json('data'));

        // Deleting a person takes their months with them, on the record.
        $this->admin()->deleteJson('/api/v1/crm/offline-employees/' . $ramesh)->assertOk();
        $this->assertSame(1, OfflineSalary::count());
        $this->assertTrue(ActivityLog::where('action', 'offline_employee.deleted')->exists());
    }
}
