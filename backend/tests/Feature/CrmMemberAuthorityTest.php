<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may change whose account.
 *
 * The Admin may act on anybody (short of removing the last Admin). Everybody
 * else acts only downwards, on employees: never on a peer Subadmin, never on
 * the Admin, never on themselves. Found the hard way, when one Subadmin
 * deactivated another.
 */
class CrmMemberAuthorityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Member> */
    private array $members = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);

        foreach ([
            'admin' => 'admin',
            'deputy' => 'admin',
            'priyanshu' => 'subadmin',
            'manisha' => 'subadmin',
            'kanika' => 'employee',
        ] as $key => $role) {
            $user = User::factory()->create(['name' => ucfirst($key)]);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'Asia/Kolkata']);

            $this->users[$key] = $user;
            $this->members[$key] = Member::create([
                'organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => 'active',
                // Reading the Users screen is a module right, as a company grants it.
                'rights' => $role === 'subadmin' ? ['employees' => ['view']] : null,
            ]);
        }
    }

    private function url(string $key, string $suffix = ''): string
    {
        return '/api/v1/crm/employees/' . $this->members[$key]->uuid . $suffix;
    }

    public function test_a_subadmin_cannot_deactivate_or_edit_a_peer_or_the_admin(): void
    {
        $priyanshu = $this->users['priyanshu'];

        // The Manisha case.
        $this->actingAs($priyanshu)->deleteJson($this->url('manisha'))->assertForbidden();
        // And the Admin - even with a second Admin in the company.
        $this->actingAs($priyanshu)->deleteJson($this->url('admin'))->assertForbidden();
        $this->actingAs($priyanshu)->putJson($this->url('admin'), ['status' => 'inactive'])->assertForbidden();
        $this->actingAs($priyanshu)->putJson($this->url('manisha'), ['designation' => 'Nobody'])->assertForbidden();

        // Nor anything else on the Admin's record: pay, documents.
        $this->actingAs($priyanshu)->postJson($this->url('admin', '/compensation/structures'), [])->assertForbidden();

        $this->assertSame('active', $this->members['manisha']->fresh()->status);
        $this->assertSame('active', $this->members['admin']->fresh()->status);

        // Your own record stays yours to update - but not to switch off.
        $this->actingAs($priyanshu)->putJson($this->url('priyanshu'), ['status' => 'inactive'])->assertOk();
        $this->assertSame('active', $this->members['priyanshu']->fresh()->status);
        $this->actingAs($priyanshu)->deleteJson($this->url('priyanshu'))->assertForbidden();
    }

    public function test_a_subadmin_still_manages_employees_and_the_admin_restores_anyone(): void
    {
        $priyanshu = $this->users['priyanshu'];

        $this->actingAs($priyanshu)->deleteJson($this->url('kanika'))->assertOk();
        $this->assertSame('inactive', $this->members['kanika']->fresh()->status);

        // The list says, row by row, what this reader may change.
        $rows = collect($this->actingAs($priyanshu)->getJson('/api/v1/crm/employees')->assertOk()->json('data'))->keyBy('uuid');
        $this->assertTrue($rows[$this->members['kanika']->uuid]['can_manage']);
        $this->assertFalse($rows[$this->members['admin']->uuid]['can_manage']);
        $this->assertFalse($rows[$this->members['manisha']->uuid]['can_manage']);

        $this->actingAs($priyanshu)->postJson($this->url('kanika', '/reactivate'))->assertOk();
        $this->assertSame('active', $this->members['kanika']->fresh()->status);
        $this->assertNull($this->members['kanika']->fresh()->resigned_at);

        // Manisha, switched off by a peer, comes back through the Admin.
        $this->members['manisha']->update(['status' => 'inactive', 'resigned_at' => now()->toDateString()]);
        $this->actingAs($priyanshu)->postJson($this->url('manisha', '/reactivate'))->assertForbidden();
        $this->actingAs($this->users['admin'])->postJson($this->url('manisha', '/reactivate'))->assertOk();

        $this->assertSame('active', $this->members['manisha']->fresh()->status);
        $this->assertTrue(ActivityLog::where('action', 'employee.reactivated')->exists());
    }

    public function test_the_admin_still_acts_on_anyone_but_the_last_admin_stays(): void
    {
        $admin = $this->users['admin'];

        $this->actingAs($admin)->deleteJson($this->url('manisha'))->assertOk();
        $this->actingAs($admin)->deleteJson($this->url('deputy'))->assertOk();

        // With the deputy gone, the Admin is the last one.
        $this->actingAs($admin)->deleteJson($this->url('admin'))->assertStatus(422);
    }
}
