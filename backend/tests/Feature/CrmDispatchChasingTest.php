<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A dispatch nobody chases is a client waiting.
 *
 * Goods that have not gone out are the office's to chase, so the desk is
 * asked about them - after the days the company allows, and again on the
 * rhythm it set, until the document is marked dispatched or somebody defers
 * it to a date of their own.
 */
class CrmDispatchChasingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private User $subUser;
    private User $staffUser;
    private string $clientUuid;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminUser = $this->member('boss@acme.test', 'admin');
        $this->subUser = $this->member('sub@acme.test', 'subadmin', ['invoices' => ['view', 'edit']]);
        $this->staffUser = $this->member('seller@acme.test', 'employee', ['invoices' => ['view', 'create', 'edit']]);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing'])->id;
        $this->clientUuid = $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel', 'contact_person' => 'Mihir Shah',
        ])->assertCreated()->json('data.uuid');
    }

    private function member(string $email, string $role, ?array $rights = null): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id,
            'crm_role' => $role, 'status' => 'active', 'rights' => $rights,
        ]);

        return $user;
    }

    private function as(?User $who = null)
    {
        return $this->actingAs($who ?? $this->adminUser);
    }

    private function raise(array $extra = []): array
    {
        return $this->as()->postJson('/api/v1/crm/invoices', $extra + [
            'kind' => 'invoice',
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->clientUuid,
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
        ])->assertCreated()->json('data');
    }

    private function due(?User $who = null): array
    {
        return $this->as($who)->getJson('/api/v1/crm/invoices-dispatch-due')->assertOk()->json('data');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_dispatch_asks_about_itself_once_the_company_has_waited_long_enough(): void
    {
        $invoice = $this->raise();

        // Nothing to say on the day it was raised: the office has two days.
        $this->assertSame([], $this->due());

        Carbon::setTestNow(now()->addDays(3));
        $asking = $this->due();
        $this->assertCount(1, $asking);
        $this->assertSame($invoice['number'], $asking[0]['number']);
        $this->assertSame('Bhavya Steel', $asking[0]['client']);
        $this->assertSame('Mihir Shah', $asking[0]['contact_person']);
        $this->assertSame(3, $asking[0]['waiting_days']);
    }

    public function test_it_goes_quiet_the_moment_it_goes_out(): void
    {
        $invoice = $this->raise();
        Carbon::setTestNow(now()->addDays(3));
        $this->assertCount(1, $this->due());

        $this->as()->putJson('/api/v1/crm/invoices/' . $invoice['uuid'], [
            'dispatch_status' => 'dispatched',
            'invoice_date' => $invoice['invoice_date'],
            'due_date' => $invoice['due_date'],
            'client_segment' => 'Regular',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'items' => [[
                'membership' => 'Standard', 'plan_name' => 'Annual listing',
                'validity_from' => now()->toDateString(), 'validity_to' => now()->addYear()->toDateString(),
                'qty' => 1, 'unit_price' => 1000,
            ]],
        ])->assertOk();

        $this->assertSame([], $this->due());
        $this->assertNull(Invoice::where('uuid', $invoice['uuid'])->value('dispatch_remind_at'));
    }

    public function test_it_can_be_deferred_to_a_day_of_ones_own(): void
    {
        $invoice = $this->raise();
        Carbon::setTestNow(now()->addDays(3));

        $this->as($this->subUser)->postJson('/api/v1/crm/invoices/' . $invoice['uuid'] . '/dispatch-reminder', [
            'until' => now()->addDays(5)->toDateString(),
            'note' => 'Stock lands on Friday.',
        ])->assertOk();

        $this->assertSame([], $this->due());

        Carbon::setTestNow(now()->addDays(6));
        $this->assertCount(1, $this->due());
    }

    public function test_deferring_with_no_date_uses_the_companys_own_rhythm(): void
    {
        $invoice = $this->raise();
        Carbon::setTestNow(now()->addDays(3));

        $this->as()->postJson('/api/v1/crm/invoices/' . $invoice['uuid'] . '/dispatch-reminder')->assertOk();
        $this->assertSame([], $this->due());

        // Two days is the default rhythm.
        Carbon::setTestNow(now()->addDays(2)->addMinute());
        $this->assertCount(1, $this->due());
    }

    public function test_a_date_already_gone_is_refused(): void
    {
        $invoice = $this->raise();
        $this->as()->postJson('/api/v1/crm/invoices/' . $invoice['uuid'] . '/dispatch-reminder', [
            'until' => now()->subDay()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_the_chasing_belongs_to_the_office_not_the_salesperson(): void
    {
        $this->raise();
        Carbon::setTestNow(now()->addDays(3));

        $this->assertCount(1, $this->due($this->subUser));
        // An employee is not asked, even though they may read the document.
        $this->assertSame([], $this->due($this->staffUser));
    }

    public function test_a_company_may_switch_the_chasing_off_or_change_its_rhythm(): void
    {
        $this->raise();

        $this->as()->putJson('/api/v1/crm/masters/payment-settings', [
            'settlement_mode' => 'manual',
            'reminders' => ['enabled' => false, 'offsets' => [], 'stop_after' => 4],
            'dispatch' => ['enabled' => false, 'after_days' => 5, 'repeat_days' => 7],
        ])->assertOk();

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame([], $this->due());

        $this->assertSame(
            ['enabled' => false, 'after_days' => 5, 'repeat_days' => 7],
            $this->as()->getJson('/api/v1/crm/masters/payment-settings')->assertOk()->json('data.dispatch'),
        );
    }
}
