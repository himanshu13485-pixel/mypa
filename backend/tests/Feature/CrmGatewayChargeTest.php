<?php

namespace Tests\Feature;

use App\Models\Crm\Expense;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Services\Crm\GatewayCharge;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the gateway kept.
 *
 * A client pays 11,800 through a gateway; 10,800 reaches the bank. The
 * client owes nothing — so the invoice must show 11,800 received, and the
 * missing 1,000 must appear as a cost of collecting the money, not as a
 * shortfall on the sale and not as a discount to the client.
 */
class CrmGatewayChargeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');
    }

    /** An invoice for 11,800 — 10,000 plus 9% + 9%. */
    private function invoice(): string
    {
        $client = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])
            ->assertCreated()->json('data.uuid');

        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $client,
            'invoice_date' => now()->toDateString(),
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
            'cgst_rate' => 9,
            'sgst_rate' => 9,
        ])->assertCreated()->json('data.uuid');
    }

    public function test_the_client_paid_in_full_and_the_charge_is_the_companys_cost(): void
    {
        $uuid = $this->invoice();
        $this->assertSame('11800.00', Invoice::firstOrFail()->total);

        // 11,800 left the client; 1,000 stayed with the gateway.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 11800,
            'charge_amount' => 1000,
            'charge_note' => 'Cashfree fee',
            'payment_mode' => 'Payment Gateway',
            'received_at' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('data.charge_amount', '1000.00');

        // The invoice is settled — no phantom 1,000 owing for ever.
        $body = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices/' . $uuid)
            ->assertOk()->json('data');
        $this->assertEquals(11800, $body['amount_received']);
        $this->assertEquals(1000, $body['collection_charges']);
        $this->assertEquals(10800, $body['payments'][0]['net_amount']);
        $this->assertSame('paid', $body['payment_status']);

        // And the 1,000 is spend, where a P&L will find it.
        $expense = Expense::firstOrFail();
        $this->assertSame(GatewayCharge::CATEGORY, $expense->category);
        $this->assertSame('1000.00', $expense->total_amount);
        $this->assertSame('Cashfree fee', $expense->vendor_name);
        // It never sat in the bank, so the bill is already settled.
        $this->assertSame('paid', $expense->payment_status);
        $this->assertSame(Invoice::firstOrFail()->id, $expense->invoice_id);
    }

    public function test_the_charge_can_be_named_after_the_money_arrived(): void
    {
        $uuid = $this->invoice();

        // The bank line was all anyone had on the day.
        $id = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 10800,
            'received_at' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('payment_status', 'partial')->json('data.id');

        // The settlement report arrives the next day and explains the gap.
        $this->actingAs($this->adminUser)
            ->putJson("/api/v1/crm/invoices/{$uuid}/payments/{$id}/charge", [
                'amount' => 11800,
                'charge_amount' => 1000,
                'charge_note' => 'Cashfree fee',
            ])->assertOk()->assertJsonPath('payment_status', 'paid');

        $this->assertSame('11800.00', InvoicePayment::firstOrFail()->amount);
        $this->assertEquals(10800, InvoicePayment::firstOrFail()->netAmount());
        $this->assertSame(1, Expense::where('category', GatewayCharge::CATEGORY)->count());
    }

    public function test_removing_the_receipt_takes_the_cost_with_it(): void
    {
        $uuid = $this->invoice();
        $id = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 11800, 'charge_amount' => 1000, 'received_at' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->assertSame(1, Expense::count());

        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/crm/invoices/{$uuid}/payments/{$id}")->assertOk();

        // No orphaned cost left behind.
        $this->assertSame(0, Expense::count());
        $this->assertSame('due', Invoice::firstOrFail()->payment_status);
    }

    public function test_clearing_the_charge_removes_the_expense_too(): void
    {
        $uuid = $this->invoice();
        $id = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 11800, 'charge_amount' => 1000, 'received_at' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->adminUser)
            ->putJson("/api/v1/crm/invoices/{$uuid}/payments/{$id}/charge", ['charge_amount' => 0])
            ->assertOk();

        $this->assertSame(0, Expense::count());
        $this->assertEquals(11800, InvoicePayment::firstOrFail()->netAmount());
    }

    public function test_the_charge_cannot_swallow_the_whole_payment(): void
    {
        $uuid = $this->invoice();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 1000, 'charge_amount' => 1000, 'received_at' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_a_receipt_written_on_the_invoice_shows_in_the_payments_ledger(): void
    {
        $uuid = $this->invoice();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 11800,
            'charge_amount' => 1000,
            'charge_note' => 'Cashfree fee',
            'payment_mode' => 'Payment Gateway',
            'reference_no' => 'UTR-77',
            'received_at' => now()->toDateString(),
        ])->assertCreated();

        // It is in the ledger, matched to this document and already settled —
        // recording it on the invoice WAS the decision.
        $entry = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments')
            ->assertOk()->json('data.0');

        $this->assertSame('claimed', $entry['status']);
        $this->assertSame('INV-1', $entry['claimed_invoice']['number']);
        $this->assertSame('UTR-77', $entry['reference_no']);
        // The bank line is what actually reached the bank, not the gross.
        $this->assertEquals(10800, $entry['amount']);
        $this->assertStringContainsString('Cashfree fee', $entry['details']);

        // Nothing is left waiting for anyone to confirm.
        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments')->json('summary');
        $this->assertEquals(0, $summary['unclaimed_count']);

        // Removing the receipt removes its reflection too.
        $paymentId = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices/' . $uuid)
            ->json('data.payments.0.id');
        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/crm/invoices/{$uuid}/payments/{$paymentId}")->assertOk();
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_bank_credit_settled_from_the_inbox_carries_the_charge(): void
    {
        $uuid = $this->invoice();

        // The bank shows 10,800 — what was left after the gateway's cut.
        $entry = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/payments', [
            'received_on' => now()->toDateString(),
            'amount' => 10800,
            'payment_mode' => 'Payment Gateway',
            'details' => 'CASHFREE SETTLEMENT',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/claim", [
            'invoice_uuid' => $uuid,
            'mode' => 'manual',
        ])->assertOk();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$entry}/settle", [
            'charge_amount' => 1000,
            'charge_note' => 'Cashfree fee',
        ])->assertOk();

        // The invoice is square: the client's gross was 10,800 + 1,000.
        $this->assertSame('11800.00', InvoicePayment::firstOrFail()->amount);
        $this->assertSame('paid', Invoice::firstOrFail()->payment_status);
        $this->assertSame('1000.00', Expense::firstOrFail()->total_amount);
    }
}
