<?php

namespace Tests\Feature;

use App\Models\Crm\Expense;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P&L lines added from the month's own figures - CGST, SGST, IGST, TDS,
 * commission, expenses - follow those figures as they change.
 */
class CrmPlLinkedLinesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private Member $admin;

    private int $companyId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = User::factory()->create(['email' => 'boss@grapout.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        $this->admin = Member::create(['organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin', 'status' => 'active']);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Grapout Billing'])->id;
        $this->clientId = \App\Models\Crm\Client::create(['organization_id' => $this->org->id, 'company_name' => 'C1', 'created_by' => $this->adminUser->id])->id;
    }

    private function invoice(string $no, array $extra): Invoice
    {
        return Invoice::create($extra + [
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => $no,
            'issuing_company_id' => $this->companyId, 'client_id' => $this->clientId, 'member_id' => $this->admin->id,
            'invoice_date' => '2026-08-10', 'subtotal' => 10000, 'total' => 11800,
        ]);
    }

    private function month(): array
    {
        return $this->actingAs($this->adminUser)->getJson('/api/v1/crm/pl?month_from=2026-08&month_to=2026-08')->assertOk()->json('data.months.0');
    }

    public function test_lines_added_from_the_months_figures_follow_them(): void
    {
        $this->invoice('INV-1', ['cgst' => 900, 'sgst' => 900, 'tds' => 100]);
        $this->invoice('INV-2', ['igst' => 1800]);
        $this->invoice('INV-SEPT', ['cgst' => 5000, 'invoice_date' => '2026-09-02']);
        Expense::create(['organization_id' => $this->org->id, 'expense_date' => '2026-08-12', 'vendor_name' => 'Client',
            'category' => 'Client Commission', 'base_amount' => 700, 'total_amount' => 700, 'created_by' => $this->adminUser->id]);

        $figures = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/pl/figures?month=2026-08')->assertOk()->json('data'))->keyBy('key');
        $this->assertEquals(900, $figures['cgst']['amount']);
        $this->assertEquals(900, $figures['sgst']['amount']);
        $this->assertEquals(1800, $figures['igst']['amount']);
        $this->assertEquals(3600, $figures['gst_total']['amount']);
        $this->assertEquals(100, $figures['tds']['amount']);
        $this->assertEquals(700, $figures['commission']['amount']);
        // Every category counts by default, so commission is already in expenses.
        $this->assertTrue($figures['commission']['already_counted']);

        foreach (['cgst' => 'CGST', 'igst' => 'IGST', 'tds' => 'TDS'] as $key => $label) {
            $this->actingAs($this->adminUser)->postJson('/api/v1/crm/pl/lines', [
                'month' => '2026-08', 'side' => 'expense', 'label' => $label, 'auto_key' => $key,
            ])->assertCreated();
        }
        // The same figure twice is refused.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/pl/lines', [
            'month' => '2026-08', 'side' => 'expense', 'label' => 'CGST again', 'auto_key' => 'cgst',
        ])->assertStatus(422);
        $this->assertTrue(collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/pl/figures?month=2026-08')->json('data'))->firstWhere('key', 'cgst')['added']);

        $lines = collect($this->month()['expenses'])->keyBy('label');
        $this->assertEquals(900, $lines['CGST']['amount']);
        $this->assertSame('linked', $lines['CGST']['source']);
        $this->assertEquals(1800, $lines['IGST']['amount']);
        $this->assertEquals(100, $lines['TDS']['amount']);

        // A new invoice in August moves the linked lines with it.
        $this->invoice('INV-3', ['cgst' => 450, 'sgst' => 450]);
        $lines = collect($this->month()['expenses'])->keyBy('label');
        $this->assertEquals(1350, $lines['CGST']['amount']);

        // A typed line still keeps its own amount, and a linked one can be removed.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/pl/lines', [
            'month' => '2026-08', 'side' => 'expense', 'label' => 'Cash', 'amount' => 250,
        ])->assertCreated();
        $month = $this->month();
        $this->assertEquals(250, collect($month['expenses'])->firstWhere('label', 'Cash')['amount']);
        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/pl/lines/' . $lines['TDS']['id'])->assertOk();
        $this->assertNull(collect($this->month()['expenses'])->firstWhere('label', 'TDS'));

        // Only real figures can be linked.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/pl/lines', [
            'month' => '2026-08', 'side' => 'expense', 'label' => 'Nope', 'auto_key' => 'salary_of_ceo',
        ])->assertStatus(422);
    }
}
