<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * A document in another currency does not print its rupee conversion.
 *
 * The client pays in that currency, and the rupee figure is for the
 * company's own books. It is still worked out and kept — the charts and the
 * P&L count the document by it — it just is not on the paper.
 */
class CrmForeignDocumentNoRupeeLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dollar_document_keeps_its_rupee_figure_off_the_pdf(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Cache::put('fx-inr-USD', 96.0, now()->addHour());

        $org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);
        Member::create(['organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => 'admin', 'status' => 'active']);
        $as = fn () => $this->actingAs($user)->withHeader('X-Crm-Org', $org->uuid);

        $companyId = $as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Global LLC', 'currency' => 'USD', 'invoice_prefix' => 'AG-', 'proforma_prefix' => 'PAG-',
        ])->assertCreated()->json('data.id');
        $clientUuid = $as()->postJson('/api/v1/crm/clients', ['company_name' => 'Northwind Trading LLC'])
            ->assertCreated()->json('data.uuid');

        $uuid = $as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice', 'issuing_company_id' => $companyId, 'client_uuid' => $clientUuid,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01',
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online', 'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01', 'validity_to' => '2027-08-31', 'qty' => 1, 'unit_price' => 202,
            ]],
        ])->assertCreated()->json('data.uuid');

        // Still worked out and kept, for the charts and the P&L.
        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        $this->assertSame('USD', $invoice->currency);
        $this->assertNotNull($invoice->total_fx);

        $captured = null;
        View::composer('crm.document', function ($view) use (&$captured) {
            $captured = $view->getData();
        });
        $as()->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertOk();
        $html = view('crm.document', $captured)->render();

        // In dollars on the paper, and no rupee line under the total.
        $this->assertStringContainsString('USD 202.00', $html);
        $this->assertStringNotContainsString('INR equivalent', $html);
    }
}
