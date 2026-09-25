<?php

namespace Tests\Feature;

use App\Models\Crm\Expense;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A bill in somebody else's money.
 *
 * A company with an export arm buys abroad as well as selling abroad, and
 * every bill used to be entered as though its figure were rupees - so a
 * $500 hosting invoice went into the register, the P&L and the vendor's
 * ledger as ₹500.
 *
 * The bill now keeps the money it was paid in, and the rate is frozen onto
 * it as it is saved. The rows read in their own currency; every total the
 * office reads adds the rupee column.
 */
class CrmExpenseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private string $vendorUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // 96 at market, less the default two-rupee margin: 94 to the dollar.
        Cache::put('fx-inr-USD', 96.0, now()->addHour());

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->vendorUuid = $this->as()->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Cloudhost International',
        ])->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function bill(float $amount, ?string $currency = null): array
    {
        return $this->as()->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $this->vendorUuid,
            'category' => 'Hosting',
            'description' => 'Servers',
            'base_amount' => $amount,
            'currency' => $currency,
        ])->assertCreated()->json('data');
    }

    public function test_a_dollar_bill_is_filed_as_dollars(): void
    {
        $bill = $this->bill(500, 'USD');

        $this->assertSame('USD', $bill['currency']);
        // The figure on the paper is untouched.
        $this->assertSame('500.00', $bill['total_amount']);
    }

    public function test_and_carries_the_rupees_it_cost_at_the_rate_of_the_day(): void
    {
        $bill = $this->bill(500, 'USD');

        // 94 to the dollar: market less the bank's cut, frozen here.
        $this->assertEquals(94, (float) $bill['fx_rate']);
        $this->assertEquals(47000, (float) $bill['total_inr']);
    }

    public function test_a_rupee_bill_is_left_exactly_as_it_was(): void
    {
        $bill = $this->bill(1260);

        $this->assertSame('INR', $bill['currency']);
        $this->assertNull($bill['fx_rate']);
        $this->assertEquals(1260, (float) $bill['total_inr']);
    }

    public function test_the_register_totals_rupees_and_never_adds_dollars_to_them(): void
    {
        $this->bill(1000);
        $this->bill(500, 'USD');

        $summary = $this->as()->getJson('/api/v1/crm/expenses')->assertOk()->json('summary');

        // ₹1,000 + ($500 x 94) = ₹48,000. The wrong answer is 1,500.
        $this->assertEquals(48000, $summary['total']);
        $this->assertSame('INR', $summary['currency']);
        $this->assertEquals(48000, collect($summary['by_month'])->sum('amount'));
    }

    public function test_the_rate_is_frozen_so_a_later_move_cannot_restate_last_month(): void
    {
        $this->bill(500, 'USD');

        // The rupee slides after the bill is filed.
        Cache::put('fx-inr-USD', 120.0, now()->addHour());

        $this->assertEquals(47000, (float) Expense::first()->total_inr);
        $this->assertEquals(48000 - 1000, $this->as()->getJson('/api/v1/crm/expenses')->json('summary.total'));
    }

    /** A $1,200 invoice from a company that bills in dollars. */
    private function dollarInvoice(): string
    {
        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Northwind Trading LLC',
        ])->assertCreated()->json('data.uuid');

        $companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Global LLC', 'currency' => 'USD',
            'invoice_prefix' => 'AG-', 'proforma_prefix' => 'PAG-',
        ])->assertCreated()->json('data.id');

        return $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1,
                'unit_price' => 1200,
            ]],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_a_commission_on_a_dollar_sale_stays_in_dollars(): void
    {
        $invoiceUuid = $this->dollarInvoice();

        $commission = $this->as()->postJson('/api/v1/crm/commissions', [
            'invoice_uuid' => $invoiceUuid,
            'amount' => 120,
            'payee' => 'Channel partner',
        ])->assertCreated()->json('data');

        // $120 on a $1,200 sale - not ₹120, and not converted on the way in.
        $this->assertSame('USD', $commission['currency']);
        $this->assertEquals(120, $commission['amount']);

        // The office's own figure is the rupees it cost, at the invoice's
        // own frozen rate: 120 x 94.
        $this->assertEquals(11280, $commission['amount_inr']);
        $this->assertEquals(11280, $this->as()->getJson('/api/v1/crm/commissions')->json('summary.total'));

        // And it is one of the register's bills, in dollars there too.
        $bill = collect($this->as()->getJson('/api/v1/crm/expenses')->json('data'))
            ->firstWhere('vendor_name', 'Channel partner');
        $this->assertSame('USD', $bill['currency']);
        $this->assertEquals(11280, (float) $bill['total_inr']);
    }

    public function test_a_cost_filed_against_a_foreign_sale_before_all_this_is_put_right(): void
    {
        $invoiceUuid = $this->dollarInvoice();
        $invoice = Invoice::where('uuid', $invoiceUuid)->firstOrFail();

        // A gateway charge as it would have been written before bills held
        // a currency: $27 of somebody's money, recorded as though rupees.
        $old = Expense::create([
            'organization_id' => $this->org->id,
            'expense_date' => now()->toDateString(),
            'invoice_id' => $invoice->id,
            'vendor_name' => 'Payment Gateway charge',
            'category' => 'Payment Gateway Charges',
            'base_amount' => 27,
            'total_amount' => 27,
            'currency' => 'INR',
            'total_inr' => 27,
        ]);

        (require database_path('migrations/2026_10_16_100000_a_cost_off_a_foreign_sale_was_never_rupees.php'))->up();

        $old->refresh();
        $this->assertSame('USD', $old->currency);
        // $27 at 94, not ₹27 - the difference is a factor of ninety-four.
        $this->assertEquals(2538, (float) $old->total_inr);
    }

    public function test_but_a_bill_somebody_typed_is_left_exactly_alone(): void
    {
        $typed = $this->bill(1260);

        (require database_path('migrations/2026_10_16_100000_a_cost_off_a_foreign_sale_was_never_rupees.php'))->up();

        $row = Expense::where('uuid', $typed['uuid'])->firstOrFail();
        $this->assertSame('INR', $row->currency);
        $this->assertEquals(1260, (float) $row->total_inr);
    }

    public function test_what_the_supplier_is_owed_is_one_figure_not_two_currencies(): void
    {
        $this->bill(1000);
        $this->bill(500, 'USD');

        $row = collect($this->as()->getJson('/api/v1/crm/vendors')->assertOk()->json('data'))
            ->firstWhere('company_name', 'Cloudhost International');

        $this->assertEquals(48000, (float) $row['billed']);
    }
}
