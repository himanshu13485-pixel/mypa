<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Who to ring about a document.
 *
 * The client's phone and e-mail belong under the client's name — whoever
 * picks up a printed invoice with a query is looking for the person it was
 * raised for, not for a registration number.
 *
 * And only what has been filled in prints. A separator with nothing before
 * it reads as a number that failed to load; nothing at all reads as a record
 * that simply does not have one.
 */
class CrmDocumentContactsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private int $companyId;

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
            'name' => 'Acme Billing Pvt Ltd',
            'invoice_prefix' => 'INV-',
            'proforma_prefix' => 'PI-',
            'gstin' => '27AABCB1234C1ZX',
            'phone' => '022 4000 1000',
            'email' => 'accounts@acme.test',
        ])->assertCreated()->json('data.id');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function raise(array $client, string $kind = 'invoice'): string
    {
        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', $client)
            ->assertCreated()->json('data.uuid');

        return $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'due_date' => '2026-12-31', 'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance', 'subscription_type' => 'online', 'dispatch_status' => 'pending', 'items' => [['membership' => 'Standard', 'plan_name' => 'Plan', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31', 'description' => 'Brass fittings', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');
    }

    /**
     * The document as HTML, exactly as the PDF renderer is handed it.
     *
     * Searching the PDF itself is a poor test: dompdf compresses its content
     * streams and splits text across drawing operators, so a string that is
     * plainly on the page may not appear in the bytes. Capturing the view's
     * own data and rendering it asks the same template the same question and
     * gets back something a human can read.
     */
    private function documentHtml(string $uuid): string
    {
        $captured = null;
        View::composer('crm.document', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        $this->as()->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertOk();

        $this->assertNotNull($captured, 'the document view was never rendered');

        return view('crm.document', $captured)->render();
    }

    // ---- what the screen is given -------------------------------------------

    public function test_the_client_contact_details_reach_the_document(): void
    {
        $uuid = $this->raise([
            'company_name' => 'Meridian Motors',
            'contact_person' => 'Nandini Mehta',
            'mobile' => '9876543210',
            'email' => 'nandini@meridian.test',
        ], 'proforma');

        $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.client_full.mobile', '9876543210')
            ->assertJsonPath('data.client_full.email', 'nandini@meridian.test');
    }

    public function test_a_client_with_nothing_on_file_sends_nothing(): void
    {
        // Not an empty string that would draw a blank line — nothing.
        $uuid = $this->raise(['company_name' => 'Kalyani Engineering']);

        $doc = $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")->assertOk();

        $this->assertNull($doc->json('data.client_full.mobile'));
        $this->assertNull($doc->json('data.client_full.email'));
    }

    // ---- and what the paper prints ------------------------------------------

    public function test_the_printed_copy_carries_both(): void
    {
        $uuid = $this->raise([
            'company_name' => 'Meridian Motors',
            'contact_person' => 'Nandini Mehta',
            'mobile' => '9876543210',
            'email' => 'nandini@meridian.test',
        ]);

        $html = $this->documentHtml($uuid);

        $this->assertStringContainsString('9876543210', $html);
        $this->assertStringContainsString('nandini@meridian.test', $html);
    }

    public function test_one_half_missing_leaves_no_dangling_separator(): void
    {
        // A phone and no e-mail must not print "9876543210 ·".
        $uuid = $this->raise([
            'company_name' => 'Meridian Motors',
            'contact_person' => 'Nandini Mehta',
            'mobile' => '9876543210',
        ]);

        $html = $this->documentHtml($uuid);

        $this->assertStringContainsString('9876543210', $html);
        $this->assertStringNotContainsString('9876543210 ·', $html);
    }

    public function test_a_company_with_an_email_and_no_phone_prints_no_leading_dot(): void
    {
        // The issuing company's own line had the same fault: the separator
        // was printed before the e-mail whether or not a phone came first.
        $bare = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Trading LLP',
            'invoice_prefix' => 'AT-',
            'proforma_prefix' => 'ATP-',
            'email' => 'billing@trading.test',
        ])->assertCreated()->json('data.id');

        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Orient Appliances',
        ])->assertCreated()->json('data.uuid');

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $bare,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'due_date' => '2026-12-31', 'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance', 'subscription_type' => 'online', 'dispatch_status' => 'pending', 'items' => [['membership' => 'Standard', 'plan_name' => 'Plan', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31', 'description' => 'Brass fittings', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        $html = $this->documentHtml($uuid);

        $this->assertStringContainsString('billing@trading.test', $html);
        $this->assertStringNotContainsString('· billing@trading.test', $html);
    }

    public function test_a_pan_with_no_gstin_prints_no_leading_dot(): void
    {
        $panOnly = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Services LLP',
            'invoice_prefix' => 'AS-',
            'proforma_prefix' => 'ASP-',
            'pan' => 'AABCB1234C',
        ])->assertCreated()->json('data.id');

        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Orient Appliances',
        ])->assertCreated()->json('data.uuid');

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $panOnly,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'due_date' => '2026-12-31', 'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance', 'subscription_type' => 'online', 'dispatch_status' => 'pending', 'items' => [['membership' => 'Standard', 'plan_name' => 'Plan', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31', 'description' => 'Brass fittings', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        $html = $this->documentHtml($uuid);

        $this->assertStringContainsString('PAN: AABCB1234C', $html);
        $this->assertStringNotContainsString('· PAN', $html);
    }
}
