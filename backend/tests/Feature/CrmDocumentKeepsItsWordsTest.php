<?php

namespace Tests\Feature;

use App\Models\Crm\CustomField;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A document can be opened again and saved.
 *
 * Two things conspired to make that untrue of any company using dropdowns
 * on its Work Order.
 *
 * House style was applied to the value picked from the dropdown, so
 * "GrapOut Consulting" was stored as "Grapout Consulting" — which is not
 * one of the options. The document saved once and was refused every time
 * afterwards, on a value the person editing it had not touched and could
 * not correct, since the list they were offered spelt it the other way.
 *
 * And a dropdown's options are a living list. A plan is retired, a name is
 * re-spelt, and every document raised under the old list is refused the
 * same way. What is on the paper stays valid; only a value somebody adds
 * today is held to today's list.
 */
class CrmDocumentKeepsItsWordsTest extends TestCase
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

        $this->adminUser = User::factory()->create();
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** Turn a Work Order column into this company's own dropdown. */
    private function dropdown(string $key, array $options): CustomField
    {
        return CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'work_order',
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'type' => 'select',
            'options' => $options,
            'is_builtin' => true,
            'status' => 'approved',
        ]);
    }

    private function payload(array $lineOver = []): array
    {
        return [
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
            'items' => [$lineOver + [
                'membership' => 'Standard',
                'plan_name' => 'Annual listing',
                'validity_from' => '2026-09-01',
                'validity_to' => '2027-08-31',
                'qty' => 1,
                'unit_price' => 10000,
            ]],
        ];
    }

    public function test_a_picked_option_is_stored_as_the_company_spells_it(): void
    {
        // The brand mark is the company's own: house style would title it
        // into something that is no longer on their list.
        $this->dropdown('membership', ['GrapOut Consulting', 'GrapOut Retail']);

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload([
            'membership' => 'GrapOut Consulting',
        ]))->assertCreated()->json('data.uuid');

        $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.items.0.membership', 'GrapOut Consulting');
    }

    public function test_a_document_can_be_opened_and_saved_again(): void
    {
        // The bug, end to end: raise it, then save it untouched.
        $this->dropdown('membership', ['GrapOut Consulting', 'GrapOut Retail']);

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload([
            'membership' => 'GrapOut Consulting',
        ]))->assertCreated()->json('data.uuid');

        $document = $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")->json('data');

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload([
            'membership' => $document['items'][0]['membership'],
        ]))->assertOk();
    }

    public function test_a_value_the_document_already_carries_survives_the_list_changing(): void
    {
        $this->dropdown('membership', ['Gold', 'Silver']);

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['membership' => 'Gold']))
            ->assertCreated()->json('data.uuid');

        // Gold is retired a year later. The document that carries it is not
        // wrong, and must still be editable.
        CustomField::where('organization_id', $this->org->id)
            ->where('entity', 'work_order')->where('key', 'membership')
            ->update(['options' => json_encode(['Silver', 'Bronze'])]);

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload([
            'membership' => 'Gold',
            'qty' => 2,
        ]))->assertOk();

        // What is not on it is still held to today's list.
        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload(['membership' => 'Platinum']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.membership');
    }

    public function test_a_new_document_is_held_to_todays_list(): void
    {
        // Grandfathering is for a document being edited, not a licence to
        // raise a new one on a retired plan.
        $this->dropdown('membership', ['Silver']);

        $this->as()->postJson('/api/v1/crm/invoices', $this->payload(['membership' => 'Gold']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.membership');
    }

    public function test_a_companys_own_column_keeps_its_words_too(): void
    {
        $field = CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'work_order', 'key' => 'type', 'label' => 'Type',
            'type' => 'select', 'options' => ['Renewal', 'Fresh'],
            'status' => 'approved',
        ]);

        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload([
            'custom_fields' => ['type' => 'Renewal'],
        ]))->assertCreated()->json('data.uuid');

        $field->update(['options' => ['Fresh', 'Upgrade']]);

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload([
            'custom_fields' => ['type' => 'Renewal'],
        ]))->assertOk();

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", $this->payload([
            'custom_fields' => ['type' => 'Downgrade'],
        ]))->assertStatus(422);
    }

    public function test_free_text_still_gets_house_style(): void
    {
        // Nothing is picked from a list here, so what somebody types is
        // tidied as it always has been.
        $uuid = $this->as()->postJson('/api/v1/crm/invoices', $this->payload([
            'membership' => 'gold membership',
        ]))->assertCreated()->json('data.uuid');

        $this->as()->getJson("/api/v1/crm/invoices/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.items.0.membership', 'Gold Membership');
    }
}
