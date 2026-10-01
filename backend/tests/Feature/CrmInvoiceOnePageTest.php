<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An invoice should come out as one sheet of paper.
 *
 * Ordinary invoices were spilling onto a second page for want of a few
 * millimetres, and the overflow was never the figures — it was the signing
 * block, arriving alone on an unbranded sheet. Two things answer that: type
 * small enough that a normal document fits, and, when one genuinely does not,
 * a letterhead repeated at the top of what follows.
 *
 * Measured in pages rather than in pixels, because pixels are the means and
 * the page count is what the client actually sees.
 */
class CrmInvoiceOnePageTest extends TestCase
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
        Storage::fake('public');

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

        $this->companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd',
            'invoice_prefix' => 'INV-',
            'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $this->as()->post("/api/v1/crm/masters/issuing-companies/{$this->companyId}/logo", [
            'file' => UploadedFile::fake()->image('logo.png', 300, 90),
        ])->assertOk();

        $this->as()->post("/api/v1/crm/masters/issuing-companies/{$this->companyId}/stamp", [
            'file' => UploadedFile::fake()->image('stamp.png', 240, 240),
        ])->assertOk();

        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors Private Limited',
            'contact_person' => 'R. Venkatesan',
            'email' => 'accounts@meridian.test',
            'phone' => '+91 98200 11223',
        ])->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** One line of the kind a real invoice carries. */
    private function line(int $n): array
    {
        return [
            'membership' => 'Standard',
            'plan_name' => 'Premium Listing Plan',
            'validity_from' => '2026-01-01',
            'validity_to' => '2026-12-31',
            'description' => "Annual directory listing and lead delivery, package {$n}",
            'qty' => 1,
            'unit_price' => 10000,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $items */
    private function raise(array $items, array $extra = []): string
    {
        return $this->as()->postJson('/api/v1/crm/invoices', array_merge([
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'due_date' => '2026-12-31',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => $items,
        ], $extra))->assertCreated()->json('data.uuid');
    }

    private function pdf(string $uuid): string
    {
        return $this->as()->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertOk()->getContent();
    }

    /**
     * How many sheets this would come out as.
     *
     * Counted from the page objects themselves rather than the /Count entry,
     * so a change in how the page tree is written cannot quietly turn this
     * test into one that always passes.
     */
    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    /**
     * Everything the document actually draws.
     *
     * dompdf deflates its content streams, so the finished file holds no
     * searchable text at all - inflating them is the only way to ask what is
     * on the page rather than what we hoped we wrote.
     */
    private function drawn(string $pdf): string
    {
        preg_match_all('#stream\n(.*?)endstream#s', $pdf, $m);

        return collect($m[1])
            ->map(fn ($s) => (string) @gzuncompress($s))
            ->implode("\n");
    }

    /**
     * How many times a phrase is printed.
     *
     * dompdf writes text two bytes to the character, so even inflated the
     * name is not the string we wrote.
     */
    private function printed(string $pdf, string $phrase): int
    {
        return substr_count($this->drawn($pdf), "\x00" . implode("\x00", str_split($phrase)));
    }

    public function test_an_ordinary_invoice_fits_on_one_page(): void
    {
        $this->assertSame(1, $this->pages($this->pdf($this->raise([$this->line(1)]))));
    }

    public function test_a_busy_invoice_still_fits_on_one_page(): void
    {
        // Eight lines, notes, and a payment recorded against it — more than a
        // typical invoice carries, and the point at which this used to spill.
        $uuid = $this->raise(
            collect(range(1, 8))->map(fn ($n) => $this->line($n))->all(),
            ['notes' => 'Payment by NEFT/RTGS only. Please quote the invoice number in the remittance advice so the receipt can be matched.'],
        );

        $this->as()->postJson("/api/v1/crm/invoices/{$uuid}/payments", [
            'amount' => 5000,
            'received_at' => '2026-08-25',
            'payment_mode' => 'neft',
        ])->assertSuccessful();

        $this->assertSame(1, $this->pages($this->pdf($uuid)));
    }

    public function test_an_invoice_that_genuinely_overflows_repeats_the_letterhead(): void
    {
        // Long enough that no amount of shrinking would honestly fit it.
        $uuid = $this->raise(collect(range(1, 40))->map(fn ($n) => $this->line($n))->all());

        $pdf = $this->pdf($uuid);
        $pages = $this->pages($pdf);
        $this->assertGreaterThan(1, $pages);

        // Page one has the full header already; every page after it gets the
        // band, numbered, so a sheet that gets separated can be put back.
        $this->assertSame(0, $this->printed($pdf, "page 1 of {$pages}"));

        foreach (range(2, $pages) as $n) {
            $this->assertSame(
                1,
                $this->printed($pdf, "page {$n} of {$pages}"),
                "page {$n} came out without the letterhead on it",
            );
        }

        // The logo rides along with the name, so the sheet the stamp lands
        // on is branded rather than bare. Counted as draws of an image: the
        // header's logo, the rubber stamp, and one more for each page after
        // the first. Not by name - the band's logo is registered as its own
        // XObject, so naming one would be asserting dompdf's bookkeeping
        // rather than what reaches the paper.
        $this->assertSame(2 + ($pages - 1), substr_count($this->drawn($pdf), ' Do'));
    }

    public function test_a_letterhead_print_is_not_overprinted_on_later_pages(): void
    {
        // The paper already carries the branding; printing ours on top of it
        // is the one thing a letterhead print must not do.
        //
        // A company with no rubber stamp, so that any image left in the file
        // could only be the logo - with a stamp there, an overprinted logo
        // would hide behind it.
        $plain = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Trading LLP', 'invoice_prefix' => 'AT-', 'proforma_prefix' => 'ATP-',
        ])->assertCreated()->json('data.id');

        $this->as()->post("/api/v1/crm/masters/issuing-companies/{$plain}/logo", [
            'file' => UploadedFile::fake()->image('logo.png', 300, 90),
        ])->assertOk();

        $uuid = $this->raise(
            collect(range(1, 40))->map(fn ($n) => $this->line($n))->all(),
            ['issuing_company_id' => $plain],
        );

        $pdf = $this->as()->get("/api/v1/crm/invoices/{$uuid}/pdf?letterhead=1")->assertOk()->getContent();

        $this->assertGreaterThan(1, $this->pages($pdf));
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }
}
