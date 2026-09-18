<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoiceItem;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding documents by what was sold, not only by who it was sold to.
 *
 * "Show me everyone on Enterprise-12M" was a question the list could not
 * answer: every filter it had described the client or the money. Membership
 * and plan name live on the work order, so a document matches when any one
 * of its lines does — which is the question actually being asked.
 */
class CrmInvoiceWorkOrderFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private int $companyId;

    private int $clientId;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = User::factory()->create(['email' => 'boss@grapout.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->memberId = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ])->id;

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Grapout Billing'])->id;
        $this->clientId = Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'Bhavya Steel', 'created_by' => $this->adminUser->id,
        ])->id;
    }

    /** @param array<int, array{0: string, 1: string}> $lines membership, plan name */
    private function invoice(string $number, array $lines, array $extra = []): Invoice
    {
        $invoice = Invoice::create($extra + [
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => $number,
            'issuing_company_id' => $this->companyId, 'client_id' => $this->clientId,
            'member_id' => $this->memberId, 'invoice_date' => now()->toDateString(),
            'subtotal' => 10000, 'total' => 10000,
        ]);

        foreach ($lines as $i => [$membership, $plan]) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'membership' => $membership, 'plan_name' => $plan,
                'validity_from' => now()->toDateString(), 'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1, 'unit_price' => 10000, 'amount' => 10000, 'sort' => $i,
            ]);
        }

        return $invoice;
    }

    /** @return array<int, string> the numbers the list came back with */
    private function listed(string $query): array
    {
        $rows = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&' . $query)
            ->assertOk()->json('data');

        return collect($rows)->pluck('number')->sort()->values()->all();
    }

    public function test_documents_can_be_found_by_plan_name(): void
    {
        $this->invoice('INV-1', [['Standard', 'Enterprise-12M']]);
        $this->invoice('INV-2', [['Standard', 'Basic Listing']]);

        $this->assertSame(['INV-1'], $this->listed('plan_name=Enterprise-12M'));
    }

    public function test_and_by_membership(): void
    {
        $this->invoice('INV-1', [['Premium', 'Enterprise-12M']]);
        $this->invoice('INV-2', [['Standard', 'Enterprise-12M']]);

        $this->assertSame(['INV-1'], $this->listed('membership=Premium'));
    }

    public function test_one_line_is_enough_to_match(): void
    {
        // A document carrying two work orders is a document that sold both,
        // and somebody looking for either should find it.
        $this->invoice('INV-1', [['Standard', 'Basic Listing'], ['Premium', 'Enterprise-12M']]);
        $this->invoice('INV-2', [['Standard', 'Basic Listing']]);

        $this->assertSame(['INV-1'], $this->listed('plan_name=Enterprise-12M'));
    }

    public function test_several_plans_can_be_ticked_at_once(): void
    {
        $this->invoice('INV-1', [['Standard', 'Enterprise-12M']]);
        $this->invoice('INV-2', [['Standard', 'Basic Listing']]);
        $this->invoice('INV-3', [['Standard', 'Something Else']]);

        $this->assertSame(['INV-1', 'INV-2'], $this->listed('plan_name=Enterprise-12M,Basic Listing'));
    }

    public function test_documents_can_be_found_by_how_they_are_delivered(): void
    {
        $this->invoice('INV-1', [['Standard', 'Enterprise-12M']], ['subscription_type' => 'online']);
        $this->invoice('INV-2', [['Standard', 'Enterprise-12M']], ['subscription_type' => 'offline']);

        $this->assertSame(['INV-1'], $this->listed('subscription_type=online'));
    }

    public function test_the_filters_offer_what_has_actually_been_sold(): void
    {
        $this->invoice('INV-1', [['Premium', 'Enterprise-12M'], ['Standard', 'Basic Listing']]);
        // A cancelled document is not a sale, and its plan should not be
        // offered as though somebody could still find rows under it.
        $this->invoice('INV-2', [['Ghost', 'Withdrawn Plan']], ['status' => 'cancelled']);

        $values = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices/work-order-values')
            ->assertOk()->json('data');

        $this->assertSame(['Premium', 'Standard'], $values['memberships']);
        $this->assertSame(['Basic Listing', 'Enterprise-12M'], $values['plan_names']);
    }

    public function test_another_companys_plans_are_not_offered_here(): void
    {
        $this->invoice('INV-1', [['Premium', 'Enterprise-12M']]);

        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVL']);
        $theirBoss = User::factory()->create(['email' => 'boss@rival.test']);
        $theirBoss->settings()->create([]);
        $theirBoss->profile()->create(['timezone' => 'Asia/Kolkata']);
        Member::create([
            'organization_id' => $other->id, 'user_id' => $theirBoss->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $values = $this->actingAs($theirBoss)
            ->getJson('/api/v1/crm/invoices/work-order-values')
            ->assertOk()->json('data');

        $this->assertSame([], $values['plan_names']);
    }
}
