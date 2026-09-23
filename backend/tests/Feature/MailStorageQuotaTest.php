<?php

namespace Tests\Feature;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\Mail\MailAccess;
use App\Services\SubscriptionEntitlementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One quota, not two.
 *
 * A person's plan says how much room they have; mail takes its share of that
 * rather than counting megabytes in a corner of its own. The platform can
 * move the number for one person, for a whole company, or for one employee -
 * and the smallest of whatever applies is what they actually get.
 */
class MailStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP', 'status' => 'active', 'mails_enabled' => true]);
        $this->user = User::factory()->create();
        $this->user->settings()->create([]);
        $this->user->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->member = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->user->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);
    }

    /** Somebody who works for the platform rather than for a company. */
    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->settings()->create([]);
        $admin->profile()->create(['timezone' => 'UTC']);
        $admin->roles()->attach(\App\Models\Role::where('slug', 'super_admin')->first()->id);

        return $admin;
    }

    private function mailbox(): MailAccount
    {
        return MailAccount::create([
            'organization_id' => $this->org->id, 'member_id' => $this->member->id, 'email' => 'a@grapout.test',
            'provider' => 'custom', 'smtp_host' => 'smtp.grapout.test', 'smtp_password' => 'x',
        ]);
    }

    public function test_mail_takes_room_from_the_same_quota_as_everything_else(): void
    {
        $service = app(SubscriptionEntitlementService::class);
        $before = $service->usedStorageBytes($this->user);

        $box = $this->mailbox();
        MailMessage::create([
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id, 'folder' => 'inbox',
            'thread_key' => 'x', 'subject' => 'Big one', 'date' => now(), 'size' => 3 * 1048576,
        ]);

        $this->assertSame($before + 3 * 1048576, $service->usedStorageBytes($this->user->fresh()),
            'mail counts against the account, not a number of its own');
    }

    public function test_the_free_plans_gigabyte_is_what_the_mailbox_gets(): void
    {
        // Nobody has said anything else, so the plan decides.
        $this->assertSame(1024, MailAccess::storageFor($this->member->fresh()), 'the free plan is 1 GB');
    }

    public function test_the_platform_can_move_one_persons_room(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$this->user->uuid}/storage", ['gb' => 10, 'note' => 'Paid extra'])
            ->assertOk()
            ->assertJsonPath('data.limit_bytes', 10 * 1073741824);

        $this->assertSame(10240, MailAccess::storageFor($this->member->fresh()));
        $this->assertSame('Paid extra', $this->user->fresh()->storage_override_note);

        // And put it back.
        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$this->user->uuid}/storage", ['gb' => null])->assertOk();
        $this->assertSame(1024, MailAccess::storageFor($this->member->fresh()));
    }

    public function test_the_company_ceiling_and_the_employees_share_both_bite(): void
    {
        $this->user->forceFill(['storage_override_bytes' => 20 * 1073741824])->save();

        // The platform gives this company 5 GB a person...
        $this->org->forceFill(['mails_storage_gb' => 5])->save();
        $this->assertSame(5120, MailAccess::storageFor($this->member->fresh()), 'the company ceiling is lower than their plan');

        // ...and their own Admin gives this person 500 MB of it.
        $this->member->update(['mail_storage_mb' => 500]);
        $this->assertSame(500, MailAccess::storageFor($this->member->fresh()));
    }

    public function test_the_platform_can_move_one_employees_share(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->putJson("/api/v1/admin/crm/organizations/{$this->org->uuid}/members/{$this->member->uuid}/storage", ['storage_mb' => 2048])
            ->assertOk()
            ->assertJsonPath('data.allocated_mb', 2048);

        $this->assertSame(1024, MailAccess::storageFor($this->member->fresh()),
            'still held by their plan, which is smaller');

        $this->user->forceFill(['storage_override_bytes' => 50 * 1073741824])->save();
        $this->assertSame(2048, MailAccess::storageFor($this->member->fresh()));
    }

    public function test_an_unlimited_plan_is_finally_unlimited(): void
    {
        $plan = Plan::where('slug', 'enterprise')->first();
        if (! $plan) {
            $plan = Plan::create(['slug' => 'unlimited', 'name' => 'Unlimited', 'limits' => ['storage_bytes' => null], 'features' => [], 'is_active' => true]);
        }
        $plan->forceFill(['limits' => ['storage_bytes' => null]])->save();

        \App\Models\Subscription::create([
            'user_id' => $this->user->id, 'plan_id' => $plan->id, 'status' => 'active', 'started_at' => now(),
        ]);

        $this->assertNull(app(SubscriptionEntitlementService::class)->storageLimitBytes($this->user->fresh()),
            'a plan that says null means unlimited, not one gigabyte');
        $this->assertNull(MailAccess::storageFor($this->member->fresh()));
        $this->assertFalse(MailAccess::isFull($this->member->fresh()));
    }
}
