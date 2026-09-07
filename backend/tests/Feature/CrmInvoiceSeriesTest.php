<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The numbering series each issuing company counts through.
 *
 * A company admin sets where its invoices and proformas start; every document
 * after that takes the next number up. The series belong to the company, not
 * to the organization — two companies billing on the same day take numbers
 * from their own counters and never from each other's.
 */
class CrmInvoiceSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private IssuingCompany $company;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        $this->adminUser->settings()->create([]);

        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->adminUser->id,
            'crm_role' => 'admin',
            'status' => 'active',
        ]);

        $this->company = IssuingCompany::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Exports',
            'invoice_prefix' => 'INV-',
            'proforma_prefix' => 'PI-',
        ]);
        // The counters come from column defaults, which the created instance
        // has not read back yet.
        $this->company->refresh();

        $this->client = Client::create([
            'organization_id' => $this->org->id,
            'company_name' => 'Buyer Ltd',
        ]);
    }

    /** Save the company, changing only what is passed. */
    private function save(array $changes)
    {
        return $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->putJson("/api/v1/crm/masters/issuing-companies/{$this->company->id}", array_merge([
                'name' => $this->company->name,
                'invoice_prefix' => $this->company->invoice_prefix,
                'proforma_prefix' => $this->company->proforma_prefix,
                'next_invoice_no' => $this->company->next_invoice_no,
                'next_proforma_no' => $this->company->next_proforma_no,
            ], $changes));
    }

    /** A document that has already been issued under a number. */
    private function issue(string $number, string $kind = 'invoice', ?IssuingCompany $company = null): Invoice
    {
        return Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => $kind,
            'number' => $number,
            'issuing_company_id' => ($company ?? $this->company)->id,
            'client_id' => $this->client->id,
            'invoice_date' => '2026-04-01',
        ]);
    }

    // ---- Setting the start -------------------------------------------------

    public function test_an_admin_sets_where_the_series_starts(): void
    {
        $this->save(['next_invoice_no' => 501, 'next_proforma_no' => 90])->assertOk();

        $this->assertSame(501, $this->company->fresh()->next_invoice_no);
        $this->assertSame(90, $this->company->fresh()->next_proforma_no);
    }

    public function test_the_start_is_what_the_next_document_is_numbered(): void
    {
        $this->save(['next_invoice_no' => 501])->assertOk();

        $this->assertSame('INV-501', $this->company->fresh()->claimNumber('invoice'));
    }

    public function test_each_document_takes_the_number_after_the_last(): void
    {
        $this->save(['next_invoice_no' => 501])->assertOk();

        $company = $this->company->fresh();
        $numbers = [$company->claimNumber('invoice'), $company->claimNumber('invoice'), $company->claimNumber('invoice')];

        $this->assertSame(['INV-501', 'INV-502', 'INV-503'], $numbers);
        $this->assertSame(504, $company->fresh()->next_invoice_no);
    }

    public function test_invoices_and_proformas_count_separately(): void
    {
        $this->save(['next_invoice_no' => 501, 'next_proforma_no' => 90])->assertOk();
        $company = $this->company->fresh();

        $this->assertSame('INV-501', $company->claimNumber('invoice'));
        $this->assertSame('PI-90', $company->claimNumber('proforma'));
        // Raising one leaves the other where it was.
        $this->assertSame('INV-502', $company->claimNumber('invoice'));
    }

    // ---- The series belongs to the company ---------------------------------

    public function test_a_second_company_counts_from_its_own_start(): void
    {
        $other = IssuingCompany::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Domestic',
            'invoice_prefix' => 'AD-',
            'next_invoice_no' => 1,
        ]);

        $this->save(['next_invoice_no' => 501])->assertOk();

        $this->assertSame('INV-501', $this->company->fresh()->claimNumber('invoice'));
        $this->assertSame('AD-1', $other->fresh()->claimNumber('invoice'));
        // And neither move touched the other's counter.
        $this->assertSame(502, $this->company->fresh()->next_invoice_no);
        $this->assertSame(2, $other->fresh()->next_invoice_no);
    }

    public function test_a_new_company_may_start_anywhere(): void
    {
        // Nothing has been issued from it, so no number is out of reach —
        // including one another company happens to have used.
        $this->issue('INV-40');

        $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Domestic',
                'invoice_prefix' => 'INV-',
                'next_invoice_no' => 5,
            ])
            ->assertCreated();

        $this->assertSame(5, IssuingCompany::where('name', 'Acme Domestic')->first()->next_invoice_no);
    }

    // ---- It cannot be walked back onto issued numbers ----------------------

    public function test_the_series_cannot_be_set_back_onto_issued_numbers(): void
    {
        $this->issue('INV-40');

        $this->save(['next_invoice_no' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('next_invoice_no');

        $this->assertSame(1, $this->company->fresh()->next_invoice_no);
    }

    public function test_the_refusal_names_the_lowest_number_left(): void
    {
        $this->issue('INV-40');

        $this->save(['next_invoice_no' => 5])
            ->assertStatus(422)
            ->assertJsonPath('errors.next_invoice_no.0',
                'Acme Exports has already issued INV-40, so its next invoice number cannot be below 41.'
                . ' Change the prefix as well to start a new series.');
    }

    public function test_the_number_just_past_the_last_issued_is_allowed(): void
    {
        $this->issue('INV-40');

        $this->save(['next_invoice_no' => 41])->assertOk();

        $this->assertSame(41, $this->company->fresh()->next_invoice_no);
    }

    public function test_a_cancelled_number_is_still_spent(): void
    {
        $this->issue('INV-40')->update(['status' => 'cancelled']);

        $this->save(['next_invoice_no' => 40])->assertStatus(422);
    }

    public function test_the_highest_issued_holds_the_floor_not_the_latest(): void
    {
        // Raised out of order, as a backdated entry would be.
        $this->issue('INV-40');
        $this->issue('INV-12');

        $this->save(['next_invoice_no' => 20])->assertStatus(422);
        $this->save(['next_invoice_no' => 41])->assertOk();
    }

    public function test_a_proforma_does_not_hold_back_the_invoice_series(): void
    {
        // Same company, same prefix by contrivance — but a different series.
        $this->issue('INV-40', 'proforma');

        $this->save(['next_invoice_no' => 5])->assertOk();
    }

    public function test_another_companys_numbers_do_not_hold_this_one_back(): void
    {
        $other = IssuingCompany::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Domestic',
            'invoice_prefix' => 'INV-',
        ]);
        $this->issue('INV-40', 'invoice', $other);

        $this->save(['next_invoice_no' => 5])->assertOk();
    }

    // ---- A new series may start again from anywhere ------------------------

    public function test_a_new_prefix_may_start_again_from_one(): void
    {
        // The financial-year restart: both halves change together, and
        // nothing has ever been issued under the new prefix.
        $this->issue('INV-40');

        $this->save(['invoice_prefix' => 'INV-26-27/', 'next_invoice_no' => 1])->assertOk();

        $company = $this->company->fresh();
        $this->assertSame('INV-26-27/1', $company->claimNumber('invoice'));
    }

    public function test_a_new_prefix_is_still_held_back_by_its_own_numbers(): void
    {
        $this->issue('INV-26-27/8');

        $this->save(['invoice_prefix' => 'INV-26-27/', 'next_invoice_no' => 3])->assertStatus(422);
    }

    public function test_a_number_outside_the_prefix_does_not_hold_it_back(): void
    {
        // Numbered under some other scheme entirely; not this series.
        $this->issue('2024/40');

        $this->save(['next_invoice_no' => 5])->assertOk();
    }

    // ---- Unrelated edits are not blocked -----------------------------------

    public function test_an_untouched_counter_does_not_block_other_edits(): void
    {
        // A counter left behind its own history — only reachable from before
        // this check existed. Correcting the address should not require
        // fixing the numbering first.
        $this->issue('INV-40');
        $this->company->update(['next_invoice_no' => 5]);

        $this->save(['address' => '11 Dock Road'])->assertOk();

        $this->assertSame('11 Dock Road', $this->company->fresh()->address);
    }

    public function test_an_explicit_null_leaves_the_counter_standing(): void
    {
        $this->save(['next_invoice_no' => 501])->assertOk();

        // NOT NULL in the table, optional in the payload — sending it empty
        // used to write the null and answer with a database error.
        $this->save(['next_invoice_no' => null, 'invoice_prefix' => null])->assertOk();

        $company = $this->company->fresh();
        $this->assertSame(501, $company->next_invoice_no);
        $this->assertSame('INV-', $company->invoice_prefix);
    }

    // ---- The admin can see the counters at all ----------------------------

    public function test_the_masters_payload_carries_the_counters(): void
    {
        $this->save(['next_invoice_no' => 501, 'next_proforma_no' => 90])->assertOk();

        $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->assertJsonPath('data.issuing_companies.0.next_invoice_no', 501)
            ->assertJsonPath('data.issuing_companies.0.next_proforma_no', 90);
    }

    // ---- And only an admin can ---------------------------------------------

    public function test_an_employee_cannot_move_the_series(): void
    {
        $staff = User::factory()->create();
        $staff->profile()->create(['timezone' => 'UTC']);
        $staff->settings()->create([]);
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $staff->id,
            'crm_role' => 'employee',
            'status' => 'active',
        ]);

        $this->actingAs($staff)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->putJson("/api/v1/crm/masters/issuing-companies/{$this->company->id}", [
                'name' => 'Acme Exports',
                'next_invoice_no' => 900,
            ])
            ->assertForbidden();

        $this->assertSame(1, $this->company->fresh()->next_invoice_no);
    }
}
