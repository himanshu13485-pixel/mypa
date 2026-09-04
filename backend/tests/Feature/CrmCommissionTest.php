<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Crm\CommissionController;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Expense;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commission paid to a client out of a sale: an expense tied to the invoice,
 * an internal note on it — and not one figure on the invoice's face.
 */
class CrmCommissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $alice;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');
        $this->bobUser = $this->makeUser('bob@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = [
            'clients' => ['view', 'create'],
            'invoices' => ['view', 'create'],
            'expenses' => ['view', 'create'],
            // Commissions are their own right since the module split; they
            // used to ride on 'expenses' along with vendors.
            'commissions' => ['view', 'create'],
            'user_log' => ['view'],
        ];
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bobUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function document(User $who, string $company = 'Bhavya Steel'): string
    {
        $clientUuid = $this->actingAs($who)->postJson('/api/v1/crm/clients', [
            'company_name' => $company,
        ])->assertCreated()->json('data.uuid');

        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => now()->toDateString(),
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_a_commission_is_an_expense_and_a_quiet_note_never_a_line(): void
    {
        $doc = $this->document($this->adminUser);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/commissions', [
            'invoice_uuid' => $doc, 'amount' => 1500, 'note' => 'Agreed on call.',
        ])->assertCreated()
            ->assertJsonPath('data.payee', 'Bhavya Steel')
            ->assertJsonPath('data.amount', 1500);

        // The books: one expense, filed under the commission category.
        $expense = Expense::firstOrFail();
        $this->assertSame(CommissionController::CATEGORY, $expense->category);
        $this->assertEquals(1500, (float) $expense->total_amount);
        $this->assertNotNull($expense->invoice_id);

        // The office memory: an internal note on the invoice.
        $notes = $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$doc}/notes")
            ->assertOk()->json('data');
        $this->assertStringContainsString('1,500.00', $notes[0]['body']);
        $this->assertStringContainsString('Bhavya Steel', $notes[0]['body']);

        // The paper: totals untouched, and no trace on the document or PDF.
        $data = $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$doc}")
            ->assertOk()->json('data');
        $this->assertEquals(10000, (float) $data['total']);
        $this->assertStringNotContainsString('ommission', json_encode($data));

        $pdf = $this->actingAs($this->adminUser)->get("/api/v1/crm/invoices/{$doc}/pdf")
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('Commission', $pdf);

        $this->assertTrue(ActivityLog::where('action', 'commission.recorded')->exists());
    }

    public function test_the_commission_follows_its_sales_window(): void
    {
        $aliceDoc = $this->document($this->aliceUser, 'Alice Client');
        $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/commissions', [
            'invoice_uuid' => $aliceDoc, 'amount' => 500,
        ])->assertCreated();

        // Bob cannot record against Alice's sale, nor see her commission.
        $this->actingAs($this->bobUser)->postJson('/api/v1/crm/commissions', [
            'invoice_uuid' => $aliceDoc, 'amount' => 100,
        ])->assertNotFound();
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/commissions')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/commissions')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.total', 500);
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/commissions')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reports_show_the_sales_net_of_commission(): void
    {
        $doc = $this->document($this->adminUser);
        $this->document($this->aliceUser, 'Alice Client');

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/commissions', [
            'invoice_uuid' => $doc, 'amount' => 1500,
        ])->assertCreated();

        $report = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/reports/overview')
            ->assertOk()->json('data');

        $this->assertEquals(1500, $report['totals']['commission']);

        $rows = collect($report['top_salespeople']);
        $adminRow = $rows->firstWhere('name', $this->adminUser->name);
        $this->assertEquals(10000, $adminRow['amount']);
        $this->assertEquals(1500, $adminRow['commission']);
        $this->assertEquals(8500, $adminRow['net']);

        $aliceRow = $rows->firstWhere('name', $this->aliceUser->name);
        $this->assertEquals(0, $aliceRow['commission']);
        $this->assertEquals(10000, $aliceRow['net']);
    }

    public function test_removing_a_commission_is_the_admins(): void
    {
        $doc = $this->document($this->aliceUser, 'Alice Client');
        $uuid = $this->actingAs($this->aliceUser)->postJson('/api/v1/crm/commissions', [
            'invoice_uuid' => $doc, 'amount' => 500,
        ])->assertCreated()->json('data.uuid');

        // Alice has no expenses.delete right, so the route itself refuses.
        $this->actingAs($this->aliceUser)->deleteJson("/api/v1/crm/commissions/{$uuid}")->assertForbidden();

        $this->actingAs($this->adminUser)->deleteJson("/api/v1/crm/commissions/{$uuid}")->assertOk();
        $this->assertSame(0, Expense::count());
        $this->assertTrue(ActivityLog::where('action', 'commission.removed')->exists());
    }
}
