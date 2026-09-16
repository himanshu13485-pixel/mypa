<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two questions that used to be one field.
 *
 * Whether a client is new is a fact about the books, so the system answers it:
 * the first document a client is ever given is new business and every one
 * after it is repeat business. What KIND of business it is - Regular, Global,
 * SEZ - is a decision, so a person makes it, out of a list the company keeps.
 */
class CrmClientStatusAndSegmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private string $clientUuid;
    private string $otherUuid;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin']);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing'])->id;
        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', ['company_name' => 'Bhavya Steel'])
            ->assertCreated()->json('data.uuid');
        $this->otherUuid = $this->as()->postJson('/api/v1/crm/clients', ['company_name' => 'Shah Brothers'])
            ->assertCreated()->json('data.uuid');
    }

    private function as()
    {
        return $this->actingAs($this->adminUser);
    }

    private function raise(string $clientUuid, array $extra = [], string $kind = 'invoice')
    {
        return $this->as()->postJson('/api/v1/crm/invoices', $extra + [
            'kind' => $kind,
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_segment' => 'Regular',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => now()->toDateString(), 'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1, 'unit_price' => 1000,
            ]],
        ]);
    }

    /** The fields an update has to send back with it. */
    private function payloadFor(array $document): array
    {
        return [
            'invoice_date' => $document['invoice_date'],
            'due_date' => $document['due_date'],
            'client_segment' => $document['client_segment'],
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => now()->toDateString(), 'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1, 'unit_price' => 1000,
            ]],
        ];
    }

    public function test_the_first_document_is_new_business_and_the_next_is_repeat(): void
    {
        // Even claiming otherwise: the books decide, not the form.
        $first = $this->raise($this->clientUuid, ['client_category' => 'existing'])->assertCreated()->json('data');
        $this->assertSame('new', $first['client_category']);

        $second = $this->raise($this->clientUuid)->assertCreated()->json('data');
        $this->assertSame('existing', $second['client_category']);

        // A different client starts again at new business.
        $this->assertSame('new', $this->raise($this->otherUuid)->assertCreated()->json('data.client_category'));

        // A proforma counts as a first meeting too.
        $proforma = $this->raise($this->otherUuid, [], 'proforma')->assertCreated()->json('data');
        $this->assertSame('existing', $proforma['client_category']);
    }

    public function test_a_backdated_document_takes_its_place_at_the_front(): void
    {
        $later = $this->raise($this->clientUuid, ['invoice_date' => '2026-09-10'])->assertCreated()->json('data');
        $this->assertSame('new', $later['client_category']);

        // Paperwork caught up with later: this one came first all along.
        $earlier = $this->raise($this->clientUuid, ['invoice_date' => '2026-08-01'])->assertCreated()->json('data');

        $this->assertSame('new', Invoice::where('uuid', $earlier['uuid'])->value('client_category'));
        $this->assertSame('existing', Invoice::where('uuid', $later['uuid'])->value('client_category'));
    }

    public function test_a_sale_called_off_never_happened(): void
    {
        $first = $this->raise($this->clientUuid)->assertCreated()->json('data');
        $second = $this->raise($this->clientUuid)->assertCreated()->json('data');
        $this->assertSame('existing', Invoice::where('uuid', $second['uuid'])->value('client_category'));

        $this->as()->postJson('/api/v1/crm/invoices/' . $first['uuid'] . '/cancel')->assertOk();

        // With the first one cancelled, the one that stands is the first.
        $this->assertSame('new', Invoice::where('uuid', $second['uuid'])->value('client_category'));
    }

    public function test_a_quotation_does_not_make_the_first_sale_a_repeat(): void
    {
        // The usual order of things: quote first, then the bill for it.
        $quote = $this->raise($this->clientUuid, [], 'proforma')->assertCreated()->json('data');
        $this->assertSame('new', $quote['client_category']);

        $first = $this->raise($this->clientUuid)->assertCreated()->json('data');
        $this->assertSame('new', $first['client_category']);

        // The sale after it is the repeat.
        $this->assertSame('existing', $this->raise($this->clientUuid)->assertCreated()->json('data.client_category'));
    }

    public function test_a_status_set_by_hand_is_left_alone(): void
    {
        $first = $this->raise($this->clientUuid)->assertCreated()->json('data');

        // Two firms under one client record: the second sale is new business
        // whatever the books say, and somebody says so.
        $second = $this->raise($this->clientUuid, [
            'client_category' => 'new', 'client_category_manual' => true,
        ])->assertCreated()->json('data');

        $this->assertSame('new', $second['client_category']);
        $this->assertTrue($second['client_category_manual']);

        // A third sale restates the client, and the hand-set one stands.
        $this->raise($this->clientUuid)->assertCreated();
        $this->assertSame('new', Invoice::where('uuid', $second['uuid'])->value('client_category'));
        $this->assertSame('new', Invoice::where('uuid', $first['uuid'])->value('client_category'));

        // Handed back to the books, it reads as the ledger says again.
        $this->as()->putJson('/api/v1/crm/invoices/' . $second['uuid'], [
            'client_category_manual' => false,
        ] + $this->payloadFor($second))->assertOk();
        $this->assertSame('existing', Invoice::where('uuid', $second['uuid'])->value('client_category'));
    }

    public function test_the_kind_of_business_comes_from_the_companys_own_list(): void
    {
        $this->assertSame(
            ['Regular', 'Global', 'SEZ'],
            $this->as()->getJson('/api/v1/crm/masters/client-segments')->assertOk()->json('data.client_segments'),
        );

        $this->assertSame('SEZ', $this->raise($this->clientUuid, ['client_segment' => 'SEZ'])
            ->assertCreated()->json('data.client_segment'));

        // A word nobody offered falls back to the first of the list rather
        // than saving a document nobody can filter to.
        $this->assertSame('Regular', $this->raise($this->otherUuid, ['client_segment' => 'Moon Base'])
            ->assertCreated()->json('data.client_segment'));

        // The Admin rewrites the list; a document can then carry the new word.
        $this->as()->putJson('/api/v1/crm/masters/client-segments', [
            'client_segments' => ['Regular', 'Global', 'SEZ', 'Deemed Export'],
        ])->assertOk();
        $this->assertSame('Deemed Export', $this->raise($this->clientUuid, ['client_segment' => 'Deemed Export'])
            ->assertCreated()->json('data.client_segment'));
    }

    public function test_the_list_can_be_read_by_status_and_by_kind(): void
    {
        $this->raise($this->clientUuid, ['client_segment' => 'Global']);     // new, Global
        $this->raise($this->clientUuid, ['client_segment' => 'Global']);     // existing, Global
        $this->raise($this->otherUuid, ['client_segment' => 'SEZ']);         // new, SEZ

        $read = fn (string $query) => collect($this->as()
            ->getJson('/api/v1/crm/invoices?kind=invoice&' . $query)->assertOk()->json('data'));

        $this->assertCount(2, $read('client_category[]=new'));
        $this->assertCount(1, $read('client_category[]=existing'));
        $this->assertCount(2, $read('client_segment[]=Global'));
        $this->assertCount(1, $read('client_segment[]=SEZ'));
        $this->assertCount(1, $read('client_segment[]=Global&client_category[]=existing'));
    }

    public function test_an_employee_cannot_rewrite_the_companys_list(): void
    {
        $staff = User::factory()->create(['email' => 'seller@acme.test']);
        $staff->settings()->create([]);
        $staff->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $staff->id, 'crm_role' => 'employee',
            'rights' => ['invoices' => ['view', 'create']],
        ]);

        // Read it, to raise a document with it.
        $this->actingAs($staff)->getJson('/api/v1/crm/masters/client-segments')->assertOk();
        $this->actingAs($staff)->putJson('/api/v1/crm/masters/client-segments', [
            'client_segments' => ['Only Mine'],
        ])->assertForbidden();
    }
}
