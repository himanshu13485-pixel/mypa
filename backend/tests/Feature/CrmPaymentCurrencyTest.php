<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money in the currency it actually arrived in.
 *
 * An office with a rupee company and a dollar company has two ledgers. A
 * receipt logged against the dollar company is dollars whether or not
 * anybody said so, and it cannot be settled against a rupee invoice - the
 * figure would go straight into the books as though the two were the same
 * number, and nothing downstream would question it.
 */
class CrmPaymentCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Organization $org;
    protected int $rupeeCompany;
    protected int $dollarCompany;
    protected string $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->rupeeCompany = $this->company('GrapOut Strategic Partners Pvt Ltd', 'INR');
        $this->dollarCompany = $this->company('Corpcio Global LLC', 'USD');

        $this->clientUuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'The Boston Consulting Group', 'email' => 'pay@bcg.test',
        ])->assertCreated()->json('data.uuid');
    }

    private function company(string $name, string $currency): int
    {
        return $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => $name,
                'invoice_prefix' => strtoupper(substr($name, 0, 3)) . '-',
                'proforma_prefix' => 'PI-',
                'currency' => $currency,
            ])->assertCreated()->json('data.id');
    }

    private function invoice(int $companyId, float $amount = 800): array
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-12-31',
            'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance',
            'subscription_type' => 'online', 'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31',
                'plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => $amount,
            ]],
        ])->assertCreated()->json('data');
    }

    public function test_a_receipt_takes_the_currency_of_the_company_it_was_paid_to(): void
    {
        $dollars = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/payments', [
            'received_on' => '2026-09-11',
            'issuing_company_id' => $this->dollarCompany,
            'amount' => 800,
            'payment_mode' => 'Stripe',
            'details' => 'Stripe payout - THE BOSTON CONSULTING GROUP',
        ])->assertCreated();

        $dollars->assertJsonPath('data.currency', 'USD');

        // And the rupee company still gets rupees, said or not.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/payments', [
            'received_on' => '2026-09-11',
            'issuing_company_id' => $this->rupeeCompany,
            'amount' => 41760,
            'payment_mode' => 'NEFT',
        ])->assertCreated()->assertJsonPath('data.currency', 'INR');

        // Nothing chosen at all: rupees, because there is nobody to ask.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/payments', [
            'received_on' => '2026-09-11', 'amount' => 500, 'payment_mode' => 'Cash',
        ])->assertCreated()->assertJsonPath('data.currency', 'INR');

        // The list says which money each one is.
        $list = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments')->assertOk();
        $this->assertEqualsCanonicalizing(
            ['USD', 'INR'],
            collect($list->json('summary.by_currency'))->pluck('currency')->all(),
        );
    }

    public function test_dollars_cannot_be_settled_against_a_rupee_invoice(): void
    {
        $rupeeInvoice = $this->invoice($this->rupeeCompany, 41760);

        $receipt = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/payments', [
            'received_on' => '2026-09-11',
            'issuing_company_id' => $this->dollarCompany,
            'amount' => 800,
            'payment_mode' => 'Stripe',
        ])->assertCreated()->json('data.uuid');

        $refused = $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/payments/{$receipt}/claim", ['invoice_uuid' => $rupeeInvoice['uuid']])
            ->assertStatus(422);

        $this->assertStringContainsString('USD', $refused->json('message'));
        $this->assertStringContainsString($rupeeInvoice['number'], $refused->json('message'));

        // The receipt is untouched - nothing half-settled.
        $this->assertSame('unclaimed', PaymentInboxEntry::where('uuid', $receipt)->firstOrFail()->status);

        // Against a dollar invoice it goes through.
        $dollarInvoice = $this->invoice($this->dollarCompany, 800);
        $this->actingAs($this->adminUser)
            ->postJson("/api/v1/crm/payments/{$receipt}/claim", ['invoice_uuid' => $dollarInvoice['uuid']])
            ->assertOk();
    }

    public function test_a_currency_named_outright_is_kept(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/payments', [
            'received_on' => '2026-09-11',
            'issuing_company_id' => $this->rupeeCompany,
            'amount' => 500,
            // A rupee company can still be sent euros by somebody abroad.
            'currency' => 'eur',
        ])->assertCreated()->assertJsonPath('data.currency', 'EUR');
    }
}
