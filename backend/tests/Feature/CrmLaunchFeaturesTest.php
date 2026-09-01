<?php

namespace Tests\Feature;

use App\Models\Crm\Asset;
use App\Models\Crm\Complaint;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\Punch;
use App\Models\Crm\SalarySlip;
use App\Services\Crm\AttendanceCalendar;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The launch batch: salary privacy, duplicate-lead lock, urgency, the late
 * waiver and late policy, per-day timings, the accounting export gate, the
 * P&L, the asset register, the complaints popup feed, FX conversion and
 * the salary-paying company tick.
 */
class CrmLaunchFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $subUser;
    protected User $empUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $sub;
    protected Member $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        foreach ([['adminUser', 'boss@acme.test'], ['subUser', 'sub@acme.test'], ['empUser', 'emp@acme.test']] as [$prop, $email]) {
            $u = User::factory()->create(['email' => $email]);
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'UTC']);
            $this->{$prop} = $u;
        }

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create(['organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin']);
        $this->sub = Member::create(['organization_id' => $this->org->id, 'user_id' => $this->subUser->id, 'crm_role' => 'subadmin', 'reporting_to' => $this->admin->id,
            'rights' => ['invoices' => ['view'], 'payments' => ['view']]]);
        $this->emp = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->empUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'status' => 'active', 'joined_at' => '2024-01-01',
            'rights' => ['leads' => ['view', 'create'], 'salary' => ['view'], 'complaints' => ['view']],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- #18 Salary stays individual ----------------------------------

    public function test_salary_is_an_individual_matter_even_with_the_salary_right(): void
    {
        SalarySlip::create(['organization_id' => $this->org->id, 'member_id' => $this->admin->id,
            'year' => 2026, 'month' => 8, 'monthly_salary' => 50000, 'payable' => 50000, 'net_salary' => 50000]);
        SalarySlip::create(['organization_id' => $this->org->id, 'member_id' => $this->emp->id,
            'year' => 2026, 'month' => 8, 'monthly_salary' => 20000, 'payable' => 20000, 'net_salary' => 20000]);

        // The employee HOLDS salary,view — and still sees only their own.
        $rows = $this->actingAs($this->empUser)
            ->getJson('/api/v1/crm/salary?year=2026&month=8')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(20000.0, (float) $rows[0]['net_salary']);

        // Another person's incentive ledger and compensation are sealed too.
        $this->actingAs($this->empUser)
            ->getJson('/api/v1/crm/incentives?member=' . $this->admin->uuid)->assertForbidden();
    }

    // ---- #9 / #11 Duplicate lock + urgency ------------------------------

    public function test_a_duplicate_lead_stays_sealed_to_employees_until_settled(): void
    {
        $original = Lead::create(['organization_id' => $this->org->id, 'lead_no' => 1,
            'company_name' => 'Original Co', 'mobile' => '9876543210',
            'assigned_member_id' => $this->emp->id, 'created_by' => $this->adminUser->id]);
        $dup = Lead::create(['organization_id' => $this->org->id, 'lead_no' => 2,
            'company_name' => 'Copy Co', 'mobile' => '9876543210',
            'assigned_member_id' => $this->emp->id, 'created_by' => $this->adminUser->id]);

        // Sealed for the employee; open for the Admin.
        $this->actingAs($this->empUser)->getJson('/api/v1/crm/leads/' . $dup->uuid)->assertForbidden();
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads/' . $dup->uuid)->assertOk();
        // The original was never locked.
        $this->actingAs($this->empUser)->getJson('/api/v1/crm/leads/' . $original->uuid)->assertOk();

        // Employees cannot settle; the Admin's gavel opens it.
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/leads/' . $dup->uuid . '/settle-duplicate')->assertForbidden();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads/' . $dup->uuid . '/settle-duplicate')->assertOk();
        $this->actingAs($this->empUser)->getJson('/api/v1/crm/leads/' . $dup->uuid)->assertOk();

        // Settled rows drop the badge on the list.
        $row = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads')->json('data'))
            ->firstWhere('lead_no', 2);
        $this->assertFalse($row['is_duplicate']);
    }

    public function test_an_urgent_lead_rides_above_every_scheduled_one(): void
    {
        Lead::create(['organization_id' => $this->org->id, 'lead_no' => 1, 'company_name' => 'Calm Co',
            'assigned_member_id' => $this->emp->id, 'created_by' => $this->adminUser->id]);
        $urgent = Lead::create(['organization_id' => $this->org->id, 'lead_no' => 2, 'company_name' => 'Fire Co',
            'assigned_member_id' => $this->emp->id, 'created_by' => $this->adminUser->id]);
        Lead::create(['organization_id' => $this->org->id, 'lead_no' => 3, 'company_name' => 'Newest Co',
            'assigned_member_id' => $this->emp->id, 'created_by' => $this->adminUser->id]);

        $this->actingAs($this->empUser)
            ->postJson('/api/v1/crm/leads/' . $urgent->uuid . '/urgent', ['urgent' => true])->assertOk();

        $rows = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads')->json('data');
        $this->assertSame(2, $rows[0]['lead_no']);            // urgent first, above #3
        $this->assertTrue($rows[0]['is_urgent']);
        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'lead.marked_urgent']);
    }

    // ---- #12 / #13 / #14 Timings, waiver, late policy -------------------

    private function punch(Member $m, string $date, string $in, ?string $out = null): void
    {
        Punch::create([
            'organization_id' => $this->org->id, 'member_id' => $m->id, 'work_date' => $date,
            'punch_in' => $date . ' ' . $in, 'punch_out' => $out ? $date . ' ' . $out : null,
            'status' => 'present', 'status_source' => 'auto',
        ]);
    }

    public function test_each_weekday_is_measured_from_its_own_office_hours(): void
    {
        // Policy defaults: Mon–Fri 10:00–18:30, Sat 10:00–18:00, grace 15.
        // 10:20 is late on any working day; Saturday proves the per-day row
        // is read (its start is Saturday's own 10:00).
        $cal = new AttendanceCalendar($this->org);
        $this->punch($this->emp, '2026-08-29', '10:20:00', '18:00:00');   // Saturday
        $this->punch($this->emp, '2026-08-28', '10:05:00', '18:30:00');   // Friday, within grace

        $rows = $cal->build(collect([$this->emp]),
            Carbon::parse('2026-08-28'), Carbon::parse('2026-08-29'))->keyBy('work_date');
        $this->assertSame('present', $rows['2026-08-28']['status']);
        $this->assertSame('late', $rows['2026-08-29']['status']);
    }

    public function test_the_admin_waiver_marks_a_late_arrival_present(): void
    {
        $this->emp->update(['late_waived' => true]);
        $this->punch($this->emp, '2026-08-28', '10:45:00', '19:00:00');   // 45 min past start

        $cal = new AttendanceCalendar($this->org);
        $row = $cal->build(collect([$this->emp]), Carbon::parse('2026-08-28'), Carbon::parse('2026-08-28'))->first();
        $this->assertSame('present', $row['status']);

        // Only the Admin moves the switch: a Subadmin's payload drops it.
        $this->emp->update(['late_waived' => false]);
        $this->actingAs($this->subUser)->putJson('/api/v1/crm/employees/' . $this->emp->uuid, [
            'crm_role' => 'employee', 'late_waived' => true,
        ])->assertOk();
        $this->assertFalse($this->emp->fresh()->late_waived);

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/employees/' . $this->emp->uuid, [
            'crm_role' => 'employee', 'late_waived' => true,
        ])->assertOk();
        $this->assertTrue($this->emp->fresh()->late_waived);
    }

    public function test_four_lates_cost_half_a_day_in_the_month_summary(): void
    {
        // Four lates (10:20, grace 15) across the working week.
        foreach (['2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'] as $d) {
            $this->punch($this->emp, $d, '10:20:00', '18:35:00');
        }
        $this->punch($this->emp, '2026-08-28', '10:00:00', '18:35:00');   // one clean day

        $cal = new AttendanceCalendar($this->org);
        $summary = $cal->summarise($cal->build(collect([$this->emp]),
            Carbon::parse('2026-08-24'), Carbon::parse('2026-08-28')))->first();

        $this->assertSame(4, $summary['late']);
        $this->assertEquals(0.5, $summary['late_penalty_days']);
        // 5 counted days at full value, less the late penalty.
        $this->assertEquals(4.5, $summary['payable_days']);
    }

    // ---- #8 The accounting export gate ---------------------------------

    public function test_the_excel_export_is_the_admins_plus_the_named_subadmin(): void
    {
        $this->actingAs($this->adminUser)->get('/api/v1/crm/exports/invoices')->assertOk();
        $this->actingAs($this->subUser)->get('/api/v1/crm/exports/invoices')->assertForbidden();
        $this->actingAs($this->empUser)->get('/api/v1/crm/exports/invoices')->assertForbidden();

        // The Admin names the Subadmin — the grant, not the job.
        $this->sub->update(['capabilities' => ['exports.excel']]);
        $this->actingAs($this->subUser)->get('/api/v1/crm/exports/payments')->assertOk();
        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'export.invoices']);
    }

    // ---- #1 The P&L ------------------------------------------------------

    public function test_the_pl_is_the_admins_alone_and_reads_the_ledgers(): void
    {
        $companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing'])->id;
        $client = \App\Models\Crm\Client::create(['organization_id' => $this->org->id, 'company_name' => 'C1', 'created_by' => $this->adminUser->id]);
        Invoice::create(['organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => 'INV-1',
            'issuing_company_id' => $companyId, 'client_id' => $client->id, 'member_id' => $this->emp->id,
            'invoice_date' => '2026-08-15', 'subtotal' => 100000, 'total' => 118000]);
        \App\Models\Crm\Expense::create(['organization_id' => $this->org->id, 'expense_date' => '2026-08-20',
            'vendor_name' => 'Landlord', 'category' => 'Rent', 'base_amount' => 30000, 'total_amount' => 30000, 'created_by' => $this->adminUser->id]);
        SalarySlip::create(['organization_id' => $this->org->id, 'member_id' => $this->emp->id,
            'year' => 2026, 'month' => 8, 'monthly_salary' => 20000, 'payable' => 20000, 'net_salary' => 20000]);

        // Subadmin and employee are refused outright.
        $this->actingAs($this->subUser)->getJson('/api/v1/crm/pl?month_from=2026-08&month_to=2026-08')->assertForbidden();
        $this->actingAs($this->empUser)->getJson('/api/v1/crm/pl?month_from=2026-08&month_to=2026-08')->assertForbidden();

        // A manual line on each side.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/pl/lines', [
            'month' => '2026-08', 'side' => 'expense', 'label' => 'Credit card bill', 'amount' => 5000,
        ])->assertCreated();

        $month = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/pl?month_from=2026-08&month_to=2026-08')
            ->assertOk()->json('data.months.0');

        $this->assertEquals(118000, $month['income_total']);          // gross, taxes included
        $this->assertEquals(30000 + 20000 + 5000, $month['expense_total']);
        $this->assertEquals(118000 - 55000, $month['profit']);
        $labels = collect($month['expenses'])->pluck('label');
        $this->assertTrue($labels->contains('Rent'));
        $this->assertTrue($labels->contains('Salaries (net payroll)'));
        $this->assertTrue($labels->contains('Credit card bill'));
    }

    // ---- #2 The asset register ------------------------------------------

    public function test_assets_live_for_life_allocated_returned_damaged_removed(): void
    {
        // Bulk entry: three chargers land in stock.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/assets', [
            'category' => 'Phone Charger', 'name' => 'Samsung 25W', 'quantity' => 3,
        ])->assertCreated();
        $this->assertSame(3, Asset::where('status', 'in_stock')->count());

        $asset = Asset::first();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/assets/' . $asset->uuid . '/allocate', [
            'member_uuid' => $this->emp->uuid, 'note' => 'With cable',
        ])->assertOk();
        $this->assertSame('allocated', $asset->fresh()->status);

        // The employee sees their own kit — and only their own.
        $mine = $this->actingAs($this->empUser)->getJson('/api/v1/crm/assets')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame($asset->uuid, $mine[0]['uuid']);

        // Returned damaged: aside until repaired or removed.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/assets/' . $asset->uuid . '/return', [
            'damaged' => true, 'note' => 'Cable frayed',
        ])->assertOk();
        $this->assertSame('damaged', $asset->fresh()->status);

        // A subadmin without the delete right cannot remove; the Admin can.
        $this->actingAs($this->subUser)->deleteJson('/api/v1/crm/assets/' . $asset->uuid)->assertForbidden();
        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/assets/' . $asset->uuid)->assertOk();
        $this->assertNull(Asset::find($asset->id));
        $this->assertDatabaseHas('crm_activity_logs', ['action' => 'asset.removed']);

        // History carried the whole life while it lived.
        $second = Asset::orderBy('id')->first();
        $history = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/assets/' . $second->uuid . '/history')->assertOk()->json('data');
        $this->assertSame('created', $history[0]['action']);

        // Employees never write.
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/assets', [
            'category' => 'Mouse', 'name' => 'Sneaky',
        ])->assertForbidden();
    }

    // ---- #15 The complaints popup feed ----------------------------------

    public function test_open_complaints_ride_the_popup_feed_of_whoever_must_answer(): void
    {
        Complaint::create([
            'organization_id' => $this->org->id, 'cms_no' => 'CMS-1', 'complained_on' => '2026-08-30',
            'company_name' => 'Angry Co', 'subject' => 'Portal down', 'status' => 'in_progress',
            'priority' => 'urgent', 'allocated_to_member_id' => $this->emp->id,
            'raised_by_member_id' => $this->admin->id, 'created_by' => $this->adminUser->id,
        ]);
        Complaint::create([
            'organization_id' => $this->org->id, 'cms_no' => 'CMS-2', 'complained_on' => '2026-08-30',
            'company_name' => 'Waiting Co', 'status' => 'unattended',
            'raised_by_member_id' => $this->admin->id, 'created_by' => $this->adminUser->id,
        ]);

        // The allocated person sees theirs; the unallocated one nags managers.
        $mine = $this->actingAs($this->empUser)->getJson('/api/v1/crm/complaints-due')->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame('CMS-1', $mine[0]['cms_no']);

        // The manager is nagged about the UNALLOCATED one — the allocated
        // complaint is its holder's to answer, not the room's.
        $admins = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints-due')->assertOk()->json('data');
        $this->assertCount(1, $admins);
        $this->assertSame('CMS-2', $admins[0]['cms_no']);
    }

    // ---- #4 / #5 The issuing company grows up ---------------------------

    public function test_one_company_pays_the_salaries_and_a_foreign_one_converts(): void
    {
        $a = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme India']);
        $b = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Exports']);

        // Ticking B unticks A — one salary company at a time.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/issuing-companies/' . $a->id, [
            'name' => 'Acme India', 'pays_salary' => true,
        ])->assertOk();
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/issuing-companies/' . $b->id, [
            'name' => 'Acme Exports', 'pays_salary' => true, 'currency' => 'usd',
        ])->assertOk();
        $this->assertFalse($a->fresh()->pays_salary);
        $this->assertTrue($b->fresh()->pays_salary);
        $this->assertSame('USD', $b->fresh()->currency);

        // A USD company's invoice converts: market 96, margin 2 → 94.
        Http::fake(['open.er-api.com/*' => Http::response(['rates' => ['INR' => 96.0]])]);
        $client = \App\Models\Crm\Client::create(['organization_id' => $this->org->id, 'company_name' => 'US Client', 'created_by' => $this->adminUser->id]);
        $created = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice', 'issuing_company_id' => $b->id, 'client_uuid' => $client->uuid,
            'invoice_date' => '2026-08-30',
            'items' => [['plan_name' => 'Plan', 'qty' => 1, 'unit_price' => 1000]],
        ])->assertCreated();

        $invoice = Invoice::where('number', $created->json('data.number'))->firstOrFail();
        $this->assertSame('USD', $invoice->currency);
        $this->assertSame('INR', $invoice->fx_currency);
        $this->assertEquals(94.0, (float) $invoice->fx_rate);
        $this->assertEquals(round((float) $invoice->total * 94, 2), (float) $invoice->total_fx);
    }

    // ---- #16b The accountant's list filters ----------------------------

    public function test_invoices_filter_gst_wise_tds_wise_and_due_wise(): void
    {
        $co = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);
        $client = \App\Models\Crm\Client::create(['organization_id' => $this->org->id, 'company_name' => 'C1', 'created_by' => $this->adminUser->id]);
        $mk = fn (string $no, array $extra) => Invoice::create($extra + [
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => $no,
            'issuing_company_id' => $co->id, 'client_id' => $client->id, 'member_id' => $this->admin->id,
            'invoice_date' => '2026-08-10', 'subtotal' => 10000, 'total' => 11800,
        ]);

        $gstIn = $mk('INV-GST', ['cgst' => 900, 'sgst' => 900]);
        $igstIn = $mk('INV-IGST', ['igst' => 1800, 'tds' => 200]);
        $plain = $mk('INV-PLAIN', ['total' => 10000]);
        \App\Models\Crm\InvoicePayment::create(['invoice_id' => $plain->id, 'amount' => 10000, 'received_at' => '2026-08-11']);

        $numbers = fn ($params) => collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&' . $params)->assertOk()->json('data'))
            ->pluck('number')->sort()->values()->all();

        $this->assertSame(['INV-GST', 'INV-IGST'], $numbers('gst=with'));
        $this->assertSame(['INV-PLAIN'], $numbers('gst=without'));
        $this->assertSame(['INV-IGST'], $numbers('gst=igst'));
        $this->assertSame(['INV-GST'], $numbers('gst=cgst_sgst'));
        $this->assertSame(['INV-IGST'], $numbers('tds=with'));
        $this->assertSame(['INV-GST', 'INV-PLAIN'], $numbers('tds=without'));
        // Due-wise: the paid one drops out; the band narrows further.
        $this->assertSame(['INV-GST', 'INV-IGST'], $numbers('due_only=1'));
        $this->assertSame(['INV-GST', 'INV-IGST'], $numbers('due_min=11000'));
        $this->assertSame(['INV-PLAIN'], $numbers('due_max=100'));
    }

    // ---- The correction batch ------------------------------------------

    public function test_punch_reports_are_personal_and_lead_delete_is_the_admins(): void
    {
        $this->punch($this->emp, '2026-08-28', '10:00:00', '18:30:00');
        $this->punch($this->sub, '2026-08-28', '10:00:00', '18:30:00');

        // The employee's punch window is their own days only.
        $rows = $this->actingAs($this->empUser)
            ->getJson('/api/v1/crm/punch?date_from=2026-08-28&date_to=2026-08-28')
            ->assertOk()->json('data');
        $uuids = collect($rows)->pluck('member.uuid')->unique()->values()->all();
        $this->assertSame([$this->emp->uuid], $uuids);

        // Deleting a lead is never an employee's, whatever rights they hold.
        $this->emp->update(['rights' => ['leads' => ['view', 'create', 'edit', 'delete']]]);
        $lead = Lead::create(['organization_id' => $this->org->id, 'lead_no' => 9,
            'company_name' => 'Keep Co', 'assigned_member_id' => $this->emp->id, 'created_by' => $this->empUser->id]);
        $this->actingAs($this->empUser)->deleteJson('/api/v1/crm/leads/' . $lead->uuid)->assertForbidden();
        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/leads/' . $lead->uuid)->assertOk();
    }

    public function test_targets_and_contests_are_company_authority(): void
    {
        $this->emp->update(['rights' => ['targets' => ['view', 'edit'], 'contests' => ['create', 'edit', 'delete']]]);

        // Even with the granted rights, setting targets is refused.
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/targets', [
            'year' => 2026, 'month' => 8,
            'targets' => [['member_uuid' => $this->emp->uuid, 'target_amount' => 100]],
        ])->assertForbidden();
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/targets/copy-previous', [
            'year' => 2026, 'month' => 8,
        ])->assertForbidden();

        // And so is creating a contest.
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/contests', [
            'title' => 'Sneaky quiz', 'starts_at' => '2026-09-01 10:00', 'ends_at' => '2026-09-02 10:00',
        ])->assertForbidden();
    }

    public function test_an_aimed_contest_reaches_only_its_audience_and_replicates(): void
    {
        $this->emp->update(['department' => 'Sales']);

        // Aimed at Marketing: the Sales employee never sees it.
        $made = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/contests', [
            'title' => 'Marketing quiz', 'starts_at' => now()->subHour()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(), 'status' => 'published',
            'audience_department' => 'Marketing',
            'questions' => [['type' => 'text', 'question' => 'Q?', 'points' => 10]],
        ])->assertCreated();
        $uuid = $made->json('data.uuid');

        $list = $this->actingAs($this->empUser)->getJson('/api/v1/crm/contests')->assertOk()->json('data');
        $this->assertCount(0, $list);
        $this->actingAs($this->empUser)->getJson('/api/v1/crm/contests/' . $uuid)->assertNotFound();

        // Aimed at THEM by name: it appears.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/contests/' . $uuid, [
            'title' => 'Marketing quiz', 'starts_at' => now()->subHour()->toDateTimeString(),
            'ends_at' => now()->addDay()->toDateTimeString(), 'status' => 'published',
            'audience_member_uuid' => $this->emp->uuid,
        ])->assertOk();
        $this->assertCount(1, $this->actingAs($this->empUser)->getJson('/api/v1/crm/contests')->json('data'));

        // Replication: a fresh draft with the same questions, no answers.
        $copy = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/contests/' . $uuid . '/replicate')->assertCreated();
        $fresh = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/contests/' . $copy->json('data.uuid'))->assertOk()->json('data');
        $this->assertSame('draft', $fresh['status']);
    }

    public function test_every_payment_receipt_carries_its_unique_payment_id(): void
    {
        $co = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);
        $client = \App\Models\Crm\Client::create(['organization_id' => $this->org->id, 'company_name' => 'C1', 'created_by' => $this->adminUser->id]);
        $inv = Invoice::create(['organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => 'INV-P1',
            'issuing_company_id' => $co->id, 'client_id' => $client->id, 'member_id' => $this->admin->id,
            'invoice_date' => '2026-08-10', 'subtotal' => 1000, 'total' => 1000]);

        $p = \App\Models\Crm\InvoicePayment::create([
            'invoice_id' => $inv->id, 'amount' => 1000, 'received_at' => '2026-08-11',
        ]);
        $this->assertSame('PAY-' . str_pad((string) $p->id, 6, '0', STR_PAD_LEFT), $p->fresh()->payment_no);
    }

    // ---- #21 Churn -------------------------------------------------------

    public function test_churn_reads_months_from_the_invoice_ledger(): void
    {
        $this->emp->update(['rights' => ['reports' => ['view']]]);
        $data = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/churn?months=6')
            ->assertOk()->json('data');
        $this->assertCount(6, $data['months']);
        $this->assertArrayHasKey('avg_churn_rate', $data['summary']);
    }

    public function test_a_company_mailbox_carries_the_complete_setup_secrets_masked(): void
    {
        $co = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Mailer']);

        $mailbox = [
            'label' => 'Acme accounts', 'from_name' => 'Acme Accounts',
            'from_address' => 'accounts@acme.test', 'mailer' => 'smtp',
            'smtp_host' => 'smtp.acme.test', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'smtp_password' => 'send-secret',
            'imap_host' => 'mail.acme.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_password' => 'read-secret', 'imap_allow_self_signed' => true,
        ];
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/communication', [
            'from_name' => 'Acme', 'company_senders' => [(string) $co->id => $mailbox],
        ])->assertOk();

        // Reading back: the shape survives, the secrets wear the mask.
        $read = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters/communication')
            ->assertOk()->json('data.company_senders.' . $co->id);
        $this->assertSame('Acme accounts', $read['label']);
        $this->assertSame('ssl', $read['smtp_encryption']);
        $this->assertSame('mail.acme.test', $read['imap_host']);
        $this->assertTrue((bool) $read['imap_allow_self_signed']);
        $this->assertSame('********', $read['smtp_password']);
        $this->assertSame('********', $read['imap_password']);

        // Stored encrypted, and a masked re-save keeps the real secrets.
        $stored = data_get($this->org->fresh()->settings, 'communication.company_senders.' . $co->id);
        $this->assertSame('send-secret', \Illuminate\Support\Facades\Crypt::decryptString($stored['smtp_password']));
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/communication', [
            'from_name' => 'Acme', 'company_senders' => [(string) $co->id => $read],
        ])->assertOk();
        $stored = data_get($this->org->fresh()->settings, 'communication.company_senders.' . $co->id);
        $this->assertSame('send-secret', \Illuminate\Support\Facades\Crypt::decryptString($stored['smtp_password']));
        $this->assertSame('read-secret', \Illuminate\Support\Facades\Crypt::decryptString($stored['imap_password']));

        // A made-up encryption mode is refused; an employee may not touch it.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/communication', [
            'company_senders' => [(string) $co->id => ['smtp_encryption' => 'rot13']],
        ])->assertStatus(422);
        $this->actingAs($this->empUser)->putJson('/api/v1/crm/masters/communication', [
            'from_name' => 'Nope',
        ])->assertForbidden();
    }

    public function test_reports_belong_to_the_admin_plus_the_named_subadmin(): void
    {
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/reports/overview')->assertOk();
        $this->actingAs($this->subUser)->getJson('/api/v1/crm/reports/overview')->assertForbidden();
        $this->actingAs($this->empUser)->getJson('/api/v1/crm/reports/overview')->assertForbidden();

        // The Admin names the Subadmin - the door opens.
        $this->sub->update(['capabilities' => ['reports.view']]);
        $this->actingAs($this->subUser)->getJson('/api/v1/crm/reports/overview')->assertOk();

        // The custom calendar window buckets exactly the months it spans.
        $data = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/reports/overview?date_from='
            . now()->startOfMonth()->toDateString() . '&date_to=' . now()->toDateString())
            ->assertOk()->json('data');
        $this->assertCount(1, $data['monthly']);
        $this->assertSame(now()->format('Y-m'), $data['monthly'][0]['month']);
    }

    public function test_an_upcoming_contest_keeps_its_questions_sealed(): void
    {
        $c = \App\Models\Crm\Contest::create([
            'organization_id' => $this->org->id, 'title' => 'Sealed Quiz',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
            'status' => 'published', 'created_by' => $this->adminUser->id,
        ]);
        $c->questions()->create(['type' => 'option', 'question' => 'The secret question',
            'options' => ['A', 'B'], 'correct_option' => 0, 'points' => 10, 'sort' => 1]);

        // A player sees the card - never the questions - before the start.
        $body = $this->actingAs($this->empUser)->getJson('/api/v1/crm/contests/' . $c->uuid)
            ->assertOk()->json('data');
        $this->assertTrue($body['sealed']);
        $this->assertSame(1, $body['question_count']);
        $this->assertCount(0, $body['questions']);
        $this->assertStringNotContainsString('secret question', json_encode($body));

        // The editors still see everything.
        $body = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/contests/' . $c->uuid)
            ->assertOk()->json('data');
        $this->assertCount(1, $body['questions']);
    }

    public function test_the_sidebar_counters_know_whose_desk_the_work_sits_on(): void
    {
        Lead::create(['organization_id' => $this->org->id, 'lead_no' => 1,
            'company_name' => 'Waiting Lead', 'lead_status' => 'unattended',
            'assigned_member_id' => $this->emp->id, 'created_by' => $this->adminUser->id]);
        \App\Models\Crm\Leave::create(['organization_id' => $this->org->id, 'member_id' => $this->emp->id,
            'category' => 'casual', 'duration' => 'full', 'date_from' => now()->addDay()->toDateString(),
            'date_to' => now()->addDay()->toDateString(), 'days' => 1, 'status' => 'pending']);

        // The lead nags its assignee; the leave nags the approver - and
        // neither number crosses to the other desk.
        $mine = $this->actingAs($this->empUser)->getJson('/api/v1/crm/badges')->assertOk()->json('data.attend');
        $this->assertSame(1, $mine['leads']);
        $this->assertArrayNotHasKey('leaves', $mine);

        $boss = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/badges')->assertOk()->json('data.attend');
        $this->assertSame(1, $boss['leaves']);
        $this->assertSame(0, $boss['approvals']);
    }

    public function test_my_dwr_offers_todays_figures_prefilled(): void
    {
        $param = \App\Models\Crm\KpiParameter::create(['organization_id' => $this->org->id,
            'name' => 'Leads Generated', 'unit' => 'count', 'is_active' => true, 'sort' => 1]);
        \App\Models\Crm\MemberKpi::create(['member_id' => $this->emp->id, 'parameter_id' => $param->id,
            'weightage' => 100, 'daily_target' => 5, 'sort' => 1]);
        Lead::create(['organization_id' => $this->org->id, 'lead_no' => 1,
            'company_name' => 'Todays Lead', 'lead_status' => 'unattended',
            'assigned_member_id' => $this->emp->id, 'created_by' => $this->empUser->id]);

        $rows = $this->actingAs($this->empUser)->getJson('/api/v1/crm/dwr/prefill')
            ->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($param->id, $rows[0]['parameter_id']);
        $this->assertEquals(1, $rows[0]['value']);
    }

    public function test_the_admin_runs_contests_but_never_ranks_on_the_board(): void
    {
        $c = \App\Models\Crm\Contest::create([
            'organization_id' => $this->org->id, 'title' => 'Live Quiz',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(),
            'status' => 'published', 'created_by' => $this->adminUser->id,
        ]);
        $q = $c->questions()->create(['type' => 'option', 'question' => 'Pick A',
            'options' => ['A', 'B'], 'correct_option' => 0, 'points' => 10, 'sort' => 1]);

        // The Admin cannot lock in an answer; an employee can.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/contests/' . $c->uuid . '/answer', [
            'question_id' => $q->id, 'answer_option' => 0,
        ])->assertStatus(422);
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/contests/' . $c->uuid . '/answer', [
            'question_id' => $q->id, 'answer_option' => 0,
        ])->assertCreated();

        // An admin answer written before the rule existed stays off the board.
        \App\Models\Crm\ContestAnswer::create(['question_id' => $q->id, 'member_id' => $this->admin->id,
            'answer_option' => 0, 'is_correct' => true, 'points_awarded' => 10]);

        $board = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/contests/' . $c->uuid . '/results')
            ->assertOk()->json('data.board');
        $this->assertCount(1, $board);
        $this->assertSame($this->empUser->name, $board[0]['name']);
    }

    public function test_registering_fetches_an_existing_netvork_account_first(): void
    {
        $person = User::factory()->create(['name' => 'Fresh Hire', 'email' => 'hire@netvork.test', 'username' => 'freshhire']);
        $person->settings()->create([]);
        $person->profile()->create(['timezone' => 'UTC']);

        // Fetch by email, and by username - same person either way. The
        // answer is a shortlist now, because a name can match more than one.
        $byEmail = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=hire@netvork.test')
            ->assertOk()->json('data');
        $this->assertSame('Fresh Hire', $byEmail[0]['name']);
        $this->assertFalse($byEmail[0]['already_member']);
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=FRESHHIRE')
            ->assertOk()->assertJsonPath('data.0.email', 'hire@netvork.test');

        // A stranger to Netvork is a 404; an employee may not go fishing.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=nobody@nowhere.test')
            ->assertNotFound();
        $this->actingAs($this->empUser)->getJson('/api/v1/crm/employees-lookup?q=hire@netvork.test')
            ->assertForbidden();

        // Registering with the fetched email links the account - no password.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/employees', [
            'name' => 'Fresh Hire', 'email' => 'hire@netvork.test', 'crm_role' => 'employee',
        ])->assertCreated();
        $this->assertTrue(Member::where('organization_id', $this->org->id)
            ->where('user_id', $person->id)->exists());

        // And the lookup now says so.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=hire@netvork.test')
            ->assertOk()->assertJsonPath('data.0.already_member', true);
    }

    /**
     * Half a name is what the company actually has.
     *
     * Whoever registers a new hire knows them as "Priyanshu", not as
     * priyanshuyadav@… — so the search that only answered to the whole
     * username was asking for the thing it was being asked for.
     */
    public function test_searching_half_a_name_finds_everybody_who_matches(): void
    {
        foreach ([
            ['Priyanshu Yadav', 'py@netvork.test', 'priyanshuyadav'],
            ['Priyanshu Sharma', 'ps@netvork.test', 'priyanshusharma'],
            ['Rahul Verma', 'rv@netvork.test', 'rahulverma'],
        ] as [$name, $email, $username]) {
            $person = User::factory()->create(['name' => $name, 'email' => $email, 'username' => $username]);
            $person->settings()->create([]);
            $person->profile()->create(['timezone' => 'UTC']);
        }

        $found = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees-lookup?q=priyanshu')
            ->assertOk()->json('data');

        $this->assertCount(2, $found);
        $this->assertEqualsCanonicalizing(
            ['Priyanshu Yadav', 'Priyanshu Sharma'],
            array_column($found, 'name'),
        );

        // The whole username still means that person, and leads the list.
        $exact = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees-lookup?q=priyanshusharma')
            ->assertOk()->json('data');
        $this->assertSame('Priyanshu Sharma', $exact[0]['name']);

        // Half an email address works the same way.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=netvork.test')
            ->assertOk()->assertJsonCount(3, 'data');

        // One letter is not a search, it is a directory dump.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=p')
            ->assertStatus(422);

        // Nobody at all is still a 404, not an empty list dressed as success.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=zzzznobody')
            ->assertNotFound();
    }

    /**
     * The App ID is the number on the person's own profile screen.
     *
     * It is what somebody reads off a card and types in, so a lookup that
     * knew the username and not this was refusing the identifier Netvork
     * puts in front of people.
     */
    public function test_an_account_can_be_found_by_its_app_id(): void
    {
        $person = User::factory()->create([
            'name' => 'Amardeep Gautam', 'email' => 'amardeep@grapmail.com', 'username' => 'amardeepgrapout',
        ]);
        $person->settings()->create([]);
        $person->profile()->create(['timezone' => 'UTC']);
        app(\App\Services\AppIdService::class)->generateFor($person);
        $appId = $person->fresh('appId')->appId->app_id;

        $found = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees-lookup?q=' . urlencode($appId))
            ->assertOk()->json('data');

        $this->assertSame('Amardeep Gautam', $found[0]['name']);
        $this->assertSame($appId, $found[0]['app_id']);

        // Part of it is enough, the same as every other field here.
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/employees-lookup?q=' . urlencode(substr($appId, 0, 4)))
            ->assertOk()->assertJsonPath('data.0.app_id', $appId);
    }

    /** A wildcard typed into the box is text, not a licence to match everyone. */
    public function test_a_percent_sign_matches_a_percent_sign(): void
    {
        $person = User::factory()->create(['name' => 'Fifty Percent', 'email' => 'fifty@netvork.test', 'username' => 'fiftypc']);
        $person->settings()->create([]);
        $person->profile()->create(['timezone' => 'UTC']);

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/employees-lookup?q=' . urlencode('%%'))
            ->assertNotFound();
    }

    public function test_a_blank_employee_code_numbers_itself_from_101(): void
    {
        $first = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/employees', [
            'name' => 'Auto One', 'email' => 'auto1@acme.test', 'password' => 'passw0rd99', 'crm_role' => 'employee',
        ])->assertCreated();
        $this->assertSame('EMP-101', Member::whereHas('user', fn ($q) => $q->where('email', 'auto1@acme.test'))
            ->value('employee_code'));

        // A hand-typed higher code moves the counter past itself.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/employees', [
            'name' => 'Hand Typed', 'email' => 'hand@acme.test', 'password' => 'passw0rd99', 'crm_role' => 'employee',
            'employee_code' => 'EMP-250',
        ])->assertCreated();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/employees', [
            'name' => 'Auto Two', 'email' => 'auto2@acme.test', 'password' => 'passw0rd99', 'crm_role' => 'employee',
        ])->assertCreated();
        $this->assertSame('EMP-251', Member::whereHas('user', fn ($q) => $q->where('email', 'auto2@acme.test'))
            ->value('employee_code'));
    }

    public function test_the_company_keeps_its_own_asset_category_list(): void
    {
        // Out of the box, the built-in list.
        $before = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/assets')
            ->assertOk()->json('categories');
        $this->assertContains('Laptop', $before);

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/asset-categories', [
            'categories' => ['Laptop', 'Tablet', '  Router  ', 'Tablet', ''],
        ])->assertOk();

        // Tidied on the way in: trimmed, de-duplicated, blanks dropped.
        $saved = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters/asset-categories')
            ->assertOk()->json('data.categories');
        $this->assertSame(['Laptop', 'Tablet', 'Router'], $saved);

        // The Add-to-stock dropdown reads the company's list now.
        $this->assertSame($saved, $this->actingAs($this->adminUser)->getJson('/api/v1/crm/assets')
            ->assertOk()->json('categories'));

        // Emptied, it falls back rather than leaving nowhere to file a laptop.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/asset-categories', [
            'categories' => [],
        ])->assertOk();
        $this->assertContains('Laptop', $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/masters/asset-categories')->json('data.categories'));

        // An employee may read the list but never rewrite it.
        $this->actingAs($this->empUser)->putJson('/api/v1/crm/masters/asset-categories', [
            'categories' => ['Nope'],
        ])->assertForbidden();
    }

    public function test_an_invoice_mail_copies_the_salesperson_and_whoever_else_is_named(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        $company = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);
        $client = \App\Models\Crm\Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'Buyer Ltd',
            'email' => 'buyer@client.test', 'created_by' => $this->adminUser->id,
        ]);
        // The salesperson raises it, so the document is theirs.
        $this->emp->update(['rights' => $this->emp->rights + ['invoices' => ['view', 'create'], 'clients' => ['view']]]);
        $uuid = $this->actingAs($this->empUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice', 'issuing_company_id' => $company->id, 'client_uuid' => $client->uuid,
            'invoice_date' => now()->toDateString(),
            'items' => [['plan_name' => 'Plan A', 'qty' => 1, 'unit_price' => 1000]],
        ])->assertCreated()->json('data.uuid');

        // The salesperson's address rides on the document, so the dialog can
        // offer them a copy without a second request.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices/' . $uuid)
            ->assertOk()->assertJsonPath('data.salesperson.email', $this->empUser->email);

        $sent = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices/' . $uuid . '/email', [
            'cc' => [$this->empUser->email, 'accounts@client.test', 'buyer@client.test'],
        ])->assertOk();

        // Copied to the salesperson and to the address named on the spot,
        // but never a second copy to the client it is already going to.
        $sent->assertJsonFragment(['message' => 'Invoice ' . \App\Models\Crm\Invoice::where('uuid', $uuid)->value('number')
            . ' sent to buyer@client.test, copied to ' . $this->empUser->email . ', accounts@client.test.']);

        // The trail keeps the same list, so who saw a document is answerable.
        $logged = \App\Models\Crm\ActivityLog::where('action', 'invoice.emailed')->latest('id')->value('changes');
        $this->assertSame($this->empUser->email . ', accounts@client.test', $logged['cc']);

        // A malformed address stops the send rather than half-sending it.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/invoices/' . $uuid . '/email', [
            'cc' => ['not-an-address'],
        ])->assertStatus(422);
    }

    public function test_a_punch_records_what_it_was_made_on_and_how_far_from_the_office(): void
    {
        Carbon::setTestNow('2026-09-02 10:05:00');

        // No office registered: the device is still recorded, the place is
        // not asked for, and nothing about location is shown.
        $this->actingAs($this->empUser)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Mobile Safari/537.36'])
            ->postJson('/api/v1/crm/punch/in')
            ->assertCreated()
            ->assertJsonPath('data.in_device', 'mobile')
            ->assertJsonPath('data.in_distance_m', null);

        // The company registers its office and asks punches to say where
        // they were made.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/hr-policy', [
            'work_start' => '10:00', 'work_end' => '19:00', 'grace_minutes' => 15,
            'half_day_after_minutes' => 180, 'half_day_hours' => 4, 'full_day_hours' => 8,
            'week_off_days' => [0], 'probation_days' => 180, 'monthly_leave_credit' => 1,
            'encash_unused_leave' => true, 'financial_year_start_month' => 4,
            'office_lat' => 28.6139, 'office_lng' => 77.2090,
            'office_radius_m' => 200, 'punch_needs_location' => true,
        ])->assertOk();

        Carbon::setTestNow('2026-09-03 10:05:00');

        // A punch that will not say where it is made is refused now.
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/punch/in')->assertStatus(422);

        // One from home is accepted and recorded as what it is: far away.
        $punch = $this->actingAs($this->empUser)
            ->withHeaders(['X-Netvork-App' => '1'])
            ->postJson('/api/v1/crm/punch/in', ['latitude' => 28.7041, 'longitude' => 77.1025])
            ->assertCreated()->json('data');

        $this->assertSame('app', $punch['in_device']);
        // Roughly 14 km across Delhi - the number the manager gets to ask about.
        $this->assertGreaterThan(10000, $punch['in_distance_m']);

        Carbon::setTestNow();
    }

    public function test_a_waived_person_is_never_absent_for_not_punching(): void
    {
        Carbon::setTestNow('2026-09-04 18:00:00');

        // A Wednesday and a Thursday with no punches at all.
        $absent = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/punch?date_from=2026-09-02&date_to=2026-09-03&member=' . $this->emp->uuid)
            ->assertOk()->json('data'))->pluck('status');
        $this->assertSame(['absent', 'absent'], $absent->values()->all());

        // The Admin waives the clock for this person - a director does not
        // clock in, and their working days are not absences.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/employees/' . $this->emp->uuid, [
            'name' => 'Emp', 'crm_role' => 'employee', 'punch_waived' => true,
        ])->assertOk();
        $this->assertTrue($this->emp->fresh()->punch_waived);

        $waived = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/punch?date_from=2026-09-02&date_to=2026-09-03&member=' . $this->emp->uuid)
            ->assertOk()->json('data'));
        $this->assertSame(['present', 'present'], $waived->pluck('status')->values()->all());
        $this->assertSame('punch_waived', $waived->first()['status_source']);

        // A Sunday is still a week off, not a working day made present.
        $sunday = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/punch?date_from=2026-09-06&date_to=2026-09-06&member=' . $this->emp->uuid)
            ->assertOk()->json('data'))->first();
        $this->assertSame('week_off', $sunday['status']);

        // The waiver is the Admin's: a Subadmin's payload cannot move it.
        $this->actingAs($this->subUser)->putJson('/api/v1/crm/employees/' . $this->emp->uuid, [
            'name' => 'Emp', 'crm_role' => 'employee', 'punch_waived' => false,
        ])->assertOk();
        $this->assertTrue($this->emp->fresh()->punch_waived);

        Carbon::setTestNow();
    }

    public function test_only_one_mailbox_can_be_the_report_sender(): void
    {
        $a = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Alpha']);
        $b = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Beta']);

        // A payload claiming two would otherwise let iteration order decide
        // who sends the company's own mail, differently on different days.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/communication', [
            'company_senders' => [
                (string) $a->id => ['from_address' => 'a@x.test', 'is_report_sender' => true],
                (string) $b->id => ['from_address' => 'b@x.test', 'is_report_sender' => true],
            ],
        ])->assertOk();

        $saved = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters/communication')
            ->assertOk()->json('data.company_senders');
        $this->assertTrue((bool) $saved[$a->id]['is_report_sender']);
        $this->assertFalse((bool) $saved[$b->id]['is_report_sender']);
    }

    public function test_a_mailbox_can_be_tried_before_it_is_trusted(): void
    {
        $co = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Mail']);
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/communication', [
            'company_senders' => [(string) $co->id => [
                'from_address' => 'billing@example.com', 'mailer' => 'smtp',
                'smtp_host' => 'smtp.invalid.test', 'smtp_port' => 587, 'smtp_password' => 'secret',
            ]],
        ])->assertOk();

        // A host that does not exist comes back as a failure with the
        // server own complaint, not as a 500.
        $smtp = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/masters/communication/test', [
            'check' => 'smtp', 'company_id' => $co->id,
        ])->assertOk()->json('data');
        $this->assertFalse($smtp['ok']);
        $this->assertNotEmpty($smtp['message']);

        // No IMAP host set is answered plainly rather than attempted.
        $imap = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/masters/communication/test', [
            'check' => 'imap', 'company_id' => $co->id,
        ])->assertOk()->json('data');
        $this->assertFalse($imap['ok']);
        $this->assertStringContainsString('IMAP host', $imap['message']);

        // The DNS check reads the from-address domain and reports all three.
        $dns = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/masters/communication/test', [
            'check' => 'dns', 'company_id' => $co->id,
        ])->assertOk()->json('data');
        $this->assertSame('example.com', $dns['domain']);
        $this->assertSame(['SPF', 'DKIM', 'DMARC'], collect($dns['checks'])->pluck('key')->all());
        $this->assertIsInt($dns['score']);

        // Testing is the Admin/Subadmin's, like the rest of this screen.
        $this->actingAs($this->empUser)->postJson('/api/v1/crm/masters/communication/test', [
            'check' => 'dns', 'company_id' => $co->id,
        ])->assertForbidden();
    }
}
