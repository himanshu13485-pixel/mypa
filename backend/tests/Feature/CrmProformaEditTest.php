<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a proforma.
 *
 * A proforma is a quotation, so it is meant to be argued over — the price
 * moves, a line is added, the client asks for a different quantity. It stays
 * editable for exactly as long as it is still only a quotation.
 *
 * Two things end that. Converting it makes a tax invoice that refers back to
 * it, and editing the quotation afterwards would leave the two disagreeing
 * about what was sold. Cancelling ends it because a cancelled document is a
 * record of something withdrawn, not a draft.
 *
 * The right is its own: 'proforma', separate from 'invoices', so a company
 * can let a salesperson quote without letting them bill.
 */
class CrmProformaEditTest extends TestCase
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
            'organization_id' => $this->org->id,
            'user_id' => $this->adminUser->id,
            'crm_role' => 'admin',
            'status' => 'active',
        ]);

        $this->companyId = $this->as($this->adminUser)->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->as($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
        ])->assertCreated()->json('data.uuid');
    }

    private function as(User $user)
    {
        return $this->actingAs($user)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function raise(string $kind = 'proforma', float $price = 10000): string
    {
        return $this->as($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['description' => 'Brass fittings', 'qty' => 1, 'unit_price' => $price]],
        ])->assertCreated()->json('data.uuid');
    }

    /** The payload an edit sends: everything, with the changes in it. */
    private function edit(User $user, string $uuid, array $items)
    {
        return $this->as($user)->putJson("/api/v1/crm/invoices/{$uuid}", [
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => $items,
        ]);
    }

    // ---- while it is still a quotation --------------------------------------

    public function test_a_proforma_can_be_edited(): void
    {
        $uuid = $this->raise();

        $this->edit($this->adminUser, $uuid, [
            ['description' => 'Brass fittings', 'qty' => 2, 'unit_price' => 9000],
        ])->assertOk();

        $doc = $this->as($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}")->assertOk();

        $this->assertSame('18000.00', $doc->json('data.subtotal'));
        $this->assertSame(2, (int) $doc->json('data.items.0.qty'));
    }

    public function test_lines_can_be_added_and_removed(): void
    {
        $uuid = $this->raise();

        $this->edit($this->adminUser, $uuid, [
            ['description' => 'Brass fittings', 'qty' => 1, 'unit_price' => 10000],
            ['description' => 'Steel casing', 'qty' => 4, 'unit_price' => 900],
        ])->assertOk();

        $this->assertCount(2, $this->as($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}")->json('data.items'));

        $this->edit($this->adminUser, $uuid, [
            ['description' => 'Steel casing', 'qty' => 4, 'unit_price' => 900],
        ])->assertOk();

        $this->assertCount(1, $this->as($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}")->json('data.items'));
    }

    public function test_its_number_and_issuing_company_never_move(): void
    {
        $uuid = $this->raise();
        $before = $this->as($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}")->json('data.number');

        // The series identity is fixed at creation; an edit cannot re-file
        // the document under another company or turn it into an invoice.
        $this->as($this->adminUser)->putJson("/api/v1/crm/invoices/{$uuid}", [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['description' => 'Brass fittings', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertOk();

        $after = $this->as($this->adminUser)->getJson("/api/v1/crm/invoices/{$uuid}")->assertOk();

        $this->assertSame($before, $after->json('data.number'));
        $this->assertSame('proforma', $after->json('data.kind'));
    }

    // ---- and when it stops being one ---------------------------------------

    public function test_a_converted_proforma_can_no_longer_be_edited(): void
    {
        $uuid = $this->raise();

        $this->as($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/convert")->assertCreated();

        // The tax invoice refers back to it; editing the quotation now would
        // leave the two disagreeing about what was sold.
        $this->edit($this->adminUser, $uuid, [
            ['description' => 'Brass fittings', 'qty' => 5, 'unit_price' => 10000],
        ])->assertStatus(422);
    }

    public function test_a_cancelled_proforma_can_no_longer_be_edited(): void
    {
        $uuid = $this->raise();

        $this->as($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/cancel")->assertOk();

        $this->edit($this->adminUser, $uuid, [
            ['description' => 'Brass fittings', 'qty' => 5, 'unit_price' => 10000],
        ])->assertStatus(422);
    }

    // ---- the right it needs -------------------------------------------------

    public function test_quoting_and_billing_are_separate_rights(): void
    {
        $seller = User::factory()->create();
        $seller->settings()->create([]);
        $seller->profile()->create(['timezone' => 'UTC']);
        $member = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $seller->id,
            'crm_role' => 'employee',
            'status' => 'active',
            // May quote. May not bill.
            'rights' => ['proforma' => ['view', 'create', 'edit'], 'clients' => ['view']],
        ]);

        // Their own quotation — staff see their own book, so a document
        // raised by somebody else is not theirs to edit or even to find.
        $proforma = $this->as($seller)->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['description' => 'Brass fittings', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertCreated()->json('data.uuid');

        $this->edit($seller, $proforma, [
            ['description' => 'Brass fittings', 'qty' => 3, 'unit_price' => 9500],
        ])->assertOk();

        // Billing is a different tick, and they do not have it.
        $this->as($seller)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['description' => 'Brass fittings', 'qty' => 1, 'unit_price' => 10000]],
        ])->assertForbidden();

        // And without the edit tick, quoting is read-only.
        $member->update(['rights' => ['proforma' => ['view'], 'clients' => ['view']]]);

        $this->edit($seller, $proforma, [
            ['description' => 'Brass fittings', 'qty' => 4, 'unit_price' => 9500],
        ])->assertForbidden();
    }
}
