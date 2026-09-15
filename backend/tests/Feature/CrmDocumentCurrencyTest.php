<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A proforma or invoice written in the currency the client pays in.
 *
 * The currency used to be the issuing company's alone, so a rupee company
 * could not raise a dollar invoice for a client abroad without a second
 * company set up to do nothing else. A document now names its own, from a
 * short list, and falls back to its company's when it does not.
 */
class CrmDocumentCurrencyTest extends TestCase
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

        // A known market rate, so the INR equivalent is a fixed figure:
        // 96 less the default two-rupee bank margin is 94.
        Cache::put('fx-inr-USD', 96.0, now()->addHour());

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        // A rupee company.
        $this->companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Northwind Trading LLC',
        ])->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01',
                'validity_to' => '2027-08-31',
                'qty' => 1,
                'unit_price' => 1000,
            ]],
        ];
    }

    public function test_a_rupee_company_can_raise_a_dollar_invoice(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['currency' => 'USD']))
            ->assertCreated()->json('data.uuid');

        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        $this->assertSame('USD', $invoice->currency);

        // And it carries the rupee figure the books need, as a dollar
        // company's documents always have.
        $this->assertSame('INR', $invoice->fx_currency);
        $this->assertEquals(94.0, (float) $invoice->fx_rate);
        $this->assertEquals(94000.0, (float) $invoice->total_fx);
    }

    public function test_a_proforma_can_be_in_another_currency_too(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['kind' => 'proforma', 'currency' => 'EUR']))
            ->assertCreated()->json('data.uuid');

        $this->assertSame('EUR', Invoice::where('uuid', $uuid)->value('currency'));
    }

    public function test_left_out_it_is_the_companys_currency(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload())
            ->assertCreated()->json('data.uuid');

        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();
        $this->assertSame('INR', $invoice->currency);
        // A rupee document needs no rupee equivalent.
        $this->assertNull($invoice->total_fx);
    }

    public function test_only_the_currencies_on_the_list(): void
    {
        $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['currency' => 'JPY']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');
    }

    public function test_the_list_is_served_to_the_form(): void
    {
        $this->as()->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->assertJsonPath('data.currencies', Invoice::CURRENCIES);
    }

    public function test_a_document_can_change_currency_before_any_money_is_taken(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload())
            ->assertCreated()->json('data.uuid');

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload(['currency' => 'GBP']))
            ->assertOk();

        $this->assertSame('GBP', Invoice::where('uuid', $uuid)->value('currency'));
    }

    public function test_but_not_once_it_has(): void
    {
        // Re-labelling a document with money against it would turn "$800
        // received" into "₹800 received" without touching a figure.
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['currency' => 'USD']))
            ->assertCreated()->json('data.uuid');
        $invoice = Invoice::where('uuid', $uuid)->firstOrFail();

        InvoicePayment::create(['invoice_id' => $invoice->id, 'amount' => 500, 'received_at' => '2026-09-02']);

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload(['currency' => 'INR']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');

        // Saving it again in the same currency is still fine.
        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload(['currency' => 'USD']))
            ->assertOk();
    }

    public function test_an_edit_that_says_nothing_keeps_the_currency(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['currency' => 'AED']))
            ->assertCreated()->json('data.uuid');

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload())
            ->assertOk();

        $this->assertSame('AED', Invoice::where('uuid', $uuid)->value('currency'));
    }

    public function test_the_list_totals_each_currency_on_its_own(): void
    {
        $this->as()->postJson('/api/v1/crm/invoices', $this->payload())->assertCreated();
        $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['currency' => 'USD']))->assertCreated();

        $rows = collect($this->as()->getJson('/api/v1/crm/invoices?kind=invoice')
            ->assertOk()->json('totals.by_currency'))->keyBy('currency');

        // A thousand dollars and a thousand rupees are not two thousand of
        // anything.
        $this->assertEqualsCanonicalizing(['INR', 'USD'], $rows->keys()->all());
        $this->assertEquals(1000, $rows['USD']['total']);
        $this->assertEquals(1000, $rows['INR']['total']);
        $this->assertSame(1, $rows['USD']['count']);
    }
}
