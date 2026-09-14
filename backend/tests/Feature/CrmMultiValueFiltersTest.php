<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkbox filters: three companies out of four, two statuses out of five -
 * several values at once, not one at a time. Everything ticked sends
 * nothing; nothing ticked matches nothing.
 */
class CrmMultiValueFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private Member $admin;

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
    }

    private function company(string $name, string $prefix): int
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => $name, 'invoice_prefix' => $prefix . '-', 'proforma_prefix' => 'P' . $prefix . '-',
        ])->assertCreated()->json('data.id');
    }

    private function invoice(int $companyId, string $number, array $overrides = []): void
    {
        $client = Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Bhavya Steel'],
            ['created_by' => $this->adminUser->id],
        );

        Invoice::create($overrides + [
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => $number,
            'issuing_company_id' => $companyId,
            'client_id' => $client->id,
            'member_id' => $this->admin->id,
            'invoice_date' => now()->toDateString(),
            'subtotal' => 1000,
            'total' => 1000,
            'status' => 'sent',
            'payment_status' => 'due',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function numbers(string $query): array
    {
        return collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&period=all&' . $query)
            ->assertOk()->json('data'))
            ->pluck('number')->sort()->values()->all();
    }

    public function test_invoices_filter_by_several_companies_and_states_at_once(): void
    {
        $a = $this->company('CGPL Corpiness Global', 'CG');
        $b = $this->company('Corpcio Global LLC', 'CO');
        $c = $this->company('Grapout Strategic Partners', 'GS');

        $this->invoice($a, 'CG-1');
        $this->invoice($b, 'CO-1', ['payment_status' => 'paid']);
        $this->invoice($c, 'GS-1', ['payment_status' => 'partial', 'tds' => 100]);

        // Nothing sent: everything.
        $this->assertSame(['CG-1', 'CO-1', 'GS-1'], $this->numbers(''));

        // Two companies out of three.
        $this->assertSame(['CG-1', 'GS-1'], $this->numbers(http_build_query(['issuing_company_id' => [$a, $c]])));

        // The older single value still works.
        $this->assertSame(['CO-1'], $this->numbers('issuing_company_id=' . $b));

        // Two payment states.
        $this->assertSame(['CG-1', 'GS-1'], $this->numbers(http_build_query(['payment_status' => ['due', 'partial']])));

        // TDS: both ticked is no filter; one ticked narrows.
        $this->assertSame(['CG-1', 'CO-1', 'GS-1'], $this->numbers(http_build_query(['tds' => ['with', 'without']])));
        $this->assertSame(['GS-1'], $this->numbers(http_build_query(['tds' => ['with']])));

        // Nothing ticked shows nothing.
        $this->assertSame([], $this->numbers(http_build_query(['issuing_company_id' => ['__none__']])));
        $this->assertSame([], $this->numbers(http_build_query(['payment_status' => ['__none__']])));
    }

    public function test_leads_filter_by_several_statuses_sources_and_owners(): void
    {
        $seller = User::factory()->create(['name' => 'Satish']);
        $seller->settings()->create([]);
        $seller->profile()->create(['timezone' => 'Asia/Kolkata']);
        $sellerMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $seller->id, 'crm_role' => 'employee', 'status' => 'active',
        ]);

        foreach ([
            [1, 'Alpha', 'new', 'Website', $this->admin->id],
            [2, 'Bravo', 'follow_up', 'Referral', $sellerMember->id],
            [3, 'Charlie', 'closed', 'Website', $sellerMember->id],
        ] as [$no, $name, $status, $source, $owner]) {
            Lead::create([
                'organization_id' => $this->org->id, 'lead_no' => $no, 'company_name' => $name,
                'lead_status' => $status, 'source' => $source, 'assigned_member_id' => $owner,
                'created_by' => $this->adminUser->id,
            ]);
        }

        $names = fn (array $query) => collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/leads?' . http_build_query($query))
            ->assertOk()->json('data'))
            ->pluck('company_name')->sort()->values()->all();

        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $names([]));
        $this->assertSame(['Alpha', 'Bravo'], $names(['lead_status' => ['new', 'follow_up']]));
        $this->assertSame(['Alpha', 'Charlie'], $names(['source' => ['Website']]));
        $this->assertSame(['Bravo', 'Charlie'], $names(['assigned_to' => [$sellerMember->uuid]]));
        $this->assertSame(['Charlie'], $names(['lead_status' => ['closed', 'new'], 'assigned_to' => [$sellerMember->uuid]]));
        $this->assertSame([], $names(['source' => ['__none__']]));
    }
}
