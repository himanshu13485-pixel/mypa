<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Approval notifications + the sidebar badge. What matters: filing a
 * request notifies exactly the people who can decide it (never the
 * requester), deciding notifies the requester back, and the badge counts
 * only what the signed-in member is allowed to decide.
 */
class CrmNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $hrUser;
    protected User $employeeUser;
    protected Organization $org;
    protected Member $adminMember;
    protected Member $hrMember;
    protected Member $employeeMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->hrUser = $this->makeUser('hr@acme.test');
        $this->employeeUser = $this->makeUser('emp@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        // HR: an employee who was granted leave-deciding rights — the
        // "SubAdmin / Team Head by rights" model.
        $this->hrMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->hrUser->id, 'crm_role' => 'employee',
            'rights' => ['leaves' => ['view', 'edit']],
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

    public function test_filing_a_leave_notifies_the_deciders_and_deciding_notifies_back(): void
    {
        Notification::fake();

        $uuid = $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Sick Leave', 'duration' => 'full',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
        ])->assertCreated()->json('data.uuid');

        // Admin and HR (leaves,edit) hear about it; the requester does not.
        Notification::assertSentTo($this->adminUser, CrmNotification::class);
        Notification::assertSentTo($this->hrUser, CrmNotification::class);
        Notification::assertNotSentTo($this->employeeUser, CrmNotification::class);

        // HR approves → the requester is told.
        $this->actingAs($this->hrUser)->postJson("/api/v1/crm/leaves/{$uuid}/decide", ['status' => 'approved'])->assertOk();
        Notification::assertSentTo($this->employeeUser, CrmNotification::class);
    }

    public function test_the_badge_counts_only_what_i_can_decide(): void
    {
        // One pending leave from the employee, one approval-register request.
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/leaves', [
            'category' => 'Casual Leave', 'duration' => 'full',
            'date_from' => now()->toDateString(), 'date_to' => now()->toDateString(),
        ]);
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/approvals', [
            'type' => 'First Approval', 'approval_date' => now()->toDateString(), 'amount' => 100,
        ]);

        // The admin decides everything → total 2.
        $admin = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/badges')->assertOk()->json('data');
        $this->assertSame(1, $admin['leaves']);
        $this->assertSame(1, $admin['approvals']);
        $this->assertSame(2, $admin['total']);

        // HR decides only leaves → total 1, approvals section null.
        $hr = $this->actingAs($this->hrUser)->getJson('/api/v1/crm/badges')->json('data');
        $this->assertSame(1, $hr['leaves']);
        $this->assertNull($hr['approvals']);
        $this->assertSame(1, $hr['total']);

        // A plain employee decides nothing → total 0.
        $emp = $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/badges')->json('data');
        $this->assertSame(0, $emp['total']);

        // Notifications persist to the bell: the admin's leave entry carries
        // the message and the deep link.
        $note = $this->adminUser->notifications()->where('data->kind', 'crm_leave')->first();
        $this->assertNotNull($note);
        $this->assertSame('/crm/leaves?status=pending', $note->data['action_path']);
        $this->assertStringContainsString('Casual Leave', $note->data['message']);
    }
}
