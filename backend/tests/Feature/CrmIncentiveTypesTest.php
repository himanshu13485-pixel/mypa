<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\IncentivePlan;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoiceItem;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Services\Crm\IncentiveCalculator;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two incentive structures at once.
 *
 * The ordinary business pays the usual percentage; a particular work order -
 * an enterprise term, a fixed-fee listing - is sold on its own terms. Which
 * is which is decided once in Billing setup, by the plan name on the work
 * order, and an invoice carrying one of each is split between them.
 */
class CrmIncentiveTypesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private Member $seller;

    private int $companyId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->person('boss@grapout.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->seller = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->person('priyanshu@grapout.test')->id,
            'crm_role' => 'employee', 'status' => 'active', 'is_salesperson' => true, 'joined_at' => '2024-01-01',
        ]);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Grapout Billing'])->id;
        $this->clientId = Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'Bhavya Steel', 'created_by' => $this->adminUser->id,
        ])->id;

        // The ordinary terms, and the enterprise ones.
        $this->plan('Type-1', 'flat_percent', ['percent' => 1], true);
        $this->plan('Type-2', 'flat_percent', ['percent' => 5], false);
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function plan(string $name, string $kind, array $config, bool $default): IncentivePlan
    {
        return IncentivePlan::create([
            'member_id' => $this->seller->id, 'name' => $name, 'is_default' => $default,
            'effective_from' => '2026-01-01', 'kind' => $kind, 'config' => $config,
            'created_by' => $this->adminUser->id,
        ]);
    }

    /** @param array<int, array{0: string, 1: float}> $lines plan name => amount */
    private function invoice(string $number, array $lines, array $extra = []): Invoice
    {
        $total = array_sum(array_column($lines, 1));
        $invoice = Invoice::create($extra + [
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => $number,
            'issuing_company_id' => $this->companyId, 'client_id' => $this->clientId,
            'member_id' => $this->seller->id, 'invoice_date' => '2026-08-10',
            'subtotal' => $total, 'total' => $total, 'payment_status' => 'paid',
        ]);

        foreach ($lines as $i => [$planName, $amount]) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'membership' => 'Standard', 'plan_name' => $planName,
                'validity_from' => '2026-08-01', 'validity_to' => '2027-07-31',
                'qty' => 1, 'unit_price' => $amount, 'amount' => $amount, 'sort' => $i,
            ]);
        }

        return $invoice;
    }

    private function earned(): array
    {
        return (new IncentiveCalculator($this->org->fresh()))
            ->compute($this->seller, Carbon::parse('2026-08-01'));
    }

    private function sellsAs(array $map): void
    {
        $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/masters/incentive-plan-types', ['types' => $map])
            ->assertOk();
    }

    public function test_without_a_rule_everything_pays_the_default(): void
    {
        $this->invoice('INV-1', [['Enterprise-12M', 100000]]);

        $earned = $this->earned();
        // 1% of a lakh, under Type-1, because nothing says otherwise.
        $this->assertEquals(1000, $earned['total']);
        $this->assertSame('Type-1', $earned['plan_name']);
    }

    public function test_a_plan_name_named_in_billing_setup_pays_its_own_structure(): void
    {
        $this->sellsAs(['Enterprise-12M' => 'Type-2']);
        $this->invoice('INV-1', [['Enterprise-12M', 100000]]);

        // 5%, not 1%: the work order is sold on the enterprise terms.
        $this->assertEquals(5000, $this->earned()['total']);
    }

    public function test_one_invoice_can_carry_a_work_order_of_each_kind(): void
    {
        $this->sellsAs(['Enterprise-12M' => 'Type-2']);
        $this->invoice('INV-1', [['Enterprise-12M', 100000], ['Standard Listing', 50000]]);

        // 5% of the enterprise line plus 1% of the ordinary one.
        $earned = $this->earned();
        $this->assertEquals(5500, $earned['total']);

        $pots = collect($earned['pots'])->keyBy('plan');
        $this->assertEquals(100000, $pots['Type-2']['sale']['effective']);
        $this->assertEquals(50000, $pots['Type-1']['sale']['effective']);
    }

    public function test_each_structure_slabs_on_its_own_sales(): void
    {
        // Type-2 is a slab: up to a lakh 2%, beyond it 4%.
        IncentivePlan::where('member_id', $this->seller->id)->where('name', 'Type-2')->delete();
        $this->plan('Type-2', 'slab', ['slabs' => [
            ['upto' => 100000, 'percent' => 2],
            ['upto' => null, 'percent' => 4],
        ]], false);

        $this->sellsAs(['Enterprise-12M' => 'Type-2']);
        // 80k of enterprise business, and 900k of ordinary business beside it.
        $this->invoice('INV-1', [['Enterprise-12M', 80000]]);
        $this->invoice('INV-2', [['Standard Listing', 900000]]);

        // The ordinary sale must not push the enterprise pot into the
        // higher band: 2% of 80,000, plus 1% of 900,000.
        $this->assertEquals(1600 + 9000, $this->earned()['total']);
    }

    public function test_an_admin_can_overrule_the_rule_on_one_invoice(): void
    {
        $this->sellsAs(['Enterprise-12M' => 'Type-2']);
        $invoice = $this->invoice('INV-1', [['Standard Listing', 100000]]);

        $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/invoices/' . $invoice->uuid . '/incentive-plan', ['plan_name' => 'Type-2'])
            ->assertOk();

        $this->assertEquals(5000, $this->earned()['total']);

        // And put back under the rule again.
        $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/invoices/' . $invoice->uuid . '/incentive-plan', ['plan_name' => null])
            ->assertOk();
        $this->assertEquals(1000, $this->earned()['total']);
    }

    public function test_overruling_it_is_not_everybodys_to_do(): void
    {
        $invoice = $this->invoice('INV-1', [['Standard Listing', 100000]]);

        $subUser = $this->person('sub@grapout.test');
        $sub = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $subUser->id, 'crm_role' => 'subadmin',
            'status' => 'active', 'rights' => ['invoices' => ['view', 'edit']],
        ]);

        $this->actingAs($subUser)
            ->putJson('/api/v1/crm/invoices/' . $invoice->uuid . '/incentive-plan', ['plan_name' => 'Type-2'])
            ->assertForbidden();

        // Named by the Admin, the same Subadmin may.
        $sub->update(['capabilities' => ['invoices.incentive_plan']]);
        $this->actingAs($subUser)
            ->putJson('/api/v1/crm/invoices/' . $invoice->uuid . '/incentive-plan', ['plan_name' => 'Type-2'])
            ->assertOk();
    }

    public function test_naming_a_new_default_stands_the_old_one_down(): void
    {
        $this->actingAs($this->adminUser)->postJson(
            '/api/v1/crm/employees/' . $this->seller->uuid . '/compensation/plans',
            ['name' => 'Type-3', 'is_default' => true, 'effective_from' => '2026-02-01', 'kind' => 'flat_percent', 'config' => ['percent' => 3]],
        )->assertCreated();

        $defaults = IncentivePlan::where('member_id', $this->seller->id)->where('is_default', true)->pluck('name');
        $this->assertSame(['Type-3'], $defaults->all());

        // And the ordinary sale now pays 3%.
        $this->invoice('INV-1', [['Standard Listing', 100000]]);
        $this->assertEquals(3000, $this->earned()['total']);
    }
}
