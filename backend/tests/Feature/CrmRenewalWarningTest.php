<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoiceItem;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\RenewalReminder;
use App\Models\User;
use App\Notifications\CrmNotification;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Saying a work order is about to run out, while there is still time to sell
 * the renewal.
 *
 * Three warnings ahead of each expiry. The executive always hears it in the
 * app — it is their sale. Mail is a decision: theirs is on by default,
 * the client's is off, because writing to a client is not something anybody
 * should discover after it has gone out.
 */
class CrmRenewalWarningTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private User $sellerUser;

    private Member $seller;

    private int $companyId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-09-19 02:00:00'));

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP', 'status' => 'active']);
        $this->adminUser = $this->person('boss@grapout.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->sellerUser = $this->person('priyanshu@grapout.test');
        $this->seller = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->sellerUser->id,
            'crm_role' => 'employee', 'status' => 'active', 'is_salesperson' => true,
        ]);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Grapout Billing'])->id;
        $this->clientId = Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'Bhavya Steel',
            'email' => 'accounts@bhavya.test', 'created_by' => $this->adminUser->id,
        ])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function workOrder(string $expiresOn, string $number = 'INV-1'): InvoiceItem
    {
        $invoice = Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => $number,
            'issuing_company_id' => $this->companyId, 'client_id' => $this->clientId,
            'member_id' => $this->seller->id, 'invoice_date' => '2025-09-20',
            'subtotal' => 10000, 'total' => 10000,
        ]);

        return InvoiceItem::create([
            'invoice_id' => $invoice->id, 'membership' => 'Standard', 'plan_name' => 'Enterprise-12M',
            'validity_from' => '2025-09-20', 'validity_to' => $expiresOn,
            'qty' => 1, 'unit_price' => 10000, 'amount' => 10000, 'sort' => 0,
        ]);
    }

    private function sweep(): void
    {
        $this->artisan('crm:warn-renewals')->assertSuccessful();
    }

    private function settings(array $patch): void
    {
        $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/masters/renewal-reminders', $patch + [
                'enabled' => true, 'offsets' => [30, 15, 7],
                'email_executive' => true, 'email_client' => false,
            ])->assertOk();
    }

    public function test_by_default_the_executive_is_told_and_the_client_is_not(): void
    {
        Notification::fake();
        Mail::fake();
        // Thirty days out, which is the first of the three warnings.
        $this->workOrder('2026-10-19');

        $this->sweep();

        Notification::assertSentTo($this->sellerUser, CrmNotification::class);

        $sent = RenewalReminder::all();
        // The notification, and the executive's e-mail. Nothing to the client.
        $this->assertSame(['executive', 'executive'], $sent->pluck('audience')->sort()->values()->all());
        $this->assertSame(['email', 'notification'], $sent->pluck('channel')->sort()->values()->all());
        $this->assertFalse($sent->pluck('to_email')->contains('accounts@bhavya.test'));
    }

    public function test_the_client_is_written_to_only_once_the_company_says_so(): void
    {
        Notification::fake();
        Mail::fake();
        $this->settings(['email_client' => true]);
        $this->workOrder('2026-10-19');

        $this->sweep();

        $this->assertTrue(
            RenewalReminder::where('audience', 'client')->where('to_email', 'accounts@bhavya.test')->exists(),
        );
    }

    public function test_the_executives_mail_can_be_switched_off_and_the_nudge_remains(): void
    {
        Notification::fake();
        Mail::fake();
        $this->settings(['email_executive' => false]);
        $this->workOrder('2026-10-19');

        $this->sweep();

        // The in-app word is not a setting: it is their sale.
        Notification::assertSentTo($this->sellerUser, CrmNotification::class);
        $this->assertSame(['notification'], RenewalReminder::pluck('channel')->all());
    }

    public function test_three_warnings_go_out_and_each_one_only_once(): void
    {
        Notification::fake();
        Mail::fake();
        $item = $this->workOrder('2026-10-19');   // 30 days out

        $this->sweep();
        $this->sweep();   // the same morning twice, and the second says nothing
        $this->assertSame([30], RenewalReminder::where('channel', 'notification')->pluck('offset_days')->all());

        // A fortnight out, then a week.
        Carbon::setTestNow(Carbon::parse('2026-10-04 02:00:00'));
        $this->sweep();
        Carbon::setTestNow(Carbon::parse('2026-10-12 02:00:00'));
        $this->sweep();

        $this->assertSame(
            [30, 15, 7],
            RenewalReminder::where('channel', 'notification')->orderByDesc('offset_days')->pluck('offset_days')->all(),
        );
        $this->assertSame($item->id, RenewalReminder::first()->invoice_item_id);
    }

    public function test_nothing_is_said_on_a_day_that_is_not_one_of_the_three(): void
    {
        Notification::fake();
        Mail::fake();
        // Twenty days out: between the warnings, and not one of them.
        $this->workOrder('2026-10-09');

        $this->sweep();

        $this->assertSame(0, RenewalReminder::count());
        Notification::assertNothingSent();
    }

    public function test_two_work_orders_ending_apart_are_two_conversations(): void
    {
        Notification::fake();
        Mail::fake();
        $invoice = Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => 'INV-2',
            'issuing_company_id' => $this->companyId, 'client_id' => $this->clientId,
            'member_id' => $this->seller->id, 'invoice_date' => '2025-09-20',
            'subtotal' => 20000, 'total' => 20000,
        ]);
        foreach ([['Listing', '2026-10-19'], ['Banner', '2027-01-01']] as $i => [$plan, $to]) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id, 'membership' => 'Standard', 'plan_name' => $plan,
                'validity_from' => '2025-09-20', 'validity_to' => $to,
                'qty' => 1, 'unit_price' => 10000, 'amount' => 10000, 'sort' => $i,
            ]);
        }

        $this->sweep();

        // Only the one that is thirty days out.
        $this->assertSame(1, RenewalReminder::where('channel', 'notification')->count());
        $this->assertSame('2026-10-19', RenewalReminder::first()->expires_on->toDateString());
    }

    public function test_a_cancelled_document_says_nothing(): void
    {
        Notification::fake();
        Mail::fake();
        $item = $this->workOrder('2026-10-19');
        $item->invoice->update(['status' => 'cancelled']);

        $this->sweep();

        $this->assertSame(0, RenewalReminder::count());
    }

    public function test_a_company_can_switch_the_whole_thing_off(): void
    {
        Notification::fake();
        Mail::fake();
        $this->settings(['enabled' => false]);
        $this->workOrder('2026-10-19');

        $this->sweep();

        $this->assertSame(0, RenewalReminder::count());
        Notification::assertNothingSent();
    }
}
