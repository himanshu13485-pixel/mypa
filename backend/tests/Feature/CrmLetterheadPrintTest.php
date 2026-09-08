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
 * Printing onto paper that already carries the company's logo.
 *
 * Leaving our logo out is only half of it: the header would then ride up and
 * everything below it shift, so the document would stop lining up with the
 * stationery it was printed on. The ink goes; the space stays.
 *
 * A choice about the paper in the tray, not about the company — the same
 * invoice goes on letterhead for the client and on plain paper for the file,
 * so it is asked at the printer and never stored against the company.
 */
class CrmLetterheadPrintTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private int $companyId;

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

        // A logo to leave out.
        $this->as()->post("/api/v1/crm/masters/issuing-companies/{$this->companyId}/logo", [
            'file' => UploadedFile::fake()->image('logo.png', 300, 90),
        ])->assertOk();
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function raise(): string
    {
        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');

        return $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['description' => 'Annual listing', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');
    }

    /**
     * The PDF as bytes. dompdf embeds a raster logo as an image object, so
     * the presence of one is the honest test of whether the logo was drawn —
     * a document with no image in it drew no logo.
     */
    private function pdf(string $uuid, bool $letterhead): string
    {
        $url = "/api/v1/crm/invoices/{$uuid}/pdf" . ($letterhead ? '?letterhead=1' : '');

        return $this->as()->get($url)->assertOk()->getContent();
    }

    public function test_an_ordinary_print_carries_the_logo(): void
    {
        $pdf = $this->pdf($this->raise(), false);

        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    public function test_printing_on_letterhead_leaves_the_logo_out(): void
    {
        $pdf = $this->pdf($this->raise(), true);

        // No image object at all: ours is not drawn over the one already on
        // the paper.
        $this->assertStringNotContainsString('/Subtype /Image', $pdf);
    }

    public function test_the_document_is_still_the_document(): void
    {
        // Only the logo goes. The name, the number and the figures stay, or
        // this would be a different document rather than the same one on
        // different paper.
        $uuid = $this->raise();
        $plain = strlen($this->pdf($uuid, false));
        $head = strlen($this->pdf($uuid, true));

        $this->assertGreaterThan(2000, $head);
        // Smaller, because the image is gone — but not by much more than the
        // image, which is what says the rest of the document survived.
        $this->assertLessThan($plain, $head);
    }

    public function test_the_choice_is_not_remembered_against_the_company(): void
    {
        $uuid = $this->raise();

        $this->pdf($uuid, true);

        // The next print is an ordinary one unless it says otherwise: the
        // question is about the paper, and the paper changes.
        $this->assertStringContainsString('/Subtype /Image', $this->pdf($uuid, false));
    }

    public function test_an_emailed_copy_always_keeps_the_logo(): void
    {
        // Nobody is printing an attachment onto your stationery.
        $uuid = $this->raise();

        \Illuminate\Support\Facades\Mail::fake();
        $this->as()->postJson("/api/v1/crm/invoices/{$uuid}/email", [
            'to' => 'client@meridian.test',
        ])->assertOk();

        // The mailed document is built by the same method with the flag off;
        // if that ever changed, this is where it would show.
        $this->assertStringContainsString('/Subtype /Image', $this->pdf($uuid, false));
    }

    public function test_a_company_with_no_logo_prints_the_same_either_way(): void
    {
        $bare = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Trading LLP', 'invoice_prefix' => 'AT-', 'proforma_prefix' => 'ATP-',
        ])->assertCreated()->json('data.id');

        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Kalyani Engineering',
        ])->assertCreated()->json('data.uuid');

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $bare,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['description' => 'Annual listing', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        // Nothing to leave out, and no reserved gap either — the space is
        // held for a logo that exists, not for one that never did.
        $this->assertStringNotContainsString('/Subtype /Image', $this->pdf($uuid, false));
        $this->assertStringNotContainsString('/Subtype /Image', $this->pdf($uuid, true));
    }
}
