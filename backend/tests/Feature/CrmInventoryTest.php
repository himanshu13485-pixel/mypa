<?php

namespace Tests\Feature;

use App\Models\Crm\InventoryItem;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a company sells, and what is left of it.
 *
 * The rule the whole thing rests on: a tax invoice that is final takes its
 * lines out of the room, and nothing else does. A draft is not a sale, a
 * proforma is a quote, a cancelled invoice never happened.
 *
 * Everything else here is that rule surviving contact with an editor -
 * quantities changed, lines removed, documents un-finalised and deleted -
 * because a count that is only right until somebody corrects a typo is not
 * a count anybody can use.
 */
class CrmInventoryTest extends TestCase
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

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME', 'status' => 'active']);
        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->companyId = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Trading', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            'sells' => 'products', 'tax_required' => false,
        ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Northwind Trading',
        ])->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    private function product(string $name, float $qty = 10, float $price = 500): array
    {
        return $this->as()->postJson('/api/v1/crm/inventory', [
            'issuing_company_id' => $this->companyId,
            'name' => $name, 'kind' => 'product',
            'unit_price' => $price, 'tax_rate' => 18, 'quantity' => $qty,
        ])->assertCreated()->json('data');
    }

    private function raise(array $line, string $status = 'final', string $kind = 'invoice'): array
    {
        return $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'status' => $status,
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'no_tax' => true,
            'items' => [$line],
        ])->assertCreated()->json();
    }

    private function line(array $item, float $qty): array
    {
        return [
            'inventory_item_id' => InventoryItem::where('uuid', $item['uuid'])->value('id'),
            // Still required by the stock Work Order method. A company
            // selling things switches it off in its own setup; the engine
            // does not care either way.
            'membership' => 'Goods',
            'plan_name' => $item['name'],
            'validity_from' => now()->toDateString(),
            'validity_to' => now()->addYear()->toDateString(),
            'qty' => $qty,
            'unit_price' => $item['unit_price'],
        ];
    }

    private function left(array $item): float
    {
        return (float) InventoryItem::where('uuid', $item['uuid'])->value('quantity');
    }

    // ---- The list ---------------------------------------------------------

    public function test_a_service_is_listed_but_never_counted(): void
    {
        $service = $this->as()->postJson('/api/v1/crm/inventory', [
            'issuing_company_id' => $this->companyId,
            'name' => 'Annual audit', 'kind' => 'service', 'unit_price' => 25000, 'tax_rate' => 18,
            // Offered, and rightly ignored: nobody has three audits in a drawer.
            'quantity' => 3,
        ])->assertCreated()->json('data');

        $this->assertNull($service['quantity']);
    }

    public function test_an_opening_count_says_where_the_stock_came_from(): void
    {
        $item = $this->product('Blue widget', qty: 40);

        $moves = $this->as()->getJson("/api/v1/crm/inventory/{$item['uuid']}/moves")->assertOk()->json('data');

        $this->assertCount(1, $moves);
        $this->assertEquals(40, $moves[0]['qty']);
        $this->assertSame('Opening count', $moves[0]['note']);
    }

    public function test_a_count_is_corrected_by_saying_what_is_on_the_shelf(): void
    {
        $item = $this->product('Blue widget', qty: 40);

        $this->as()->postJson("/api/v1/crm/inventory/{$item['uuid']}/adjust", [
            'counted' => 37, 'reason' => 'damage', 'note' => 'Three broken in transit',
        ])->assertOk();

        $this->assertEquals(37, $this->left($item));
        // And the correction says so, rather than the number simply changing.
        $this->assertEquals(-3, $this->as()->getJson("/api/v1/crm/inventory/{$item['uuid']}/moves")->json('data.0.qty'));
    }

    public function test_the_count_cannot_be_typed_over_through_the_back_door(): void
    {
        $item = $this->product('Blue widget', qty: 40);

        $this->as()->putJson("/api/v1/crm/inventory/{$item['uuid']}", [
            'name' => 'Blue widget', 'quantity' => 999,
        ])->assertOk();

        $this->assertEquals(40, $this->left($item));
    }

    // ---- What a document does to it ---------------------------------------

    public function test_a_final_invoice_takes_its_lines_out_of_the_room(): void
    {
        $item = $this->product('Blue widget', qty: 10);

        $this->raise($this->line($item, 4));

        $this->assertEquals(6, $this->left($item));
    }

    public function test_a_draft_takes_nothing_until_it_is_final(): void
    {
        $item = $this->product('Blue widget', qty: 10);

        $raised = $this->raise($this->line($item, 4), status: 'draft');
        $this->assertEquals(10, $this->left($item));

        $uuid = $raised['data']['uuid'];
        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", [
            'status' => 'final',
            'client_uuid' => $this->clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online',
            'dispatch_status' => 'pending', 'no_tax' => true,
            'items' => [$this->line($item, 4)],
        ])->assertOk();

        $this->assertEquals(6, $this->left($item));
    }

    public function test_a_proforma_is_a_quote_and_empties_no_shelf(): void
    {
        $item = $this->product('Blue widget', qty: 10);

        $this->raise($this->line($item, 4), kind: 'proforma');

        $this->assertEquals(10, $this->left($item));
    }

    public function test_changing_the_quantity_settles_the_difference(): void
    {
        $item = $this->product('Blue widget', qty: 10);
        $uuid = $this->raise($this->line($item, 4))['data']['uuid'];
        $this->assertEquals(6, $this->left($item));

        $this->as()->putJson("/api/v1/crm/invoices/{$uuid}", [
            'status' => 'final',
            'client_uuid' => $this->clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online',
            'dispatch_status' => 'pending', 'no_tax' => true,
            'items' => [$this->line($item, 3)],
        ])->assertOk();

        // Three sold, not seven: the document is re-read, not re-applied.
        $this->assertEquals(7, $this->left($item));
    }

    public function test_a_cancelled_invoice_gives_everything_back(): void
    {
        $item = $this->product('Blue widget', qty: 10);
        $uuid = $this->raise($this->line($item, 4))['data']['uuid'];

        $this->as()->postJson("/api/v1/crm/invoices/{$uuid}/cancel")->assertOk();

        $this->assertEquals(10, $this->left($item));
    }

    public function test_a_deleted_invoice_gives_everything_back_too(): void
    {
        $item = $this->product('Blue widget', qty: 10);
        $uuid = $this->raise($this->line($item, 4))['data']['uuid'];

        $this->as()->deleteJson("/api/v1/crm/invoices/{$uuid}")->assertOk();

        $this->assertEquals(10, $this->left($item));
    }

    public function test_selling_more_than_there_is_says_so_and_still_sells(): void
    {
        $item = $this->product('Blue widget', qty: 3);

        $raised = $this->raise($this->line($item, 5));

        // The sale is a fact; the count was somebody's last guess. Refusing
        // the invoice would stop the business to protect the bookkeeping.
        $this->assertEquals(-2, $this->left($item));
        $this->assertNotEmpty($raised['stock_short']);
        $this->assertStringContainsString('Blue widget', $raised['stock_short'][0]);
    }

    public function test_a_line_cannot_name_another_companys_stock(): void
    {
        $other = $this->as()->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Exports', 'invoice_prefix' => 'EX-', 'proforma_prefix' => 'PEX-',
            'sells' => 'products', 'tax_required' => false,
        ])->assertCreated()->json('data.id');

        $theirs = $this->as()->postJson('/api/v1/crm/inventory', [
            'issuing_company_id' => $other, 'name' => 'Export widget', 'kind' => 'product', 'quantity' => 5,
        ])->assertCreated()->json('data');

        $this->as()->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice', 'status' => 'final',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_category' => 'new', 'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance', 'subscription_type' => 'online',
            'dispatch_status' => 'pending', 'no_tax' => true,
            'items' => [$this->line($theirs + ['unit_price' => 100], 1)],
        ])->assertStatus(422);

        $this->assertEquals(5, $this->left($theirs));
    }

    public function test_something_already_sold_is_switched_off_rather_than_deleted(): void
    {
        $item = $this->product('Blue widget', qty: 10);
        $this->raise($this->line($item, 1));

        $this->as()->deleteJson("/api/v1/crm/inventory/{$item['uuid']}")->assertOk();

        // Still there, so the invoice that sold it still reads properly.
        $row = InventoryItem::where('uuid', $item['uuid'])->first();
        $this->assertNotNull($row);
        $this->assertFalse($row->is_active);
    }

    public function test_the_moves_name_the_document_that_caused_them(): void
    {
        $item = $this->product('Blue widget', qty: 10);
        $this->raise($this->line($item, 2));

        $move = $this->as()->getJson("/api/v1/crm/inventory/{$item['uuid']}/moves")->assertOk()->json('data.0');

        $this->assertEquals(-2, $move['qty']);
        $this->assertSame('invoice', $move['reason']);
        $this->assertSame(Invoice::first()->number, $move['invoice']['number']);
    }
}
