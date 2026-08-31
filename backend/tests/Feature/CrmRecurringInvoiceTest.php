<?php

namespace Tests\Feature;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentLink;
use App\Models\Crm\RecurringInvoice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Subscriptions: a document told to happen again.
 *
 * Each cycle copies the source document into a fresh one in the same series;
 * run dates are counted from the start so a bill anchored to the 31st does
 * not drift; and a resume never bills the missed months in a lump.
 */
class CrmRecurringInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $alice;
    protected int $issuingCompanyId;
    protected string $clientUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-08-28 10:00:00');

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');
        $this->bobUser = $this->makeUser('bob@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = [
            'clients' => ['view', 'create'],
            'invoices' => ['view', 'create', 'edit'],
            'payments' => ['view', 'create'],
        ];
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bobUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');

        $this->clientUuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel', 'email' => 'pay@bhavya.test', 'mobile' => '9825012345',
        ])->assertCreated()->json('data.uuid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function document(User $who, string $kind = 'proforma', array $extra = []): string
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $this->clientUuid,
            'invoice_date' => '2026-08-01',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'items' => [[
                'membership' => 'GOLD',
                'plan_name' => 'ARTIS - I',
                'qty' => 1,
                'unit_price' => 10000,
            ]],
        ] + $extra)->assertCreated()->json('data.uuid');
    }

    private function repeat(User $who, string $sourceUuid, array $payload = []): string
    {
        return $this->actingAs($who)->postJson("/api/v1/crm/invoices/{$sourceUuid}/recurring", $payload + [
            'frequency' => 'monthly',
            'starts_on' => '2026-09-01',
        ])->assertCreated()->json('data.uuid');
    }

    // ---- Starting one -------------------------------------------------------

    public function test_a_document_can_be_told_to_repeat_once(): void
    {
        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source);

        // Once: the second ask is refused while the first schedule lives.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-10-01',
        ])->assertStatus(422);

        $this->assertSame(1, RecurringInvoice::count());
    }

    public function test_sending_automatically_needs_an_email_on_file(): void
    {
        $clientUuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'No Email Co',
        ])->assertCreated()->json('data.uuid');

        $source = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma', 'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid, 'invoice_date' => '2026-08-01',
            'items' => [['plan_name' => 'A', 'qty' => 1, 'unit_price' => 100]],
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-09-01', 'auto_email' => true,
        ])->assertStatus(422);
    }

    // ---- The runs -----------------------------------------------------------

    public function test_a_run_copies_the_document_into_the_same_series(): void
    {
        $source = $this->document($this->adminUser, 'proforma', ['due_date' => '2026-08-15']);
        $this->repeat($this->adminUser, $source);

        Carbon::setTestNow('2026-09-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        $copy = Invoice::where('kind', 'proforma')->where('number', 'PI-2')->firstOrFail();
        $this->assertSame('2026-09-01', $copy->invoice_date->toDateString());
        // The 14-day gap between raised and due is part of the deal.
        $this->assertSame('2026-09-15', $copy->due_date->toDateString());
        $this->assertSame('due', $copy->payment_status);
        $this->assertEquals(11800, (float) $copy->total);
        $this->assertSame('GOLD', $copy->items()->firstOrFail()->membership);
        $this->assertEquals(900, (float) $copy->taxes()->where('key', 'cgst')->firstOrFail()->amount);
        $this->assertSame(Invoice::where('uuid', '!=', $copy->uuid)->firstOrFail()->taxes()->count(), $copy->taxes()->count());

        $schedule = RecurringInvoice::firstOrFail();
        $this->assertSame(1, $schedule->occurrences);
        $this->assertSame('2026-10-01', $schedule->next_run_on->toDateString());
        $this->assertSame('PI-2', $schedule->lastInvoice->number);

        // Run again the same morning: nothing new is owed.
        $this->artisan('crm:generate-recurring')->assertSuccessful();
        $this->assertSame(2, Invoice::count());
    }

    public function test_a_bill_anchored_to_the_31st_does_not_drift(): void
    {
        $source = $this->document($this->adminUser);
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-08-31',
        ])->assertCreated();

        $schedule = RecurringInvoice::firstOrFail();

        // August 31 → September 30 (no 31st) → October 31 again.
        $this->assertSame('2026-08-31', $schedule->runDate(0)->toDateString());
        $this->assertSame('2026-09-30', $schedule->runDate(1)->toDateString());
        $this->assertSame('2026-10-31', $schedule->runDate(2)->toDateString());
    }

    public function test_missed_cycles_are_each_raised_on_catch_up(): void
    {
        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source);   // monthly from Sep 1

        // The server slept through September and October.
        Carbon::setTestNow('2026-11-02 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        // Sep, Oct and Nov are each owed their document.
        $this->assertSame(3, Invoice::where('id', '!=', Invoice::where('uuid', $source)->value('id'))->count());
        $schedule = RecurringInvoice::firstOrFail();
        $this->assertSame(3, $schedule->occurrences);
        $this->assertSame('2026-12-01', $schedule->next_run_on->toDateString());
    }

    public function test_a_schedule_completes_when_its_runs_are_spent(): void
    {
        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source, ['max_occurrences' => 2]);

        Carbon::setTestNow('2026-10-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        $schedule = RecurringInvoice::firstOrFail();
        $this->assertSame(2, $schedule->occurrences);
        $this->assertSame('completed', $schedule->status);

        // Later mornings raise nothing more.
        Carbon::setTestNow('2026-11-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();
        $this->assertSame(2, RecurringInvoice::firstOrFail()->occurrences);
    }

    // ---- Pause, resume, cancel ----------------------------------------------

    public function test_a_paused_schedule_bills_nobody_and_resume_skips_the_gap(): void
    {
        $source = $this->document($this->adminUser);
        $uuid = $this->repeat($this->adminUser, $source);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/recurring/{$uuid}/decide", ['action' => 'pause'])
            ->assertOk()->assertJsonPath('data.status', 'paused');

        Carbon::setTestNow('2026-11-15 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();
        $this->assertSame(1, Invoice::count());   // only the source

        // Resume: the missed Sep/Oct/Nov cycles are NOT billed in a lump —
        // the schedule picks up from the next date still ahead.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/recurring/{$uuid}/decide", ['action' => 'resume'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.next_run_on', '2026-12-01');

        $this->artisan('crm:generate-recurring')->assertSuccessful();
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_cancelled_schedule_is_finished(): void
    {
        $source = $this->document($this->adminUser);
        $uuid = $this->repeat($this->adminUser, $source);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/recurring/{$uuid}/decide", ['action' => 'cancel'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/recurring/{$uuid}/decide", ['action' => 'resume'])
            ->assertStatus(422);
    }

    public function test_run_now_raises_one_without_waiting_for_the_morning(): void
    {
        $source = $this->document($this->adminUser);
        // A past start is caught up at creation now, so PI-2 already exists
        // by the time the button is pressed…
        $uuid = $this->repeat($this->adminUser, $source, ['starts_on' => '2026-08-28']);
        $this->assertSame('PI-2', Invoice::orderByDesc('id')->first()->number);

        // …and Run now pulls the NEXT cycle forward instead of waiting.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/recurring/{$uuid}/run")
            ->assertCreated()->assertJsonPath('data.number', 'PI-3');

        $this->assertSame('2026-10-28', RecurringInvoice::firstOrFail()->next_run_on->toDateString());
    }

    // ---- What a run also sends ----------------------------------------------

    public function test_a_run_can_email_the_document_with_a_payment_link(): void
    {
        Mail::fake();
        Http::fake(['*/pg/links' => Http::response([
            'cf_link_id' => 'CF-1', 'link_url' => 'https://payments-test.cashfree.com/links/sub1',
        ], 200)]);
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-gateway', [
            'mode' => 'sandbox', 'app_id' => 'TEST_APP_ID', 'secret' => 'TEST_SECRET', 'is_active' => true,
        ])->assertOk();

        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source, ['auto_email' => true, 'auto_payment_link' => true]);

        Carbon::setTestNow('2026-09-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        // The link exists against the fresh document, for its full amount.
        $link = PaymentLink::firstOrFail();
        $this->assertEquals(11800, (float) $link->amount);
        $this->assertSame('PI-2', $link->invoice->number);
        $this->assertNull(RecurringInvoice::firstOrFail()->last_error);
    }

    public function test_a_failing_extra_never_stops_the_billing(): void
    {
        Mail::fake();
        // The gateway is down this morning.
        Http::fake(['*/pg/links' => Http::response(['message' => 'gateway timeout'], 500)]);
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/payment-gateway', [
            'mode' => 'sandbox', 'app_id' => 'TEST_APP_ID', 'secret' => 'TEST_SECRET', 'is_active' => true,
        ])->assertOk();

        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source, ['auto_payment_link' => true]);

        Carbon::setTestNow('2026-09-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        // The document was still raised; the failure is on the schedule.
        $this->assertSame(2, Invoice::count());
        $this->assertStringContainsString('Payment link', RecurringInvoice::firstOrFail()->last_error);
    }

    // ---- The ledger window --------------------------------------------------

    public function test_a_schedule_follows_its_documents_window(): void
    {
        $mine = $this->document($this->aliceUser);
        $this->repeat($this->aliceUser, $mine);

        $theirs = $this->document($this->adminUser);
        $adminSchedule = $this->repeat($this->adminUser, $theirs);

        // Bob sees neither; Alice sees hers; the admin sees both.
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/recurring')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/recurring')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/recurring')
            ->assertOk()->assertJsonCount(2, 'data');

        // And Alice cannot manage the admin's schedule.
        $this->actingAs($this->aliceUser)->postJson("/api/v1/crm/recurring/{$adminSchedule}/decide", ['action' => 'pause'])
            ->assertNotFound();
    }

    // ---- Contracts: this document + the rest --------------------------------

    public function test_a_contract_counts_this_document_as_cycle_one(): void
    {
        $source = $this->document($this->adminUser);
        // "12 in all": this one, plus eleven more.
        $this->repeat($this->adminUser, $source, ['max_occurrences' => 11, 'counts_source' => true]);

        Carbon::setTestNow('2026-09-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();
        Carbon::setTestNow('2026-10-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        // The copies count from 2 — the original is already 1 of 12.
        $this->assertSame('Recurring · 2 of 12',
            Invoice::where('number', 'PI-2')->firstOrFail()->recurring_note);
        $this->assertSame('Recurring · 3 of 12',
            Invoice::where('number', 'PI-3')->firstOrFail()->recurring_note);
        // The original itself carries no note.
        $this->assertNull(Invoice::where('uuid', $source)->firstOrFail()->recurring_note);

        // Eleven runs complete the twelve-month contract.
        $schedule = RecurringInvoice::firstOrFail();
        $this->assertSame('2 of 11 runs used', $schedule->occurrences . ' of ' . $schedule->max_occurrences . ' runs used');
    }

    public function test_a_contract_needs_its_total(): void
    {
        $source = $this->document($this->adminUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-09-01', 'counts_source' => true,
        ])->assertStatus(422);
    }

    public function test_the_note_can_be_left_off_the_document(): void
    {
        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source, [
            'max_occurrences' => 11, 'counts_source' => true, 'show_on_document' => false,
        ]);

        Carbon::setTestNow('2026-09-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        $this->assertNull(Invoice::where('number', 'PI-2')->firstOrFail()->recurring_note);
    }

    public function test_an_open_ended_schedule_notes_without_a_total(): void
    {
        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source);

        Carbon::setTestNow('2026-09-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        $copy = Invoice::where('number', 'PI-2')->firstOrFail();
        $this->assertSame('Recurring · 1', $copy->recurring_note);

        // And the note rides the API into the document view.
        $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$copy->uuid}")
            ->assertOk()->assertJsonPath('data.recurring_note', 'Recurring · 1');
    }

    public function test_the_office_sees_recurring_even_when_the_paper_is_silent(): void
    {
        $source = $this->document($this->adminUser);
        $this->repeat($this->adminUser, $source, [
            'max_occurrences' => 11, 'counts_source' => true, 'show_on_document' => false,
        ]);

        Carbon::setTestNow('2026-09-01 07:30:00');
        $this->artisan('crm:generate-recurring')->assertSuccessful();

        $rows = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices?kind=proforma')
            ->assertOk()->json('data'))->keyBy('number');

        // The copy is flagged for the office even with no note on the paper…
        $this->assertTrue($rows['PI-2']['is_recurring']);
        $this->assertNull($rows['PI-2']['recurring_note']);
        // …and the hand-raised original is not.
        $this->assertFalse($rows['PI-1']['is_recurring']);
    }

    public function test_a_one_time_copy_never_wears_the_recurring_badge(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-30 11:00'));
        $source = $this->document($this->adminUser, 'invoice');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'once', 'starts_on' => '2026-08-15', 'show_on_document' => false,
        ])->assertCreated();

        $copy = Invoice::orderByDesc('id')->first();
        $row = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices/' . $copy->uuid)
            ->assertOk()->json('data');

        // One extra document, raised once: no "Recurring" anywhere.
        $this->assertFalse($row['is_recurring']);
        $this->assertNull($row['recurring_note']);

        // A copy from a STANDING cycle still wears it, as before.
        $monthly = $this->document($this->adminUser, 'invoice');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$monthly}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-08-01',
        ])->assertCreated();
        $cycleCopy = Invoice::orderByDesc('id')->first();
        $this->assertTrue($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices/' . $cycleCopy->uuid)->json('data')['is_recurring']);

        \Carbon\Carbon::setTestNow();
    }

    public function test_the_same_one_time_copy_cannot_be_scheduled_twice_for_one_date(): void
    {
        $source = $this->document($this->adminUser, 'invoice');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'once', 'starts_on' => '2026-10-15',
        ])->assertCreated();

        // The double-click: same document, same date — refused.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'once', 'starts_on' => '2026-10-15',
        ])->assertStatus(422);

        // A different date is a different intent, and fine.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'once', 'starts_on' => '2026-11-15',
        ])->assertCreated();
    }

    public function test_a_past_or_today_date_raises_the_copy_immediately(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-30 11:00'));
        $source = $this->document($this->adminUser, 'invoice');

        // "Raise it on the 15th", said on the 30th: the copy exists before
        // the toast fades — no waiting for tomorrow's timer.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'once', 'starts_on' => '2026-08-15',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(2, \App\Models\Crm\Invoice::count());
        $copy = \App\Models\Crm\Invoice::orderByDesc('id')->first();
        // A fresh number from the company's own series, dated as asked.
        $this->assertNotSame(
            \App\Models\Crm\Invoice::orderBy('id')->first()->number,
            $copy->number,
        );
        $this->assertSame('2026-08-15', $copy->invoice_date->toDateString());

        // A standing cycle with overdue runs catches up on the spot too.
        $second = $this->document($this->adminUser, 'invoice');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$second}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-07-01',
        ])->assertCreated();
        // July and August were owed; September is next.
        $this->assertSame(5, \App\Models\Crm\Invoice::count());

        \Carbon\Carbon::setTestNow();
    }

    public function test_a_one_time_copy_is_allowed_beside_a_standing_cycle(): void
    {
        $source = $this->document($this->adminUser, 'invoice');
        $this->repeat($this->adminUser, $source);   // the standing monthly cycle

        // A second standing cycle is still refused — that would double-bill.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-10-01',
        ])->assertStatus(422);

        // But one extra copy on a chosen date is a different intent, and fine.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$source}/recurring", [
            'frequency' => 'once', 'starts_on' => '2026-09-15',
        ])->assertCreated();

        // And a pending one-off never blocks starting a standing cycle either.
        $fresh = $this->document($this->adminUser, 'invoice');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$fresh}/recurring", [
            'frequency' => 'once', 'starts_on' => '2026-09-15',
        ])->assertCreated();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$fresh}/recurring", [
            'frequency' => 'monthly', 'starts_on' => '2026-10-01',
        ])->assertCreated();
    }

    public function test_a_one_time_repeat_raises_exactly_one_copy_and_completes(): void
    {
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-01 08:00'));
        $uuid = $this->document($this->adminUser, 'invoice');

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$uuid}/recurring", [
            'frequency' => 'once',
            'starts_on' => '2026-08-05',
        ])->assertCreated();

        // Before the date: nothing.
        $this->artisan('crm:generate-recurring')->assertSuccessful();
        $this->assertSame(1, \App\Models\Crm\Invoice::count());

        // On the date: one copy, and the schedule is spent.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-08-05 08:00'));
        $this->artisan('crm:generate-recurring')->assertSuccessful();
        $this->assertSame(2, \App\Models\Crm\Invoice::count());
        $this->assertSame('completed', \App\Models\Crm\RecurringInvoice::firstOrFail()->status);

        // Days later: still exactly one copy — one time means one.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-09-20 08:00'));
        $this->artisan('crm:generate-recurring')->assertSuccessful();
        $this->assertSame(2, \App\Models\Crm\Invoice::count());

        \Carbon\Carbon::setTestNow();
    }
}
