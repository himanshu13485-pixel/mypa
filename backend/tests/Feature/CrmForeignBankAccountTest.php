<?php

namespace Tests\Feature;

use App\Models\Crm\BankAccount;
use App\Models\Crm\Client;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An account a client abroad can actually pay into.
 *
 * A wire needs a SWIFT/BIC, the receiving bank and a routing number, and
 * has no use for an IFSC. What Billing setup saves has to come back from
 * Billing setup - the wire columns were once left off the list the screen
 * reads, so an account saved perfectly reopened blank, which looks exactly
 * like a save that never happened.
 */
class CrmForeignBankAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;
    private Organization $org;
    private IssuingCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->boss = User::factory()->create(['email' => 'boss@acme.test']);
        $this->boss->settings()->create([]);
        $this->boss->profile()->create(['timezone' => 'UTC']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->boss->id, 'crm_role' => 'admin']);
        $this->company = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Corpcio Global LLC']);
    }

    private const WIRE = [
        'label' => 'Mercury',
        'is_swift' => true,
        'beneficiary_name' => 'Corpcio Global LLC',
        'account_no' => '478613819089937',
        'account_type' => 'Checking',
        'receiving_bank' => 'Column N.A.',
        'swift_code' => 'CLNOUS66MER',
        'aba_routing' => '084106768',
        'aba_routing_alt' => '121145349',
        'intermediary_swift' => 'CHASUS33',
        'beneficiary_address' => '2093 Philadelphia Pike, Claymont, DE 19703',
        'receiving_bank_address' => '1 Letterman Drive, San Francisco, CA 94129',
        'note' => 'Quote the invoice number as the payment reference.',
    ];

    public function test_wire_details_come_back_from_billing_setup_exactly_as_they_went_in(): void
    {
        $this->actingAs($this->boss)->postJson('/api/v1/crm/masters/bank-accounts', self::WIRE + [
            'issuing_company_id' => $this->company->id,
            'is_active' => true,
        ])->assertCreated();

        $saved = collect($this->actingAs($this->boss)->getJson('/api/v1/crm/masters')->assertOk()->json('data.bank_accounts'))
            ->firstWhere('label', 'Mercury');

        // Every field the form can write, readable again by the same form.
        foreach (self::WIRE as $field => $value) {
            $this->assertSame($value, $saved[$field], "Billing setup lost {$field}");
        }
    }

    public function test_a_wire_account_prints_what_a_wire_needs_and_no_ifsc(): void
    {
        BankAccount::create(self::WIRE + [
            'organization_id' => $this->org->id,
            'issuing_company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $client = Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'Buyer Inc',
            'email' => 'buyer@client.test', 'created_by' => $this->boss->id,
        ]);
        $uuid = $this->actingAs($this->boss)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice', 'issuing_company_id' => $this->company->id, 'client_uuid' => $client->uuid,
            'invoice_date' => now()->toDateString(), 'due_date' => '2026-12-31',
            'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance',
            'subscription_type' => 'online', 'dispatch_status' => 'pending',
            'items' => [['membership' => 'Standard', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31',
                'plan_name' => 'Plan A', 'qty' => 1, 'unit_price' => 1200]],
        ])->assertCreated()->json('data.uuid');

        $printed = $this->actingAs($this->boss)->getJson('/api/v1/crm/invoices/' . $uuid)
            ->assertOk()->json('data.bank');

        $this->assertTrue($printed['is_swift']);
        $lines = collect($printed['lines']);
        $this->assertSame('CLNOUS66MER', $lines->firstWhere('label', 'SWIFT / BIC')['value']);
        $this->assertSame('Column N.A.', $lines->firstWhere('label', 'Receiving bank')['value']);
        $this->assertSame('084106768', $lines->firstWhere('label', 'ABA routing')['value']);
        $this->assertSame('Corpcio Global LLC', $lines->firstWhere('label', 'Beneficiary')['value']);
        $this->assertSame(self::WIRE['note'], $lines->firstWhere('label', 'Note')['value']);

        // Nothing domestic, and nothing blank taking up a line.
        $this->assertNull($lines->firstWhere('label', 'IFSC'));
        $this->assertNull($lines->firstWhere('label', 'Bank'));
        $this->assertEmpty($lines->filter(fn ($line) => $line['value'] === ''));

        // And the paper itself still renders with them on it.
        $this->actingAs($this->boss)->get('/api/v1/crm/invoices/' . $uuid . '/pdf')->assertOk();
    }

    public function test_the_wire_numbers_are_the_managers_to_see(): void
    {
        BankAccount::create(self::WIRE + [
            'organization_id' => $this->org->id, 'issuing_company_id' => $this->company->id, 'is_active' => true,
        ]);

        $clerk = User::factory()->create(['email' => 'clerk@acme.test']);
        $clerk->settings()->create([]);
        $clerk->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $clerk->id, 'crm_role' => 'employee',
            'status' => 'active', 'rights' => ['invoices' => ['view']],
        ]);

        $seen = collect($this->actingAs($clerk)->getJson('/api/v1/crm/masters')->assertOk()->json('data.bank_accounts'))
            ->firstWhere('label', 'Mercury');

        // The label and the bank, yes; the numbers a payment could be
        // diverted with, no - the same rule the account number already had.
        $this->assertSame('Column N.A.', $seen['receiving_bank']);
        $this->assertNull($seen['swift_code']);
        $this->assertNull($seen['aba_routing']);
        $this->assertNull($seen['aba_routing_alt']);
        $this->assertNull($seen['intermediary_swift']);
        $this->assertSame('…9937', $seen['account_no']);
    }
}
