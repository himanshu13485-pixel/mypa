<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a target screen is actually asking.
 *
 * A sale is what was sold, so the target is judged on the taxable value; the
 * tax belongs to the government and only shows up in what the client still
 * owes. And the two shortfalls are different questions: work still to do, and
 * money not yet in - the screen used to call both of them "Due".
 */
class CrmTargetFiguresTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private User $sellerUser;
    private Member $seller;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminUser = $this->makeUser('boss@acme.test');
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin']);

        $this->sellerUser = $this->makeUser('seller@acme.test');
        $this->seller = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->sellerUser->id,
            'crm_role' => 'employee', 'status' => 'active', 'is_salesperson' => true,
            'rights' => ['targets' => ['view']],
        ]);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing'])->id;
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function client(string $name, string $category): Client
    {
        return Client::create([
            'organization_id' => $this->org->id, 'company_name' => $name, 'category' => $category,
            'assigned_member_id' => $this->seller->id, 'created_by' => $this->adminUser->id,
        ]);
    }

    /** A document of $base plus 18% tax, with $received already paid against it. */
    private function bill(Client $client, float $base, float $received = 0): Invoice
    {
        $invoice = Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . random_int(1000, 999999),
            'issuing_company_id' => $this->companyId,
            'client_id' => $client->id,
            'member_id' => $this->seller->id,
            'client_category' => $client->category,
            'invoice_date' => now()->toDateString(),
            'subtotal' => $base,
            'igst' => round($base * 0.18, 2),
            'total' => round($base * 1.18, 2),
        ]);

        if ($received > 0) {
            InvoicePayment::create(['invoice_id' => $invoice->id, 'amount' => $received, 'received_at' => now()->toDateString()]);
        }

        return $invoice;
    }

    private function setTarget(float $amount): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [['member_uuid' => $this->seller->uuid, 'target_amount' => $amount]],
        ])->assertOk();
    }

    private function row(): array
    {
        return collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/targets?year=' . now()->year . '&month=' . now()->month)
            ->assertOk()->json('data'))
            ->firstWhere('member_uuid', $this->seller->uuid);
    }

    public function test_the_target_is_judged_on_the_sale_and_the_due_is_the_money_outstanding(): void
    {
        $this->setTarget(150000);
        // A lakh of business, taxed to 1,18,000, of which 18,000 has come in.
        $this->bill($this->client('Bhavya Steel', 'new'), 100000, 18000);

        $row = $this->row();

        $this->assertEquals(100000, $row['achieved']);
        $this->assertEquals(100000, $row['achieved_new']);
        $this->assertEquals(50000, $row['pending_target']);
        // What the client still owes, tax and all.
        $this->assertEquals(100000, $row['payment_due']);
        $this->assertEqualsWithDelta(66.7, $row['percent'], 0.1);
    }

    public function test_the_head_count_splits_new_business_from_repeat_business(): void
    {
        $this->setTarget(1000);
        $this->bill($this->client('New One', 'new'), 1000);
        $this->bill($this->client('Global New', 'global_new'), 1000);
        $this->bill($this->client('Old One', 'existing'), 1000);
        $this->bill($this->client('SEZ Old', 'sez_existing'), 1000);

        $row = $this->row();

        $this->assertSame(4, $row['clients']);
        $this->assertSame(2, $row['clients_new']);
        $this->assertSame(2, $row['clients_existing']);
        // Existing business is the two repeat clients' taxable value.
        $this->assertEquals(2000, $row['achieved_existing']);
        $this->assertEquals(2000, $row['achieved_new']);
    }

    public function test_a_client_target_says_what_the_new_clients_and_the_old_ones_billed(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [[
                'member_uuid' => $this->seller->uuid, 'kind' => 'clients',
                'target_amount' => 0, 'client_target' => 5,
            ]],
        ])->assertOk();

        $this->bill($this->client('Fresh Co', 'new'), 3000);
        $this->bill($this->client('Returning Co', 'existing'), 2000);

        $row = $this->row();

        $this->assertSame('clients', $row['kind']);
        $this->assertSame(1, $row['clients_new']);
        $this->assertSame(1, $row['clients_existing']);
        $this->assertSame(2, $row['clients_closed']);
        // Four clients still wanted, since only the new one counts.
        $this->assertSame(4, $row['clients_due']);
        $this->assertEquals(5000, $row['client_sales']);
        $this->assertEquals(3000, $row['client_sales_new']);
        $this->assertEquals(2000, $row['client_sales_existing']);
    }

    public function test_a_salesperson_can_read_their_own_standing_for_the_strip(): void
    {
        $this->setTarget(200000);
        $this->bill($this->client('Bhavya Steel', 'new'), 50000, 5000);

        $mine = $this->actingAs($this->sellerUser)->getJson('/api/v1/crm/targets/mine')->assertOk()->json('data');

        $this->assertTrue($mine['has_target']);
        $this->assertSame('sales', $mine['kind']);
        // This month and the three before it, oldest first.
        $this->assertCount(4, $mine['months']);
        $this->assertTrue($mine['months'][3]['is_current']);
        $this->assertSame(now()->format('Y-m'), $mine['current']['month']);
        $this->assertEquals(50000, $mine['current']['achieved']);
        $this->assertEquals(150000, $mine['current']['pending_target']);
        $this->assertEquals(54000, $mine['current']['payment_due']);
        $this->assertEquals(25.0, $mine['current']['percent']);
        $this->assertSame(1, $mine['current']['clients']);
    }

    public function test_a_desk_with_nothing_asked_of_it_has_no_strip(): void
    {
        $this->assertFalse($this->actingAs($this->sellerUser)
            ->getJson('/api/v1/crm/targets/mine')->assertOk()->json('data.has_target'));
    }

    public function test_the_invoice_list_can_be_read_new_business_only(): void
    {
        $this->bill($this->client('New One', 'new'), 1000);
        $this->bill($this->client('Old One', 'existing'), 2000);

        $numbers = fn (string $query) => collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&' . $query)->assertOk()->json('data'))
            ->pluck('client_category')->all();

        $this->assertSame(['new'], $numbers('client_category[]=new'));
        $this->assertSame(['existing'], $numbers('client_category[]=existing'));
        $this->assertCount(2, $numbers(''));
    }
}
