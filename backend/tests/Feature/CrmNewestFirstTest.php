<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The thing just made is the thing being looked for.
 *
 * Every list here is read straight after something was added to it, and the
 * addition kept landing somewhere down the page: documents and bills sat in
 * the order of the date typed on them, so anything backdated sank below the
 * ones already there, and clients and vendors sat alphabetically, which put
 * a new name wherever its first letter fell.
 *
 * So these lists are ordered by when the record was made, newest first. The
 * dates are still on the rows and still filter; they no longer decide where
 * a row sits.
 *
 * Lists that are not a record of what somebody entered keep their own order
 * and are not covered here: a calendar reads by date, a payroll run by
 * month, a conversation oldest first, and a picker alphabetically.
 */
class CrmNewestFirstTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private ?int $companyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->adminUser->id,
            'crm_role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    // ---- documents -----------------------------------------------------------

    private function client(string $name): string
    {
        return $this->as()->postJson('/api/v1/crm/clients', ['company_name' => $name])
            ->assertCreated()->json('data.uuid');
    }

    private function raise(string $date, string $clientUuid): string
    {
        $companyId = $this->companyId ??= $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        return $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => $date,
            'due_date' => '2026-12-31',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => '2026-08-20',
                'validity_to' => '2027-08-19',
                'qty' => 1,
                'unit_price' => 10000,
            ]],
        ])->assertCreated()->json('data.number');
    }

    public function test_the_document_just_raised_heads_the_list_even_when_backdated(): void
    {
        $this->raise('2026-09-01', $this->client('Meridian Motors'));
        $backdated = $this->raise('2026-07-14', $this->client('Kalyani Engineering'));

        $this->as()->getJson('/api/v1/crm/invoices?kind=invoice')
            ->assertOk()
            ->assertJsonPath('data.0.number', $backdated);
    }

    public function test_a_client_sees_its_newest_document_first(): void
    {
        $uuid = $this->client('Meridian Motors');

        $this->raise('2026-09-01', $uuid);
        $backdated = $this->raise('2026-07-14', $uuid);

        $this->as()->getJson("/api/v1/crm/clients/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.invoices.0.number', $backdated);
    }

    // ---- the people and companies on them ------------------------------------

    public function test_the_client_just_added_heads_the_list(): void
    {
        // Names chosen so that alphabetical order and the order they were
        // entered in disagree.
        $this->as()->postJson('/api/v1/crm/clients', ['company_name' => 'Aarav Traders'])->assertCreated();
        $this->as()->postJson('/api/v1/crm/clients', ['company_name' => 'Zenith Works'])->assertCreated();

        $this->as()->getJson('/api/v1/crm/clients')
            ->assertOk()
            ->assertJsonPath('data.0.company_name', 'Zenith Works');
    }

    public function test_the_vendor_just_registered_heads_the_list(): void
    {
        $this->as()->postJson('/api/v1/crm/vendors', ['company_name' => 'Aarav Stationers'])->assertCreated();
        $this->as()->postJson('/api/v1/crm/vendors', ['company_name' => 'Zenith Couriers'])->assertCreated();

        $this->as()->getJson('/api/v1/crm/vendors')
            ->assertOk()
            ->assertJsonPath('data.0.company_name', 'Zenith Couriers');
    }

    public function test_the_newest_joiner_heads_the_employee_list(): void
    {
        $joiner = User::factory()->create(['email' => 'newest@acme.test']);
        $joiner->settings()->create([]);
        $joiner->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $joiner->id,
            'crm_role' => 'employee',
            'status' => 'active',
        ]);

        $this->as()->getJson('/api/v1/crm/employees')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'newest@acme.test');
    }

    // ---- money out -----------------------------------------------------------

    public function test_the_bill_just_entered_heads_the_ledger_even_when_backdated(): void
    {
        $vendor = $this->as()->postJson('/api/v1/crm/vendors', ['company_name' => 'Om Sai Marketing'])
            ->assertCreated()->json('data.uuid');

        $this->as()->postJson('/api/v1/crm/expenses', [
            'expense_date' => '2026-09-01', 'vendor_uuid' => $vendor, 'base_amount' => 10000,
        ])->assertCreated();

        $backdated = $this->as()->postJson('/api/v1/crm/expenses', [
            'expense_date' => '2026-07-14', 'vendor_uuid' => $vendor, 'base_amount' => 4000,
        ])->assertCreated()->json('data.uuid');

        $this->as()->getJson('/api/v1/crm/expenses')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $backdated);

        // And on the vendor's own page, which lists the same bills.
        $this->as()->getJson("/api/v1/crm/vendors/{$vendor}")
            ->assertOk()
            ->assertJsonPath('data.recent_bills.0.uuid', $backdated);
    }

    // ---- and the one that already read this way ------------------------------

    public function test_the_newest_lead_still_heads_the_lead_list(): void
    {
        $this->as()->postJson('/api/v1/crm/leads', ['company_name' => 'Aarav Traders'])->assertCreated();
        $this->as()->postJson('/api/v1/crm/leads', ['company_name' => 'Zenith Works'])->assertCreated();

        $this->as()->getJson('/api/v1/crm/leads')
            ->assertOk()
            ->assertJsonPath('data.0.company_name', 'Zenith Works');
    }
}
