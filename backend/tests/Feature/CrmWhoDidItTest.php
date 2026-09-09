<?php

namespace Tests\Feature;

use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Naming the person, and naming the lead.
 *
 * A document said whose client it was and not who raised it, which are two
 * different people wherever an office types up what the field brings in.
 * And the lead log said "Lead #41" — a number to go and look up, forty
 * times a page.
 */
class CrmWhoDidItTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private Member $priya;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = $this->makeUser('admin@acme.test', 'Neha Kapoor');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);
        $this->priya = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->makeUser('priya@acme.test', 'Priya Nair')->id,
            'crm_role' => 'employee', 'status' => 'active', 'is_salesperson' => true,
        ]);
    }

    private function makeUser(string $email, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    public function test_a_document_says_who_raised_it_as_well_as_whose_client_it_is(): void
    {
        $companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');

        // Neha types it up; the account is Priya's.
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $companyId,
            'client_uuid' => $clientUuid,
            'member_uuid' => $this->priya->uuid,
            'invoice_date' => '2026-09-01', 'due_date' => '2026-10-01',
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01', 'validity_to' => '2027-08-31',
                'qty' => 1, 'unit_price' => 10000,
            ]],
        ])->assertCreated()->json('data.uuid');

        // On the document…
        $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.created_by', 'Neha Kapoor')
            ->assertJsonPath('data.salesperson.name', 'Priya Nair');

        // …and in the list, without opening it.
        $this->as()->getJson('/api/v1/crm/invoices?kind=invoice')
            ->assertOk()
            ->assertJsonPath('data.0.created_by', 'Neha Kapoor');
    }

    public function test_a_log_entry_names_its_lead_and_not_only_its_number(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/leads', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');

        $entry = collect($this->as()->getJson('/api/v1/crm/lead-log')->assertOk()->json('data'))->first();

        $this->assertSame('Meridian Motors', $entry['company_name']);
        $this->assertSame($uuid, $entry['lead_uuid']);
        $this->assertNotNull($entry['lead_no']);
    }

    public function test_the_name_is_the_one_the_lead_has_now(): void
    {
        // Renamed after the entry was written: the log reads the lead, so
        // one company does not appear under two names down the page.
        $uuid = $this->as()->postJson('/api/v1/crm/leads', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');

        $this->as()->putJson("/api/v1/crm/leads/{$uuid}", [
            'company_name' => 'Meridian Mobility',
        ])->assertOk();

        $names = collect($this->as()->getJson('/api/v1/crm/lead-log')->assertOk()->json('data'))
            ->pluck('company_name')->unique()->values()->all();

        $this->assertSame(['Meridian Mobility'], $names);
    }

    public function test_an_entry_whose_lead_is_gone_keeps_the_name_it_captured(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/leads', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');

        $this->as()->deleteJson("/api/v1/crm/leads/{$uuid}")->assertOk();

        $deletion = collect($this->as()->getJson('/api/v1/crm/lead-log')->assertOk()->json('data'))
            ->firstWhere('action', 'lead.deleted');

        $this->assertSame('Meridian Motors', $deletion['company_name']);
        $this->assertNull($deletion['lead_uuid'], 'and it does not pretend to link anywhere');
        $this->assertSame(0, Lead::where('uuid', $uuid)->count());
    }
}
