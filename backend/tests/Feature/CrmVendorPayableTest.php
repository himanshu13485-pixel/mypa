<?php

namespace Tests\Feature;

use App\Models\Crm\Expense;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\Vendor;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vendor register and what the company owes it.
 *
 * What matters: a bill cannot name a supplier who was never registered, the
 * same supplier cannot be registered twice under two spellings, a bill's
 * standing follows the payments made against it rather than being typed,
 * and a payment entered by mistake can be taken back.
 */
class CrmVendorPayableTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $employeeUser;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->employeeUser = $this->makeUser('clerk@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->employeeUser->id, 'crm_role' => 'employee',
            'rights' => ['expenses' => ['view'], 'vendors' => ['view']],
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function vendor(array $overrides = []): string
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', $overrides + [
            'company_name' => 'Om Sai Marketing',
        ])->assertCreated()->json('data.uuid');
    }

    private function bill(string $vendorUuid, array $overrides = []): string
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', $overrides + [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $vendorUuid,
            'base_amount' => 10000,
        ])->assertCreated()->json('data.uuid');
    }

    // ---- Registration comes first -------------------------------------------

    public function test_a_bill_must_name_a_registered_vendor(): void
    {
        // No vendor at all.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'base_amount' => 500,
        ])->assertStatus(422);

        // A name that was never registered is not a vendor.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => 'not-a-real-vendor',
            'base_amount' => 500,
        ])->assertNotFound();

        $uuid = $this->vendor(['gst_no' => '24aaacs1234a1z5']);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $uuid,
            'base_amount' => 500,
        ])->assertCreated()
            // Name and GSTIN are snapshotted from the register, in house casing.
            ->assertJsonPath('data.vendor_name', 'Om Sai Marketing')
            ->assertJsonPath('data.vendor_gstin', '24AAACS1234A1Z5');
    }

    public function test_the_same_supplier_cannot_be_registered_twice(): void
    {
        $this->vendor(['gst_no' => '24AAACS1234A1Z5']);

        // Spelling and punctuation are noise, exactly as for a client.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'OM SAI  MARKETING.',
        ])->assertStatus(422);

        // A different name sharing the GSTIN is the same company too.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Om Sai Traders',
            'gst_no' => '24AAACS1234A1Z5',
        ])->assertStatus(422);

        $this->assertSame(1, Vendor::count());
    }

    public function test_a_vendor_with_bills_is_retired_rather_than_deleted(): void
    {
        $uuid = $this->vendor();
        $this->bill($uuid);

        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/vendors/' . $uuid)
            ->assertOk()->assertJsonPath('retired', true);

        $this->assertSame('inactive', Vendor::firstOrFail()->status);
        // The bill still knows who it was paid to.
        $this->assertSame('Om Sai Marketing', Expense::firstOrFail()->vendor_name);

        // One with no history goes for good.
        $spare = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Never Used Supplies',
        ])->assertCreated()->json('data.uuid');
        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/vendors/' . $spare)
            ->assertOk()->assertJsonPath('retired', false);
    }

    // ---- Owing, part paying, settling ---------------------------------------

    public function test_a_bill_starts_owed_and_follows_its_payments(): void
    {
        $vendor = $this->vendor();
        $bill = $this->bill($vendor, ['base_amount' => 10000, 'cgst_amount' => 900, 'sgst_amount' => 900]);

        $listed = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/expenses')->assertOk();
        $listed->assertJsonPath('data.0.payment_status', 'unpaid')
            ->assertJsonPath('data.0.balance', 11800);
        $this->assertEquals(11800, $listed->json('summary.outstanding'));
        $this->assertEquals(0, $listed->json('summary.paid'));

        // Part payment: the bill moves, it does not settle.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/expenses/{$bill}/payments", [
            'amount' => 5000, 'payment_mode' => 'NEFT', 'reference_no' => 'UTR-1',
        ])->assertCreated()
            ->assertJsonPath('data.payment_status', 'part')
            ->assertJsonPath('data.balance', 6800);

        // More than what is left is refused rather than creating a credit.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/expenses/{$bill}/payments", [
            'amount' => 9000,
        ])->assertStatus(422);

        // No amount at all means "settle the rest" — the one-click case.
        $settled = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/expenses/{$bill}/payments", [])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.balance', 0);
        $this->assertCount(2, $settled->json('data.payments'));

        // And a settled bill takes no more money.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/expenses/{$bill}/payments", [
            'amount' => 100,
        ])->assertStatus(422);

        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/expenses')->json('summary');
        $this->assertEquals(11800, $summary['paid']);
        $this->assertEquals(0, $summary['outstanding']);
        $this->assertSame(0, $summary['unpaid_bills']);
    }

    public function test_a_payment_entered_by_mistake_is_taken_back(): void
    {
        $vendor = $this->vendor();
        $bill = $this->bill($vendor, ['base_amount' => 4000]);

        $paid = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/expenses/{$bill}/payments", ['amount' => 4000])
            ->assertCreated();
        $paymentUuid = $paid->json('payment_uuid');
        $this->assertSame('paid', Expense::firstOrFail()->payment_status);

        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/crm/expenses/{$bill}/payments/{$paymentUuid}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.balance', 4000);

        $this->assertSame(0, Expense::firstOrFail()->payments()->count());
    }

    public function test_a_due_date_comes_from_the_vendors_terms_and_makes_a_bill_overdue(): void
    {
        $vendor = $this->vendor(['payment_terms_days' => 30]);

        // Nothing typed: the vendor's own terms set the date.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $vendor,
            'base_amount' => 1000,
        ])->assertCreated()
            ->assertJsonPath('data.due_date', now()->addDays(30)->toDateString())
            ->assertJsonPath('data.overdue', false);

        // A bill whose day has gone by, still owing, is overdue.
        $late = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->subMonths(2)->toDateString(),
            'due_date' => now()->subDays(10)->toDateString(),
            'vendor_uuid' => $vendor,
            'base_amount' => 7000,
        ])->assertCreated()->assertJsonPath('data.overdue', true)->json('data.uuid');

        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/expenses')->json('summary');
        $this->assertEquals(7000, $summary['overdue']);
        $this->assertSame(1, $summary['overdue_bills']);

        // Asked for by name, the overdue ones come back on their own.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/expenses?payment_status=overdue')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $late);

        // Paying it takes it off the overdue list.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/expenses/{$late}/payments", [])->assertCreated();
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/expenses?payment_status=overdue')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- Tax as the bill quotes it ------------------------------------------

    public function test_tax_is_entered_as_a_rate_and_the_amount_follows(): void
    {
        $vendor = $this->vendor();

        $bill = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $vendor,
            'base_amount' => 5000,
            'cgst_rate' => 9,
            'sgst_rate' => 9,
        ])->assertCreated()
            ->assertJsonPath('data.cgst_amount', '450.00')
            ->assertJsonPath('data.sgst_amount', '450.00')
            ->assertJsonPath('data.igst_amount', '0.00')
            ->assertJsonPath('data.total_amount', '5900.00');

        // The rate is kept, so reopening the bill shows what was quoted.
        $this->assertSame('9.000', $bill->json('data.cgst_rate'));
        $this->assertNull($bill->json('data.igst_rate'));

        // Anything else the bill charges brings its own name.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $vendor,
            'base_amount' => 20000,
            'igst_rate' => 18,
            'other_tax_label' => 'Swachh Bharat Cess',
            'other_tax_rate' => 0.5,
        ])->assertCreated()
            ->assertJsonPath('data.igst_amount', '3600.00')
            ->assertJsonPath('data.other_tax_amount', '100.00')
            ->assertJsonPath('data.other_tax_label', 'Swachh Bharat Cess')
            ->assertJsonPath('data.total_amount', '23700.00');
    }

    public function test_an_amount_typed_without_a_rate_stands_as_the_paper_reads(): void
    {
        $vendor = $this->vendor();

        // A bill that rounded its own way is entered as it reads: no rate,
        // and the register does not argue with the paper.
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/expenses', [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $vendor,
            'base_amount' => 1000,
            'cgst_amount' => 91,
            'other_tax_amount' => 25,
        ])->assertCreated()
            ->assertJsonPath('data.cgst_amount', '91.00')
            ->assertJsonPath('data.cgst_rate', null)
            // A charge with no name still gets one on the register.
            ->assertJsonPath('data.other_tax_label', 'Other tax')
            ->assertJsonPath('data.total_amount', '1116.00')
            ->json('data.uuid');

        // A rate added later takes over that line and recomputes it.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/expenses/' . $uuid, [
            'expense_date' => now()->toDateString(),
            'vendor_uuid' => $vendor,
            'base_amount' => 1000,
            'cgst_rate' => 9,
            'cgst_amount' => 91,
        ])->assertOk()
            ->assertJsonPath('data.cgst_amount', '90.00')
            ->assertJsonPath('data.total_amount', '1090.00');
    }

    // ---- What each supplier is owed -----------------------------------------

    public function test_the_register_shows_what_each_vendor_is_owed(): void
    {
        $sai = $this->vendor();
        $other = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Metro Print House',
        ])->assertCreated()->json('data.uuid');

        $first = $this->bill($sai, ['base_amount' => 10000]);
        $this->bill($sai, ['base_amount' => 6000]);
        $this->bill($other, ['base_amount' => 3000]);
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/expenses/{$first}/payments", ['amount' => 4000])
            ->assertCreated();

        $rows = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/vendors')->assertOk()->json('data'))
            ->keyBy('company_name');

        $this->assertSame(2, $rows['Om Sai Marketing']['bills']);
        $this->assertEquals(16000, $rows['Om Sai Marketing']['billed']);
        $this->assertEquals(4000, $rows['Om Sai Marketing']['paid']);
        $this->assertEquals(12000, $rows['Om Sai Marketing']['outstanding']);
        $this->assertEquals(3000, $rows['Metro Print House']['outstanding']);

        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/vendors')->json('summary');
        $this->assertEquals(19000, $summary['billed']);
        $this->assertEquals(15000, $summary['outstanding']);

        // The vendor's own page lists the bills behind that figure.
        $detail = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/vendors/' . $sai)
            ->assertOk()->json('data');
        $this->assertCount(2, $detail['recent_bills']);
        $this->assertEquals(12000, $detail['outstanding']);
    }

    public function test_registering_a_vendor_needs_the_vendors_right(): void
    {
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/vendors', [
            'company_name' => 'Sneaky Supplies',
        ])->assertForbidden();

        // Viewing is allowed — the clerk who enters bills must see the list.
        $this->actingAs($this->employeeUser)->getJson('/api/v1/crm/vendors')->assertOk();
    }
}
