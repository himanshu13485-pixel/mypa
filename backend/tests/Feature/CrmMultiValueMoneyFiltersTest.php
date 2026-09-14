<?php

namespace Tests\Feature;

use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checkbox filters on the money screens: the bank inbox, the expense book and
 * the vendor register take several values at once, keep taking the single
 * value older callers send, and show nothing when nothing is ticked.
 */
class CrmMultiValueMoneyFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = User::factory()->create(['name' => 'Boss']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);
    }

    private function pluck(string $url, string $field): array
    {
        return collect($this->actingAs($this->adminUser)->getJson($url)->assertOk()->json('data'))
            ->pluck($field)->sort()->values()->all();
    }

    public function test_payment_inbox_filters_by_several_companies_and_states(): void
    {
        $companies = collect(['A', 'B', 'C'])->mapWithKeys(fn ($name) => [
            $name => IssuingCompany::create(['organization_id' => $this->org->id, 'name' => "Company {$name}"])->id,
        ]);

        foreach (['A' => 'unclaimed', 'B' => 'claimed', 'C' => 'unclaimed'] as $name => $status) {
            PaymentInboxEntry::create([
                'organization_id' => $this->org->id,
                'issuing_company_id' => $companies[$name],
                'received_on' => now()->toDateString(),
                'amount' => 1000,
                'details' => "Credit {$name}",
                'status' => $status,
                'created_by' => $this->adminUser->id,
            ]);
        }

        $url = '/api/v1/crm/payments?';

        $this->assertSame(['Credit A', 'Credit B', 'Credit C'], $this->pluck($url, 'details'));
        $this->assertSame(['Credit A', 'Credit C'], $this->pluck($url . http_build_query(['issuing_company_id' => [$companies['A'], $companies['C']]]), 'details'));
        $this->assertSame(['Credit A', 'Credit B', 'Credit C'], $this->pluck($url . http_build_query(['status' => ['unclaimed', 'claimed']]), 'details'));
        // The older single value still works.
        $this->assertSame(['Credit B'], $this->pluck($url . 'status=claimed', 'details'));
        $this->assertSame([], $this->pluck($url . http_build_query(['status' => ['__none__']]), 'details'));
    }

    public function test_expenses_filter_by_several_categories_payment_states_and_gst(): void
    {
        $vendor = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Om Sai Marketing',
        ])->assertCreated()->json('data.uuid');

        foreach ([['Pantry', true], ['Travel', false], ['Rent', false]] as [$category, $claimed]) {
            $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
                'expense_date' => now()->toDateString(),
                'vendor_uuid' => $vendor,
                'category' => $category,
                'description' => $category . ' bill',
                'base_amount' => 1000,
                'gst_claimed' => $claimed,
            ])->assertCreated();
        }

        $url = '/api/v1/crm/expenses?';

        $this->assertSame(['Pantry bill', 'Travel bill'], $this->pluck($url . http_build_query(['category' => ['Pantry', 'Travel']]), 'description'));
        // Both GST boxes ticked is no filter; one narrows.
        $this->assertSame(['Pantry bill', 'Rent bill', 'Travel bill'], $this->pluck($url . http_build_query(['gst_claimed' => ['0', '1']]), 'description'));
        $this->assertSame(['Pantry bill'], $this->pluck($url . http_build_query(['gst_claimed' => ['1']]), 'description'));
        // Every new bill is unpaid; "paid or overdue" finds none of them, "unpaid or paid" all.
        $this->assertSame([], $this->pluck($url . http_build_query(['payment_status' => ['paid', 'overdue']]), 'description'));
        $this->assertSame(['Pantry bill', 'Rent bill', 'Travel bill'], $this->pluck($url . http_build_query(['payment_status' => ['unpaid', 'paid']]), 'description'));
        $this->assertSame([], $this->pluck($url . http_build_query(['category' => ['__none__']]), 'description'));
    }

    public function test_vendors_filter_by_several_categories_and_statuses(): void
    {
        foreach ([['Alpha Traders', 'Goods', 'active'], ['Beta Rentals', 'Rent', 'inactive'], ['Gamma Soft', 'Software', 'active']] as [$name, $category, $status]) {
            $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
                'company_name' => $name, 'category' => $category, 'status' => $status,
            ])->assertCreated();
        }

        $url = '/api/v1/crm/vendors?';

        $this->assertSame(['Alpha Traders', 'Beta Rentals'], $this->pluck($url . http_build_query(['category' => ['Goods', 'Rent']]), 'company_name'));
        $this->assertSame(['Alpha Traders', 'Beta Rentals', 'Gamma Soft'], $this->pluck($url . http_build_query(['status' => ['active', 'inactive']]), 'company_name'));
        $this->assertSame(['Beta Rentals'], $this->pluck($url . 'status=inactive', 'company_name'));
        $this->assertSame([], $this->pluck($url . http_build_query(['status' => ['__none__']]), 'company_name'));
    }
}
