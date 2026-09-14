<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Client;
use App\Models\Crm\Complaint;
use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkbox filters on the sales and service screens: several values at once,
 * the older single value still understood, and nothing ticked showing nothing.
 */
class CrmMultiValueSalesFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private Member $admin;

    private Member $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->makeUser('Boss');
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);
        $this->seller = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('Satish')->id,
            'crm_role' => 'employee', 'status' => 'active', 'reporting_to' => $this->admin->id,
        ]);
    }

    private function makeUser(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    /** @return list<string> */
    private function pluck(string $url, array $query, string $field): array
    {
        return collect($this->actingAs($this->adminUser)
            ->getJson($url . '?' . http_build_query($query))
            ->assertOk()->json('data'))
            ->pluck($field)->sort()->values()->all();
    }

    public function test_clients_filter_by_several_statuses_and_categories(): void
    {
        foreach ([
            ['Alpha', 'active', 'new'],
            ['Bravo', 'inactive', 'existing'],
            ['Charlie', 'active', 'existing'],
        ] as [$name, $status, $category]) {
            Client::create([
                'organization_id' => $this->org->id, 'company_name' => $name, 'status' => $status,
                'category' => $category, 'assigned_member_id' => $this->admin->id, 'created_by' => $this->adminUser->id,
            ]);
        }

        $names = fn (array $q) => $this->pluck('/api/v1/crm/clients', $q, 'company_name');

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names([]));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names(['status' => ['active', 'inactive']]));
        $this->assertSame(['Bravo'], $names(['status' => 'inactive']));
        $this->assertSame(['Charlie'], $names(['status' => ['active'], 'category' => ['existing', 'global_existing']]));
        $this->assertSame([], $names(['status' => ['__none__']]));
    }

    public function test_complaints_filter_by_several_statuses_priorities_subjects_and_desks(): void
    {
        foreach ([
            ['C-1', 'unattended', 'low', 'Data', $this->seller->id],
            ['C-2', 'in_progress', 'high', 'Billing', $this->admin->id],
            ['C-3', 'closed_satisfied', 'urgent', 'Data', null],
            ['C-4', 'closed_dissatisfied', 'high', 'Login', $this->seller->id],
        ] as [$no, $status, $priority, $subject, $desk]) {
            Complaint::create([
                'organization_id' => $this->org->id, 'cms_no' => $no, 'complained_on' => now()->toDateString(),
                'company_name' => 'Bhavya Steel', 'subject' => $subject, 'details' => 'Rows missing.',
                'status' => $status, 'priority' => $priority,
                'raised_by_member_id' => $this->admin->id, 'allocated_to_member_id' => $desk,
            ]);
        }

        $list = fn (array $q) => $this->pluck('/api/v1/crm/complaints', $q, 'cms_no');
        $log = fn (array $q) => $this->pluck('/api/v1/crm/complaint-log', $q, 'cms_no');

        $this->assertSame(['C-1', 'C-2', 'C-3', 'C-4'], $list([]));
        // Ticks widen, stored states and readings of the clock alike.
        $this->assertSame(['C-1', 'C-3'], $list(['status' => ['unattended', 'closed_satisfied']]));
        $this->assertSame(['C-1', 'C-2', 'C-4'], $list(['status' => ['open', 'closed_dissatisfied']]));
        $this->assertSame(['C-2', 'C-3', 'C-4'], $list(['priority' => ['high', 'urgent']]));
        $this->assertSame(['C-1', 'C-3'], $list(['subject' => 'Data']));
        $this->assertSame(['C-1', 'C-2', 'C-4'], $list(['allocated_to' => [$this->seller->uuid, $this->admin->uuid]]));
        $this->assertSame(['C-4'], $list(['allocated_to' => [$this->seller->uuid], 'priority' => ['high']]));
        $this->assertSame([], $list(['priority' => ['__none__']]));
        $this->assertSame([], $list(['allocated_to' => ['__none__']]));

        // The log stays closed whatever is ticked, and its own endings narrow.
        $this->assertSame(['C-3', 'C-4'], $log([]));
        $this->assertSame(['C-4'], $log(['status' => ['closed_dissatisfied']]));
        $this->assertSame(['C-3', 'C-4'], $log(['status' => ['closed_satisfied', 'closed_dissatisfied']]));
        $this->assertSame([], $log(['status' => ['unattended']]));
        $this->assertSame([], $log(['status' => ['__none__']]));
    }

    public function test_the_lead_log_filters_by_several_people(): void
    {
        $lead = Lead::create([
            'organization_id' => $this->org->id, 'lead_no' => 1, 'company_name' => 'Alpha',
            'lead_status' => 'new', 'assigned_member_id' => $this->admin->id, 'created_by' => $this->adminUser->id,
        ]);
        foreach ([[$this->admin, 'lead.created'], [$this->seller, 'lead.updated']] as [$who, $action]) {
            ActivityLog::create([
                'organization_id' => $this->org->id, 'member_id' => $who->id, 'action' => $action,
                'subject_type' => Lead::class, 'subject_id' => $lead->id, 'changes' => ['lead_no' => 1],
            ]);
        }

        $actions = fn (array $q) => $this->pluck('/api/v1/crm/lead-log', $q, 'action');

        $this->assertSame(['lead.created', 'lead.updated'], $actions([]));
        $this->assertSame(['lead.created', 'lead.updated'], $actions(['member' => [$this->admin->uuid, $this->seller->uuid]]));
        $this->assertSame(['lead.updated'], $actions(['member' => $this->seller->uuid]));
        $this->assertSame([], $actions(['member' => ['__none__']]));
    }
}
