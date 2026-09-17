<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A PDF template may only ask for a weight dompdf can find.
 *
 * dompdf turns a font weight into a file: 400 is "normal", 700 is "bold",
 * and anything else — 500, 600 — is passed through as the name of a variant
 * to look for. DejaVu, the face bundled with it, has no such variant, so the
 * run falls back to a built-in font with no rupee sign in it.
 *
 * What that looks like is a document where ₹36,000.00 prints as ?36,000.00
 * in exactly the cells somebody wanted to emphasise — the amount on a line,
 * the grand total — while every ordinary row reads correctly. The page looks
 * right until a figure is read.
 *
 * Any weight is fine in a browser, which synthesises the ones it has no file
 * for. This is about the templates dompdf renders, and it is the kind of
 * rule that comes back the next time a class is copied off a screen.
 */
class CrmPdfFontWeightTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<int, string>> */
    public static function templates(): array
    {
        return [
            'document' => ['crm/document.blade.php'],
            'payslip' => ['crm/payslip.blade.php'],
            'offline payslip' => ['crm/offline-payslip.blade.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function test_a_pdf_template_asks_only_for_normal_or_bold(string $template): void
    {
        $path = resource_path('views/' . $template);
        $this->assertFileExists($path);

        preg_match_all('/font-weight:\s*([a-z0-9]+)/i', (string) file_get_contents($path), $matches);

        $unknown = array_values(array_unique(array_filter(
            $matches[1],
            fn (string $weight) => ! in_array(strtolower($weight), ['normal', 'bold', '400', '700'], true),
        )));

        $this->assertSame([], $unknown, $template . ' asks for a weight dompdf cannot find, so ₹ prints as "?"');
    }

    /**
     * And the proof, in a real file: the bold runs are set in DejaVu.
     *
     * The check above says what the template asked for; this says what dompdf
     * did with it. A weight it cannot find falls back to a core font — Times,
     * as it turns out — which has no rupee sign, and that is where the "?"
     * came from.
     */
    public function test_a_rendered_document_sets_its_bold_runs_in_dejavu(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);
        $as = fn () => $this->actingAs($user)->withHeader('X-Crm-Org', $org->uuid);

        $companyId = $as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $clientUuid = $as()->postJson('/api/v1/crm/clients', ['company_name' => 'Meridian Motors'])
            ->assertCreated()->json('data.uuid');

        // A proforma with tax on it: the amount and the grand total are the
        // bold cells, and the ones that printed "?".
        $uuid = $as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma', 'issuing_company_id' => $companyId, 'client_uuid' => $clientUuid,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01',
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online', 'dispatch_status' => 'pending',
            'igst_rate' => 18,
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Growth Plus',
                'validity_from' => '2026-09-01', 'validity_to' => '2026-12-01',
                'qty' => 1, 'unit_price' => 36000,
            ]],
        ])->assertCreated()->json('data.uuid');

        $pdf = $as()->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertOk()->getContent();

        $this->assertStringContainsString('DejaVuSans-Bold', $pdf, 'the bold runs fell back to another font');

        /*
         * And nothing was set in a core font.
         *
         * A weight dompdf cannot find falls back to its default face, a PDF
         * core font — Times, Helvetica, Courier. None of them carries a rupee
         * sign, so every figure set in one came out as "?".
         */
        foreach (['Times', 'Helvetica', 'Courier'] as $core) {
            $this->assertStringNotContainsString($core, $pdf, $core . ' has no rupee sign in it');
        }
    }
}
