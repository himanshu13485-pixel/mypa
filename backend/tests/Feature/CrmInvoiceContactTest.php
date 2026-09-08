<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phone number and e-mail on a document.
 *
 * Printed only where they have been filled in. A company that never entered
 * a phone number should get a document with no phone line at all — not an
 * empty one, and not a stray separator standing where the missing half used
 * to be, which is what a document that joins two optional fields with a
 * bullet produces the day one of them is blank.
 *
 * The screen carries them too, because Print prints the screen: a detail
 * only the PDF knew about was a detail printing never had.
 */
class CrmInvoiceContactTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
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

    /** An issuing company with whichever contact details are passed. */
    private function company(array $contact): int
    {
        return $this->as()->postJson('/api/v1/crm/masters/issuing-companies', array_merge([
            'name' => 'Acme Billing Pvt Ltd',
            'invoice_prefix' => 'INV-',
            'proforma_prefix' => 'PI-',
            'gstin' => '24AAACS1234A1Z5',
        ], $contact))->assertCreated()->json('data.id');
    }

    private function raise(int $companyId, string $kind = 'invoice'): string
    {
        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');

        return $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'due_date' => '2026-12-31', 'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance', 'subscription_type' => 'online', 'dispatch_status' => 'pending', 'items' => [['membership' => 'Standard', 'plan_name' => 'Plan', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31', 'description' => 'Annual listing', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');
    }

    // ---- What the screen is handed -----------------------------------------

    public function test_the_document_carries_the_phone_and_email_it_has(): void
    {
        $id = $this->company(['phone' => '+91 98250 11223', 'email' => 'billing@acme.test']);
        $uuid = $this->raise($id);

        $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.issuing_company_full.phone', '+91 98250 11223')
            ->assertJsonPath('data.issuing_company_full.email', 'billing@acme.test');
    }

    public function test_a_company_without_them_sends_nothing_to_print(): void
    {
        $uuid = $this->raise($this->company([]));

        $full = $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")->assertOk()->json('data.issuing_company_full');

        // Null, so the screen has something falsy to skip rather than an
        // empty string that would draw a blank line.
        $this->assertNull($full['phone']);
        $this->assertNull($full['email']);
    }

    public function test_a_proforma_carries_them_as_well(): void
    {
        $id = $this->company(['phone' => '+91 98250 11223', 'email' => 'billing@acme.test']);
        $uuid = $this->raise($id, 'proforma');

        $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.issuing_company_full.phone', '+91 98250 11223');
    }

    // ---- What the PDF prints -----------------------------------------------

    public function test_the_pdf_prints_both_joined(): void
    {
        $id = $this->company(['phone' => '+91 98250 11223', 'email' => 'billing@acme.test']);
        $uuid = $this->raise($id);

        $html = $this->renderDocument($uuid);

        $this->assertStringContainsString('+91 98250 11223 · billing@acme.test', $html);
    }

    public function test_the_pdf_prints_an_email_alone_without_a_leading_bullet(): void
    {
        // The bug this guards: joining two optional fields with " · " in front
        // of the second one prints " · billing@acme.test" when there is no
        // phone number to sit before it.
        $id = $this->company(['email' => 'billing@acme.test']);
        $uuid = $this->raise($id);

        $html = $this->renderDocument($uuid);

        $this->assertStringContainsString('billing@acme.test', $html);
        $this->assertStringNotContainsString('· billing@acme.test', $html);
    }

    public function test_the_pdf_prints_a_phone_alone(): void
    {
        $id = $this->company(['phone' => '+91 98250 11223']);
        $uuid = $this->raise($id);

        $html = $this->renderDocument($uuid);

        $this->assertStringContainsString('+91 98250 11223', $html);
        $this->assertStringNotContainsString('+91 98250 11223 ·', $html);
    }

    public function test_a_company_with_neither_prints_no_line_at_all(): void
    {
        $uuid = $this->raise($this->company([]));

        $html = $this->renderDocument($uuid);

        // The name and GSTIN still print; only the contact line is absent.
        $this->assertStringContainsString('Acme Billing Pvt Ltd', $html);
        $this->assertStringContainsString('24AAACS1234A1Z5', $html);
        $this->assertStringNotContainsString('<div class="muted"></div>', $html);
    }

    /** The document template, rendered with the same data the PDF uses. */
    private function renderDocument(string $uuid): string
    {
        $invoice = \App\Models\Crm\Invoice::where('uuid', $uuid)
            ->with(['client', 'issuingCompany', 'items', 'taxes', 'member.user'])
            ->firstOrFail();

        return view('crm.document', [
            'invoice' => $invoice,
            'company' => $invoice->issuingCompany,
            'isProforma' => $invoice->kind === 'proforma',
            'columns' => [],
            'extraColumns' => [],
            'headings' => [],
            'documentFields' => [],
            'moneyLines' => [],
            'currency' => $invoice->currency ?: 'INR',
            'received' => 0.0,
            'logoPath' => null,
            'stampPath' => null,
            'bank' => null,
        ])->render();
    }
}
