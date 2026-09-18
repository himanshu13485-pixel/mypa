<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoiceItem;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sale served to the end of its term is dispatched.
 *
 * Dispatch says where the work has got to, and neither "Due" nor "In
 * process" is true once every work order has run its validity out - the
 * thing was delivered, month by month, and the last month has passed.
 * Somebody had to remember to say so on every invoice, for ever.
 */
class CrmDispatchServedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private int $companyId;

    private int $clientId;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-18 10:00:00'));

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $user = User::factory()->create(['email' => 'boss@grapout.test']);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->memberId = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id,
            'crm_role' => 'admin', 'status' => 'active',
        ])->id;

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Grapout Billing'])->id;
        $this->clientId = Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'Bhavya Steel', 'created_by' => $user->id,
        ])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<int, ?string> $validities  validity_to per work order */
    private function invoice(string $number, string $dispatch, array $validities): Invoice
    {
        $invoice = Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => $number,
            'issuing_company_id' => $this->companyId, 'client_id' => $this->clientId,
            'member_id' => $this->memberId, 'invoice_date' => '2025-09-01',
            'subtotal' => 10000, 'total' => 10000, 'dispatch_status' => $dispatch,
        ]);

        foreach ($validities as $i => $to) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'membership' => 'Standard', 'plan_name' => 'Listing',
                'validity_from' => '2025-09-01', 'validity_to' => $to,
                'qty' => 1, 'unit_price' => 10000, 'amount' => 10000, 'sort' => $i,
            ]);
        }

        return $invoice;
    }

    private function sweep(): void
    {
        $this->artisan('crm:close-served-dispatches')->assertSuccessful();
    }

    public function test_in_process_becomes_dispatched_when_the_term_is_served(): void
    {
        $invoice = $this->invoice('INV-1', 'in_process', ['2026-08-31']);

        $this->sweep();

        $this->assertSame('dispatched', $invoice->fresh()->dispatch_status);
    }

    public function test_so_does_one_still_sitting_as_due(): void
    {
        $invoice = $this->invoice('INV-2', 'pending', ['2026-09-17']);

        $this->sweep();

        $this->assertSame('dispatched', $invoice->fresh()->dispatch_status);
    }

    public function test_a_term_still_running_is_left_alone(): void
    {
        $invoice = $this->invoice('INV-3', 'in_process', ['2026-12-31']);

        $this->sweep();

        $this->assertSame('in_process', $invoice->fresh()->dispatch_status);
    }

    public function test_two_work_orders_wait_for_the_later_one(): void
    {
        // Three months finished in December, twelve months runs to next
        // September: the sale is still being delivered.
        $invoice = $this->invoice('INV-4', 'in_process', ['2025-12-01', '2027-08-31']);

        $this->sweep();
        $this->assertSame('in_process', $invoice->fresh()->dispatch_status);

        // And once the longer one is over too.
        Carbon::setTestNow(Carbon::parse('2027-09-01 06:00:00'));
        $this->sweep();
        $this->assertSame('dispatched', $invoice->fresh()->dispatch_status);
    }

    public function test_the_last_day_is_still_a_day_of_service(): void
    {
        $invoice = $this->invoice('INV-5', 'in_process', ['2026-09-18']);

        // Today is the 18th: the term runs to the end of it.
        $this->sweep();
        $this->assertSame('in_process', $invoice->fresh()->dispatch_status);

        Carbon::setTestNow(Carbon::parse('2026-09-19 01:30:00'));
        $this->sweep();
        $this->assertSame('dispatched', $invoice->fresh()->dispatch_status);
    }

    public function test_what_somebody_said_by_hand_is_not_overruled(): void
    {
        // Partial dispatched is a person's own account of a delivery, and
        // this command has no business rewriting it.
        $invoice = $this->invoice('INV-6', 'partial', ['2026-08-31']);

        $this->sweep();

        $this->assertSame('partial', $invoice->fresh()->dispatch_status);
    }

    public function test_a_work_order_with_no_end_date_never_ends(): void
    {
        $invoice = $this->invoice('INV-7', 'in_process', ['2026-08-31', null]);

        $this->sweep();

        $this->assertSame('in_process', $invoice->fresh()->dispatch_status);
    }

    public function test_a_cancelled_document_is_not_dispatched(): void
    {
        $invoice = $this->invoice('INV-8', 'in_process', ['2026-08-31']);
        $invoice->update(['status' => 'cancelled']);

        $this->sweep();

        $this->assertSame('in_process', $invoice->fresh()->dispatch_status);
    }
}
