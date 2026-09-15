<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The downloaded PDF reads like the printed page.
 *
 * Print prints the invoice screen; Download, View and the e-mailed PDF came
 * from a template of their own, laid out differently. The owner preferred the
 * printed one, so the template follows it. These pin what makes it that
 * layout — the words and figures a reader sees — rather than pixels, which a
 * test can say nothing useful about.
 */
class CrmDocumentPrintFormatTest extends TestCase
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

        $this->adminUser = User::factory()->create(['name' => 'Neha Kapoor']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            'gstin' => '24AAACS1234A1Z5', 'pan' => 'AAACS1234A',
        ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
            'contact_person' => 'Nandini Mehta',
            'city' => 'Pune',
            'state' => 'Maharashtra',
        ])->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function raise(string $kind = 'invoice', array $line = []): string
    {
        return $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [$line + [
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01',
                'validity_to' => '2027-08-31',
                'qty' => 1,
                'unit_price' => 100000,
            ]],
        ])->assertCreated()->json('data.uuid');
    }

    /** The document as the PDF is built from it, read back as HTML. */
    private function document(string $uuid): string
    {
        $captured = null;
        View::composer('crm.document', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        $this->as()->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertOk();

        return view('crm.document', $captured)->render();
    }

    public function test_rupees_read_with_the_rupee_sign_and_indian_grouping(): void
    {
        $html = $this->document($this->raise());

        // As the printed page writes it — not "INR 100,000.00".
        $this->assertStringContainsString('₹1,00,000.00', $html);
        $this->assertStringNotContainsString('INR 100,000.00', $html);
    }

    public function test_it_carries_the_printed_pages_headings_and_lines(): void
    {
        $html = $this->document($this->raise());

        $this->assertStringContainsString('Tax invoice', $html);
        $this->assertStringContainsString('No: </span>', $html);
        $this->assertStringContainsString('Billed to', $html);
        // Who raised it and where the money stands, as the screen says them.
        $this->assertStringContainsString('Raised by: </span>Neha Kapoor', $html);
        $this->assertStringContainsString('Payment: </span>', $html);
        $this->assertStringContainsString('>Due<', $html);
        // The service span, counted in months.
        $this->assertStringContainsString('(12 months)', $html);
        // GSTIN and PAN each on their own line, as on the printed page.
        $this->assertStringContainsString('GSTIN: 24AAACS1234A1Z5</div>', $html);
        $this->assertStringContainsString('PAN: AAACS1234A</div>', $html);
    }

    public function test_the_clients_address_is_one_line(): void
    {
        $html = $this->document($this->raise());

        $this->assertStringContainsString('Pune, Maharashtra', $html);
    }

    public function test_an_invoice_always_says_what_is_received_and_what_is_left(): void
    {
        // The printed page shows both even before any money has come in.
        $html = $this->document($this->raise());

        $this->assertStringContainsString('Received', $html);
        $this->assertStringContainsString('Balance', $html);
    }

    public function test_a_proforma_is_the_same_page_without_the_money_received(): void
    {
        $html = $this->document($this->raise('proforma'));

        $this->assertStringContainsString('Proforma invoice', $html);
        $this->assertStringNotContainsString('>Received<', $html);
        $this->assertStringNotContainsString('>Balance<', $html);
    }

    public function test_a_listed_description_reads_as_keywords(): void
    {
        $html = $this->document($this->raise('invoice', ['description' => 'brass, steel, brass']));

        // Each keyword once, in a chip of its own.
        $this->assertSame(2, substr_count($html, 'class="keyword"'));
        $this->assertStringContainsString('>brass<', $html);
        $this->assertStringContainsString('>steel<', $html);
    }

    public function test_prose_stays_prose(): void
    {
        $html = $this->document($this->raise('invoice', ['description' => 'Annual subscription with weekly updates']));

        $this->assertStringNotContainsString('class="keyword"', $html);
        $this->assertStringContainsString('Annual subscription with weekly updates', $html);
    }

    public function test_payments_list_with_their_ids(): void
    {
        $uuid = $this->raise();
        InvoicePayment::create([
            'invoice_id' => Invoice::where('uuid', $uuid)->value('id'),
            'amount' => 40000,
            'received_at' => '2026-09-05',
            'payment_mode' => 'NEFT',
        ]);

        $html = $this->document($uuid);

        $this->assertStringContainsString('Payments received', $html);
        $this->assertStringContainsString('NEFT', $html);
        $this->assertStringContainsString('₹40,000.00', $html);
        // What is left, in the same hand.
        $this->assertStringContainsString('₹60,000.00', $html);
    }

    public function test_the_pdf_itself_still_builds(): void
    {
        $response = $this->as()->get('/api/v1/crm/invoices/' . $this->raise() . '/pdf')->assertOk();

        $this->assertStringContainsString('pdf', strtolower((string) $response->headers->get('content-type')));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
