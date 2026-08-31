<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalaryRecord;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payment inbox, Expenses, Salary. What matters: claiming an inbox entry
 * creates the real receipt (and unclaiming removes it) so the two ledgers
 * agree; expense totals are computed; the salary run pulls the current
 * salary + bank snapshot and employees only see their own slips.
 */
class CrmPaymentsExpensesSalaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $employeeUser;
    protected Organization $org;
    protected Member $adminMember;
    protected Member $employeeMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->employeeUser = $this->makeUser('emp@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->employeeMember = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->employeeUser->id,
            'crm_role' => 'employee',
            'bank_name' => 'HDFC',
            'bank_account_no' => '1234567890',
            'bank_ifsc' => 'HDFC0000001',
            'bank_account_name' => 'Emp Loyee',
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function makeInvoice(float $total): Invoice
    {
        $client = Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Claim Client'],
        );
        $company = IssuingCompany::firstOrCreate(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);

        return Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . fake()->unique()->numberBetween(1, 99999),
            'issuing_company_id' => $company->id,
            'client_id' => $client->id,
            'invoice_date' => now()->toDateString(),
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    public function test_claiming_creates_the_receipt_and_unclaiming_removes_it(): void
    {
        $invoice = $this->makeInvoice(18900);

        $entry = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/payments', [
            'received_on' => now()->toDateString(),
            'amount' => 18900,
            'payment_mode' => 'NEFT',
            'details' => 'HSBCN23478857787 NEFT Cr-HSBC0400002-BCG INDIA',
        ])->assertCreated();
        $uuid = $entry->json('data.uuid');
        $this->assertSame('unclaimed', $entry->json('data.status'));

        // Claim → settled on the spot, because this claim says so. (A
        // company's default is to have an Admin check it first; that path is
        // covered in CrmPaymentSettlementTest.)
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$uuid}/claim", [
            'invoice_uuid' => $invoice->uuid,
            'mode' => 'auto',
        ])->assertOk()
            ->assertJsonPath('data.status', 'claimed')
            ->assertJsonPath('data.claimed_invoice.number', $invoice->number);

        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(1, $invoice->payments()->count());

        // A claimed entry is frozen.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/payments/{$uuid}", [
            'received_on' => now()->toDateString(), 'amount' => 1,
        ])->assertStatus(422);
        $this->actingAs($this->adminUser)->deleteJson("/api/v1/crm/payments/{$uuid}")->assertStatus(422);

        // Unclaim → receipt gone, invoice due again.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/payments/{$uuid}/unclaim")->assertOk();
        $this->assertSame(0, $invoice->payments()->count());
        $this->assertSame('due', $invoice->fresh()->payment_status);

        // Summary counts the unclaimed money again.
        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments')->json('summary');
        $this->assertEquals(1, $summary['unclaimed_count']);
        $this->assertEquals(18900, $summary['unclaimed_amount']);
    }

    public function test_expense_totals_are_computed_and_summarised_by_category(): void
    {
        // A bill names a registered vendor — the name and GSTIN come from
        // that record rather than being typed onto every bill.
        $pantry = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Om Sai Marketing',
        ])->assertCreated()->json('data.uuid');
        $supplies = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Complete Business Solutions',
            'gst_no' => '06ACNPY2693Q2ZD',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $pantry,
            'category' => 'Pantry',
            'description' => 'Water',
            'base_amount' => 1260,
        ])->assertCreated()
            ->assertJsonPath('data.total_amount', '1260.00')
            ->assertJsonPath('data.vendor_name', 'Om Sai Marketing');

        $withGst = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $supplies,
            'category' => 'Office Supplies',
            'base_amount' => 1000,
            'cgst_amount' => 90,
            'sgst_amount' => 90,
            'gst_claimed' => false,
        ])->assertCreated()
            // The GSTIN is snapshotted off the vendor record.
            ->assertJsonPath('data.vendor_gstin', '06ACNPY2693Q2ZD');
        $this->assertSame('1180.00', $withGst->json('data.total_amount'));

        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/expenses')->json('summary');
        $this->assertEquals(2440, $summary['total']);
        $this->assertEquals(180, $summary['gst_total']);
        $this->assertEquals(180, $summary['gst_unclaimed']);
        $this->assertCount(2, $summary['by_category']);

        // Employees without the module right see nothing.
        $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/expenses')->assertForbidden();
    }

    public function test_salary_run_snapshots_current_salary_and_bank(): void
    {
        SalaryRecord::create([
            'member_id' => $this->employeeMember->id,
            'amount' => 25000,
            'effective_from' => now()->subMonths(2)->toDateString(),
        ]);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', [
            'year' => now()->year, 'month' => now()->month,
        ])->assertOk();

        $rows = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/salary?year=' . now()->year . '&month=' . now()->month)
            ->assertOk()
            ->json('data');

        // The admin has no salary record → skipped; the employee has a slip
        // with the bank snapshot and net = payable.
        $this->assertCount(1, $rows);
        $this->assertSame('25000.00', $rows[0]['monthly_salary']);
        $this->assertSame('25000.00', $rows[0]['net_salary']);
        $this->assertSame('HDFC', $rows[0]['bank_name']);
        $this->assertSame('1234567890', $rows[0]['account_no']);

        // Generating again does not duplicate.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', [
            'year' => now()->year, 'month' => now()->month,
        ])->assertOk();
        $this->assertCount(1, $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/salary?year=' . now()->year . '&month=' . now()->month)->json('data'));
    }

    public function test_slip_edit_recomputes_net_and_paid_slips_are_protected(): void
    {
        SalaryRecord::create([
            'member_id' => $this->employeeMember->id, 'amount' => 30000,
            'effective_from' => now()->subMonth()->toDateString(),
        ]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', [
            'year' => now()->year, 'month' => now()->month,
        ]);
        $uuid = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/salary')->json('data.0.uuid');

        // 30000 + 2000 incentive - 1500 deduction = 30500, marked paid.
        $updated = $this->actingAs($this->adminUser)->putJson("/api/v1/crm/salary/{$uuid}", [
            'additions' => 2000,
            'deductions' => 1500,
            'deduction_note' => '1 absent day',
            'status' => 'paid',
        ])->assertOk();
        $this->assertSame('30500.00', $updated->json('data.net_salary'));
        $this->assertNotNull($updated->json('data.paid_on'));

        // Paid slips cannot be deleted; employees cannot edit slips at all.
        $this->actingAs($this->adminUser)->deleteJson("/api/v1/crm/salary/{$uuid}")->assertStatus(422);
        $this->actingAs($this->employeeUser)->putJson("/api/v1/crm/salary/{$uuid}", ['additions' => 99999])->assertForbidden();

        // The employee sees exactly their own slip.
        $mine = $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/salary')->assertOk();
        $this->assertCount(1, $mine->json('data'));
        $this->assertFalse($mine->json('manages'));
    }
}
