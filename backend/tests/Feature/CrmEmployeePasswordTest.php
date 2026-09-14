<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Resetting a password from Users: your own for everybody, anybody's for the
 * Company Admin, and nobody else's for a Subadmin.
 */
class CrmEmployeePasswordTest extends TestCase
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
        Notification::fake();

        $org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);

        foreach ([
            'admin' => 'admin',
            'deputy' => 'admin',
            'priyanshu' => 'subadmin',
            'sanjeev' => 'employee',
            'kanika' => 'employee',
        ] as $key => $role) {
            $user = User::factory()->create(['name' => ucfirst($key), 'password' => 'OldPass123']);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'Asia/Kolkata']);

            $this->users[$key] = $user;
            $this->members[$key] = Member::create([
                'organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => 'active',
            ]);
        }
    }

    private function url(string $key): string
    {
        return '/api/v1/crm/employees/' . $this->members[$key]->uuid . '/password';
    }

    public function test_everybody_changes_their_own_with_the_current_password(): void
    {
        foreach (['sanjeev', 'priyanshu'] as $who) {
            $this->actingAs($this->users[$who])->postJson($this->url($who), [
                'current_password' => 'wrong-one', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456',
            ])->assertStatus(422);

            $this->actingAs($this->users[$who])->postJson($this->url($who), [
                'current_password' => 'OldPass123', 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456',
            ])->assertOk();

            $this->assertTrue(Hash::check('NewPass456', $this->users[$who]->fresh()->password));
            $this->assertFalse((bool) $this->users[$who]->fresh()->force_password_change);
        }

        $this->assertTrue(ActivityLog::where('action', 'employee.password_changed')->exists());
    }

    public function test_nobody_but_the_admin_resets_somebody_else(): void
    {
        $payload = ['password' => 'Hijack789', 'password_confirmation' => 'Hijack789'];

        // An employee, another employee.
        $this->actingAs($this->users['sanjeev'])->postJson($this->url('kanika'), $payload)->assertForbidden();
        // A Subadmin, an employee - not theirs to reset.
        $this->actingAs($this->users['priyanshu'])->postJson($this->url('sanjeev'), $payload)->assertForbidden();
        // A Subadmin, the Admin.
        $this->actingAs($this->users['priyanshu'])->postJson($this->url('admin'), $payload)->assertForbidden();

        foreach (['kanika', 'sanjeev', 'admin'] as $who) {
            $this->assertTrue(Hash::check('OldPass123', $this->users[$who]->fresh()->password));
        }
    }

    public function test_the_admin_resets_anyone_and_they_are_signed_out_and_asked_to_change_it(): void
    {
        foreach (['sanjeev', 'priyanshu', 'deputy'] as $who) {
            $this->users[$who]->createToken('phone');

            $this->actingAs($this->users['admin'])->postJson($this->url($who), [
                'password' => 'Fresh2026', 'password_confirmation' => 'Fresh2026',
            ])->assertOk();

            $fresh = $this->users[$who]->fresh();
            $this->assertTrue(Hash::check('Fresh2026', $fresh->password));
            $this->assertTrue((bool) $fresh->force_password_change);
            $this->assertSame(0, $fresh->tokens()->count());
        }

        $this->assertSame(3, ActivityLog::where('action', 'employee.password_reset')->count());

        // Weak or unconfirmed passwords are refused.
        $this->actingAs($this->users['admin'])->postJson($this->url('kanika'), [
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422);
    }

    public function test_an_account_with_a_platform_role_is_not_the_companys_to_reset(): void
    {
        $this->users['kanika']->roles()->attach(Role::where('slug', 'admin')->first()->id);

        $this->actingAs($this->users['admin'])->postJson($this->url('kanika'), [
            'password' => 'Fresh2026', 'password_confirmation' => 'Fresh2026',
        ])->assertForbidden();
    }
}
