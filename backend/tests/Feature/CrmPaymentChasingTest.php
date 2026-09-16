<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money not yet in, asking the person whose sale it was.
 *
 * The company already writes to the client about an unpaid invoice; nothing
 * ever told the salesperson, who is the one who can pick up the phone. This
 * is their own nudge - about their own sales - and it can be put off to the
 * day the client actually promised.
 */
class CrmPaymentChasingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private User $sellerUser;
    private User $otherUser;
    private Member $seller;
    private Member $other;
    private string $clientUuid;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        [$this->adminUser] = $this->member('boss@acme.test', 'admin');
        [$this->sellerUser, $this->seller] = $this->member('seller@acme.test', 'employee', ['invoices' => ['view', 'create', 'edit']]);
        [$this->otherUser, $this->other] = $this->member('other@acme.test', 'employee', ['invoices' => ['view', 'create', 'edit']]);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing'])->id;
        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel', 'contact_person' => 'Mihir Shah', 'mobile' => '9810011111',
        ])->assertCreated()->json('data.uuid');
    }

    /** @return array{0: User, 1: Member} */
    private function member(string $email, string $role, ?array $rights = null): array
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);
        $member = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id,
            'crm_role' => $role, 'status' => 'active', 'rights' => $rights, 'is_salesperson' => true,
        ]);

        return [$user, $member];
    }

    private function as(?User $who = null)
    {
        return $this->actingAs($who ?? $this->adminUser);
    }

    /** Raised by the seller, due in a week unless told otherwise. */
    private function raise(?User $who = null, array $extra = []): array
    {
        return $this->as($who ?? $this->sellerUser)->postJson('/api/v1/crm/invoices', $extra + [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addWeek()->toDateString(),
            'client_segment' => 'Regular',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'dispatched',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => now()->toDateString(), 'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1, 'unit_price' => 10000,
            ]],
        ])->assertCreated()->json('data');
    }

    private function due(?User $who = null): array
    {
        return $this->as($who ?? $this->sellerUser)
            ->getJson('/api/v1/crm/invoices-payment-due')->assertOk()->json('data');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_unpaid_invoice_asks_its_salesperson_once_it_falls_due(): void
    {
        $invoice = $this->raise();

        // Not yet: the client still has the week they were given.
        $this->assertSame([], $this->due());

        Carbon::setTestNow(now()->addDays(9));
        $asking = $this->due();

        $this->assertCount(1, $asking);
        $this->assertSame($invoice['number'], $asking[0]['number']);
        $this->assertSame('Bhavya Steel', $asking[0]['client']);
        $this->assertSame('Mihir Shah', $asking[0]['contact_person']);
        $this->assertSame('9810011111', $asking[0]['mobile']);
        $this->assertEquals(10000, $asking[0]['balance']);
        $this->assertSame(2, $asking[0]['overdue_days']);
    }

    public function test_part_payment_leaves_the_balance_asking(): void
    {
        $invoice = $this->raise();
        Carbon::setTestNow(now()->addDays(9));

        InvoicePayment::create([
            'invoice_id' => Invoice::where('uuid', $invoice['uuid'])->value('id'),
            'amount' => 4000, 'received_at' => now()->toDateString(),
        ]);
        Invoice::where('uuid', $invoice['uuid'])->first()->refreshPaymentStatus();

        $asking = $this->due();
        $this->assertCount(1, $asking);
        $this->assertSame('partial', $asking[0]['payment_status']);
        $this->assertEquals(4000, $asking[0]['received']);
        $this->assertEquals(6000, $asking[0]['balance']);
    }

    public function test_settled_in_full_it_goes_quiet_for_good(): void
    {
        $invoice = $this->raise();
        Carbon::setTestNow(now()->addDays(9));
        $this->assertCount(1, $this->due());

        InvoicePayment::create([
            'invoice_id' => Invoice::where('uuid', $invoice['uuid'])->value('id'),
            'amount' => 10000, 'received_at' => now()->toDateString(),
        ]);
        Invoice::where('uuid', $invoice['uuid'])->first()->refreshPaymentStatus();

        $this->assertSame([], $this->due());
        $this->assertNull(Invoice::where('uuid', $invoice['uuid'])->value('payment_remind_at'));
    }

    public function test_it_can_be_put_off_to_the_day_the_client_promised(): void
    {
        $invoice = $this->raise();
        Carbon::setTestNow(now()->addDays(9));

        $this->as($this->sellerUser)->postJson('/api/v1/crm/invoices/' . $invoice['uuid'] . '/payment-reminder', [
            'until' => now()->addDays(4)->toDateString(),
            'note' => 'Cheque on the 5th.',
        ])->assertOk();

        $this->assertSame([], $this->due());

        Carbon::setTestNow(now()->addDays(5));
        $this->assertCount(1, $this->due());
    }

    public function test_putting_it_off_with_no_date_uses_the_companys_rhythm(): void
    {
        $invoice = $this->raise();
        Carbon::setTestNow(now()->addDays(9));

        $this->as($this->sellerUser)->postJson('/api/v1/crm/invoices/' . $invoice['uuid'] . '/payment-reminder')->assertOk();
        $this->assertSame([], $this->due());

        // Three days is the default rhythm.
        Carbon::setTestNow(now()->addDays(3)->addMinute());
        $this->assertCount(1, $this->due());
    }

    public function test_it_is_ones_own_sales_one_is_asked_about(): void
    {
        $this->raise();
        Carbon::setTestNow(now()->addDays(9));

        $this->assertCount(1, $this->due($this->sellerUser));
        // Another desk's client is not this person's to ring about.
        $this->assertSame([], $this->due($this->otherUser));
    }

    public function test_a_company_may_switch_the_chasing_off_or_change_its_rhythm(): void
    {
        $this->raise();

        $this->as()->putJson('/api/v1/crm/masters/payment-settings', [
            'settlement_mode' => 'manual',
            'reminders' => ['enabled' => false, 'offsets' => [], 'stop_after' => 4],
            'payment_chase' => ['enabled' => false, 'after_days' => 5, 'repeat_days' => 7],
        ])->assertOk();

        Carbon::setTestNow(now()->addDays(9));
        $this->assertSame([], $this->due());

        $this->assertSame(
            ['enabled' => false, 'after_days' => 5, 'repeat_days' => 7],
            $this->as()->getJson('/api/v1/crm/masters/payment-settings')->assertOk()->json('data.payment_chase'),
        );
    }
}
