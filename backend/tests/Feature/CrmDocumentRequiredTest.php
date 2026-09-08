<?php

namespace Tests\Feature;

use App\Models\Crm\CustomField;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a document cannot be raised without.
 *
 * A proforma is a promise and an invoice is a demand for money; both are
 * read by somebody outside the company, and both were saveable with the due
 * date, the terms, the dispatch state and the plan all blank. What the
 * office was left with is a document it has to ring somebody to explain.
 *
 * A company that genuinely does not use one of these still has a way out:
 * hide the column. Hidden is never required — requiring something invisible
 * is a document nobody can raise. What it cannot do is keep the column and
 * leave it optional.
 *
 * Tax is the exception, and is the company's own answer: off by default,
 * because a company raising exempt or zero-rated documents would otherwise
 * be unable to raise them at all.
 */
class CrmDocumentRequiredTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private int $companyId;
    private string $clientUuid;

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

        $this->companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** A complete document, which every case below takes something out of. */
    private function payload(array $over = [], array $lineOver = []): array
    {
        return $over + [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'due_date' => '2026-09-20',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [$lineOver + [
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => '2026-08-20',
                'validity_to' => '2027-08-19',
                'qty' => 1,
                'unit_price' => 10000,
            ]],
        ];
    }

    private function raise(array $over = [], array $lineOver = [])
    {
        return $this->as()->postJson('/api/v1/crm/invoices', $this->payload($over, $lineOver));
    }

    public function test_a_complete_document_is_accepted(): void
    {
        $this->raise()->assertCreated();
    }

    public static function headerFields(): array
    {
        return [
            'due date' => ['due_date'],
            'client status' => ['client_category'],
            'pricing' => ['pricing_tier'],
            'terms of payment' => ['terms_of_payment'],
            'subscription' => ['subscription_type'],
            'dispatch' => ['dispatch_status'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('headerFields')]
    public function test_a_document_needs_its_header_field(string $field): void
    {
        $this->raise([$field => null])->assertStatus(422)->assertJsonValidationErrors($field);
    }

    public static function lineFields(): array
    {
        return [
            'membership' => ['membership', 'items.0.membership'],
            'plan name' => ['plan_name', 'items.0.plan_name'],
            'quantity' => ['qty', 'items.0.qty'],
            'validity start' => ['validity_from', 'items.0.validity_from'],
            'validity end' => ['validity_to', 'items.0.validity_to'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lineFields')]
    public function test_a_line_needs_its_own_field(string $field, string $error): void
    {
        $this->raise([], [$field => null])->assertStatus(422)->assertJsonValidationErrors($error);
    }

    public function test_the_issuing_company_the_client_and_the_date_are_still_required(): void
    {
        // These never went through the column method; they are asked for by
        // the document itself and always have been.
        foreach (['issuing_company_id', 'client_uuid', 'invoice_date'] as $field) {
            $this->raise([$field => null])->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }

    public function test_editing_an_existing_document_is_held_to_the_same_rules(): void
    {
        // Documents raised before this rule are completed on their next edit,
        // rather than left half-filled for ever.
        $uuid = $this->raise()->assertCreated()->json('data.uuid');

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload(['due_date' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_date');
    }

    // ---- the way out ---------------------------------------------------------

    public function test_a_column_a_company_hides_is_not_required(): void
    {
        // Requiring something nobody can see is a document nobody can raise.
        CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'invoice',
            'key' => 'dispatch_status',
            'label' => 'Dispatch',
            'type' => 'select',
            'is_builtin' => true,
            'is_hidden' => true,
            'status' => 'approved',
        ]);

        $this->raise(['dispatch_status' => null])->assertCreated();
    }

    public function test_a_company_may_add_requiredness_but_not_remove_it(): void
    {
        // An override that says "not required" does not unlock a field the
        // product requires; hiding it is the way out, and that is deliberate.
        CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'invoice',
            'key' => 'due_date',
            'label' => 'Due date',
            'type' => 'date',
            'is_builtin' => true,
            'is_required' => false,
            'status' => 'approved',
        ]);

        $this->raise(['due_date' => null])->assertStatus(422)->assertJsonValidationErrors('due_date');
    }

    // ---- tax, which each issuing company decides ----------------------------

    /** Turn the rule on for the company these documents are raised from. */
    private function requireTax(): void
    {
        $this->as()->putJson("/api/v1/crm/masters/issuing-companies/{$this->companyId}", [
            'name' => 'Acme Billing Pvt Ltd',
            'tax_required' => true,
        ])->assertOk();
    }

    public function test_tax_is_not_required_by_default(): void
    {
        // A company raising exempt or zero-rated documents must still be able
        // to raise them.
        $this->raise()->assertCreated();
    }

    public function test_when_a_company_requires_tax_its_documents_must_carry_some(): void
    {
        $this->requireTax();

        $this->raise()->assertStatus(422)->assertJsonValidationErrors('cgst');
    }

    public function test_any_one_tax_line_satisfies_it(): void
    {
        $this->requireTax();

        // Which line applies depends on where the client is; the rule only
        // asks that somebody answered.
        $this->raise(['igst_rate' => 18])->assertCreated();
        $this->raise(['cgst_rate' => 9, 'sgst_rate' => 9])->assertCreated();
        $this->raise(['cgst' => 900, 'sgst' => 900])->assertCreated();
    }

    public function test_one_company_requiring_tax_does_not_bind_another(): void
    {
        // The whole point of moving it: a domestic arm charging GST on
        // everything and an export arm invoicing without payment of tax sit
        // in the same account.
        $this->requireTax();

        $exports = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Exports LLP', 'invoice_prefix' => 'AE-', 'proforma_prefix' => 'AEP-',
        ])->assertCreated()->json('data.id');

        $this->raise(['issuing_company_id' => $exports])->assertCreated();
        $this->raise()->assertStatus(422)->assertJsonValidationErrors('cgst');
    }

    public function test_the_refusal_names_the_company_that_asked(): void
    {
        $this->requireTax();

        $this->raise()->assertStatus(422)->assertJsonPath(
            'errors.cgst.0',
            'Acme Billing Pvt Ltd requires tax on every document — enter at least one tax line.',
        );
    }

    public function test_the_setting_survives_and_is_served_with_the_company(): void
    {
        $this->requireTax();

        $this->as()->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->assertJsonPath('data.issuing_companies.0.tax_required', true);
    }

    // ---- a line can name more than one ---------------------------------------

    public function test_a_line_can_carry_several_memberships_and_plans(): void
    {
        // One line covering three memberships is a real line; it used to have
        // to become three lines charged separately.
        $uuid = $this->raise([], [
            'membership' => 'Gold, Silver, Bronze',
            'plan_name' => 'Annual listing, Banner',
        ])->assertCreated()->json('data.uuid');

        $doc = $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")->assertOk();

        $this->assertSame('Gold, Silver, Bronze', $doc->json('data.items.0.membership'));
        // House style titles these two, as it always has (TextCase::company).
        $this->assertSame('Annual Listing, Banner', $doc->json('data.items.0.plan_name'));
    }

    public function test_the_list_is_tidied_on_the_way_in(): void
    {
        // Stray spaces and the same name twice: stored as one clean list, so
        // the column reads the same however it was typed.
        $uuid = $this->raise([], ['membership' => '  Gold ,, silver,  Gold '])
            ->assertCreated()->json('data.uuid');

        // Deduplicated first, then titled by the house rule.
        $this->assertSame(
            'Gold, Silver',
            $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")->json('data.items.0.membership'),
        );
    }

    public function test_the_invoice_list_counts_each_name_separately(): void
    {
        $this->raise([], ['membership' => 'Gold, Silver'])->assertCreated();

        $row = $this->as()->getJson('/api/v1/crm/invoices?kind=invoice')->assertOk()->json('data.0');

        $this->assertSame(['Gold', 'Silver'], $row['memberships']);
    }

    public function test_a_dropdown_judges_every_name_in_the_list(): void
    {
        // Turned into a dropdown of this company's own products.
        CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'work_order',
            'key' => 'membership',
            'label' => 'Membership',
            'type' => 'select',
            'options' => ['Gold', 'Silver'],
            'is_builtin' => true,
            'status' => 'approved',
        ]);

        $this->raise([], ['membership' => 'Gold, Silver'])->assertCreated();

        // Rule::in on the whole string would have refused the line above for
        // not being an option, which is true and useless.
        $this->raise([], ['membership' => 'Gold, Platinum'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.membership');
    }
}
