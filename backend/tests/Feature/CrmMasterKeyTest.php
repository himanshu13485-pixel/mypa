<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\SocialNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * One password a company admin can put a locked-out employee back in with.
 *
 * The convenience is one button. Everything tested here is the other half —
 * the reasons this is not simply a back door into every account in the
 * company, each of which is a rule somebody would otherwise have to remember
 * to keep.
 */
class CrmMasterKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'Winter-Gate-77!';

    private Organization $org;
    private User $adminUser;
    private User $staffUser;
    private Member $admin;
    private Member $staff;
    private Member $subadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->staffUser = $this->makeUser('sales@acme.test');

        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->staff = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->staffUser->id, 'crm_role' => 'employee',
        ]);
        $this->subadmin = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->makeUser('deputy@acme.test')->id,
            'crm_role' => 'subadmin',
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => 'secret-password-1']);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function setKey(?User $who = null, string $ownPassword = 'secret-password-1')
    {
        return $this->actingAs($who ?? $this->adminUser)->putJson('/api/v1/crm/master-key', [
            'current_password' => $ownPassword,
            'master_key' => self::KEY,
            'master_key_confirmation' => self::KEY,
        ]);
    }

    public function test_an_admin_sets_the_key_by_proving_it_is_them(): void
    {
        $this->setKey()->assertOk();

        $this->assertTrue($this->org->fresh()->hasMasterKey());
        $this->assertSame(self::KEY, $this->org->fresh()->master_key);
    }

    public function test_a_hijacked_session_cannot_mint_one_without_the_password(): void
    {
        // The point of asking: taking over an admin's open tab must not be
        // enough to make a key that opens every account in the company.
        $this->setKey($this->adminUser, 'not-the-password')->assertStatus(422);

        $this->assertFalse($this->org->fresh()->hasMasterKey());
    }

    public function test_the_key_is_never_handed_back(): void
    {
        $this->setKey()->assertOk();

        $body = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/master-key')
            ->assertOk()
            ->assertJsonPath('data.is_set', true)
            ->json();

        $this->assertStringNotContainsString(self::KEY, json_encode($body));
    }

    public function test_a_subadmin_may_not_touch_it_however_many_rights_they_hold(): void
    {
        $deputy = User::where('email', 'deputy@acme.test')->first();

        $this->setKey($deputy)->assertStatus(403);

        $this->setKey()->assertOk();

        $this->actingAs($deputy)
            ->postJson("/api/v1/crm/employees/{$this->staff->uuid}/reset-password")
            ->assertStatus(403);
    }

    public function test_a_reset_puts_the_employee_on_the_key_and_out_of_their_sessions(): void
    {
        $this->setKey()->assertOk();

        // A session they were already holding.
        $this->staffUser->createToken('phone');
        $this->assertSame(1, $this->staffUser->tokens()->count());

        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/employees/{$this->staff->uuid}/reset-password")
            ->assertOk();

        $fresh = $this->staffUser->fresh();

        $this->assertTrue(Hash::check(self::KEY, $fresh->password));
        // A doorway, not a resting password.
        $this->assertTrue((bool) $fresh->force_password_change);
        // A password changed while the old tokens still work is not a lockout.
        $this->assertSame(0, $fresh->tokens()->count());

        // And the person is told, because a silent password change on somebody
        // else's account is how a takeover hides.
        Notification::assertSentTo($this->staffUser, SocialNotification::class);
    }

    public function test_it_refuses_before_a_key_exists(): void
    {
        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/employees/{$this->staff->uuid}/reset-password")
            ->assertStatus(422);
    }

    public function test_one_admin_cannot_take_over_another(): void
    {
        $this->setKey()->assertOk();

        $peerUser = $this->makeUser('cofounder@acme.test');
        $peer = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $peerUser->id, 'crm_role' => 'admin',
        ]);

        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/employees/{$peer->uuid}/reset-password")
            ->assertStatus(403);

        $this->assertTrue(Hash::check('secret-password-1', $peerUser->fresh()->password));
    }

    public function test_it_stops_at_the_edge_of_the_company(): void
    {
        $this->setKey()->assertOk();

        $otherOrg = Organization::create(['name' => 'Rival Ltd', 'code' => 'RIVAL']);
        $outsider = Member::create([
            'organization_id' => $otherOrg->id,
            'user_id' => $this->makeUser('someone@rival.test')->id,
            'crm_role' => 'employee',
        ]);

        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/employees/{$outsider->uuid}/reset-password")
            ->assertStatus(404);
    }

    public function test_an_account_holding_a_platform_role_is_out_of_reach(): void
    {
        $this->setKey()->assertOk();

        // Somebody who is also staff at Netvork itself: their account reaches
        // past this company, so a key set inside it must not open them.
        $this->staffUser->roles()->attach(\App\Models\Role::where('slug', 'salesperson')->value('id'));

        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/employees/{$this->staff->uuid}/reset-password")
            ->assertStatus(403);
    }

    public function test_clearing_it_stops_further_resets(): void
    {
        $this->setKey()->assertOk();

        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/master-key')->assertOk();

        $this->assertFalse($this->org->fresh()->hasMasterKey());

        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/employees/{$this->staff->uuid}/reset-password")
            ->assertStatus(422);
    }

    public function test_a_flimsy_key_is_refused(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/master-key', [
            'current_password' => 'secret-password-1',
            'master_key' => 'password',
            'master_key_confirmation' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('master_key');
    }
}
