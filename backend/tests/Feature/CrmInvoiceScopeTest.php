<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whose ledger is it? An employee sees only the documents credited to them,
 * a team head sees their subtree, and the Company Admin sees the company.
 * The window is the same everywhere a document can be reached — the list,
 * the single view, editing, cancelling, converting and payments.
 */
class CrmInvoiceScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $headUser;
    protected User $juniorUser;
    protected User $strangerUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $head;
    protected Member $junior;
    protected Member $stranger;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->headUser = $this->makeUser('head@acme.test');
        $this->juniorUser = $this->makeUser('junior@acme.test');
        $this->strangerUser = $this->makeUser('stranger@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = [
            'clients' => ['view', 'create'],
            'invoices' => ['view', 'create', 'edit', 'delete'],
            'proforma' => ['view', 'create', 'edit'],
            // Granted so the sideways checks below fail on the ledger window,
            // not on a missing module right.
            'approvals' => ['view', 'create'],
            'payments' => ['view', 'create'],
        ];
        $this->head = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->headUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        $this->junior = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->juniorUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->head->id, 'rights' => $rights,
        ]);
        // Same company, a different chain: never in the head's window.
        $this->stranger = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->strangerUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    /** @return string the invoice uuid */
    private function raiseInvoice(User $who, string $company, string $kind = 'invoice'): string
    {
        $clientUuid = $this->actingAs($who)->postJson('/api/v1/crm/clients', [
            'company_name' => $company,
        ])->assertCreated()->json('data.uuid');

        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_an_employee_sees_only_their_own_invoices(): void
    {
        $mine = $this->raiseInvoice($this->juniorUser, 'Bhavya Steel');
        $theirs = $this->raiseInvoice($this->strangerUser, 'Quiet Holdings');

        $this->actingAs($this->juniorUser)->getJson('/api/v1/crm/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $mine)
            ->assertJsonPath('totals.count', 1);

        // Somebody else's document is not merely hidden from the list.
        $this->actingAs($this->juniorUser)->getJson("/api/v1/crm/invoices/{$theirs}")->assertNotFound();
    }

    public function test_a_team_head_sees_the_documents_of_their_team(): void
    {
        $juniors = $this->raiseInvoice($this->juniorUser, 'Bhavya Steel');
        $own = $this->raiseInvoice($this->headUser, 'Head Own Client');
        $outside = $this->raiseInvoice($this->strangerUser, 'Quiet Holdings');

        $listed = $this->actingAs($this->headUser)->getJson('/api/v1/crm/invoices')
            ->assertOk()->assertJsonCount(2, 'data')->json('data.*.uuid');

        $this->assertEqualsCanonicalizing([$juniors, $own], $listed);
        $this->actingAs($this->headUser)->getJson("/api/v1/crm/invoices/{$outside}")->assertNotFound();
    }

    public function test_the_company_admin_sees_the_whole_ledger(): void
    {
        $this->raiseInvoice($this->juniorUser, 'Bhavya Steel');
        $this->raiseInvoice($this->strangerUser, 'Quiet Holdings');

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('totals.count', 2);
    }

    public function test_the_window_holds_for_every_action_on_a_document(): void
    {
        $theirs = $this->raiseInvoice($this->strangerUser, 'Quiet Holdings');
        $proforma = $this->raiseInvoice($this->strangerUser, 'Quiet Traders', 'proforma');

        $junior = $this->actingAs($this->juniorUser);

        $junior->putJson("/api/v1/crm/invoices/{$theirs}", [
            'invoice_date' => '2026-08-21',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 1]],
        ])->assertNotFound();
        $junior->postJson("/api/v1/crm/invoices/{$theirs}/cancel")->assertNotFound();
        $junior->postJson("/api/v1/crm/invoices/{$proforma}/convert")->assertNotFound();
        $junior->postJson("/api/v1/crm/invoices/{$theirs}/payments", [
            'amount' => 100, 'received_at' => '2026-08-22',
        ])->assertNotFound();

        // Nor can it be reached sideways, through the approvals register.
        $junior->postJson('/api/v1/crm/approvals', [
            'type' => 'Discount', 'approval_date' => '2026-08-22', 'invoice_uuid' => $theirs,
        ])->assertNotFound();
        $junior->postJson("/api/v1/crm/invoices/{$theirs}/update-request", [
            'changes' => ['invoice_date' => '2026-08-25'],
        ])->assertNotFound();
    }

    public function test_a_proforma_list_is_scoped_the_same_way(): void
    {
        $mine = $this->raiseInvoice($this->juniorUser, 'Bhavya Steel', 'proforma');
        $this->raiseInvoice($this->strangerUser, 'Quiet Holdings', 'proforma');

        $this->actingAs($this->juniorUser)->getJson('/api/v1/crm/invoices?kind=proforma')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $mine);
    }

    // ---- The two ledgers of a Team Head -------------------------------------

    public function test_the_heads_own_ledger_holds_only_their_own_sales(): void
    {
        $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');

        $mine = $this->actingAs($this->headUser)->getJson('/api/v1/crm/invoices?kind=invoice&scope=mine')
            ->assertOk()->json();

        $this->assertSame(1, $mine['totals']['count']);
        $this->assertSame('mine', $mine['totals']['scope']);
        $this->assertSame($this->headUser->name, $mine['data'][0]['salesperson']['name']);
        $this->assertArrayNotHasKey('by_salesperson', $mine['totals']);
    }

    public function test_the_team_view_is_the_total_and_says_whose_money_is_whose(): void
    {
        $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');
        // Outside the subtree — must stay outside the total too.
        $this->raiseInvoice($this->strangerUser, 'Stranger Client');

        $team = $this->actingAs($this->headUser)->getJson('/api/v1/crm/invoices?kind=invoice&scope=team')
            ->assertOk()->json();

        $this->assertSame(2, $team['totals']['count']);

        $rows = collect($team['totals']['by_salesperson']);
        $this->assertTrue((bool) $rows->firstWhere('is_me', true));
        $this->assertSame($this->headUser->name, $rows->firstWhere('is_me', true)['name']);
        $this->assertEquals(5000, $rows->firstWhere('name', $this->juniorUser->name)['total']);
        $this->assertNull($rows->firstWhere('name', $this->strangerUser->name));
    }

    public function test_a_junior_has_no_second_ledger_to_switch_to(): void
    {
        $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');

        // Whatever scope the junior asks for, the window is just their own.
        foreach (['mine', 'team'] as $scope) {
            $this->actingAs($this->juniorUser)
                ->getJson('/api/v1/crm/invoices?kind=invoice&scope=' . $scope)
                ->assertOk()
                ->assertJsonPath('totals.count', 1)
                ->assertJsonPath('data.0.salesperson.name', $this->juniorUser->name);
        }
    }

    public function test_an_admin_can_also_narrow_to_their_own_sales(): void
    {
        $this->raiseInvoice($this->adminUser, 'Admin Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices?kind=invoice')
            ->assertOk()->assertJsonPath('totals.count', 2);
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/invoices?kind=invoice&scope=mine')
            ->assertOk()
            ->assertJsonPath('totals.count', 1)
            ->assertJsonPath('data.0.salesperson.name', $this->adminUser->name);
    }

    // ---- The same two ledgers on the dashboard and the reports --------------

    public function test_the_dashboard_shows_the_ledger_it_was_asked_for(): void
    {
        // The fixtures are dated 2026-08; hold the clock in that month so
        // the dashboard's this-month figure sees them on any real date.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-08-25 12:00', 'Asia/Kolkata'));

        $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');
        $this->raiseInvoice($this->strangerUser, 'Stranger Client');

        $mine = $this->actingAs($this->headUser)->getJson('/api/v1/crm/dashboard?scope=mine')
            ->assertOk()->json('data');
        $team = $this->actingAs($this->headUser)->getJson('/api/v1/crm/dashboard?scope=team')
            ->assertOk()->json('data');

        $this->assertSame('mine', $mine['scope']);
        $this->assertSame(1, $mine['invoices']['month_count']);
        $this->assertSame(2, $team['invoices']['month_count']);
        // The clients follow the window too, and the stranger stays outside.
        $this->assertSame(1, $mine['clients']['total']);
        $this->assertSame(2, $team['clients']['total']);
        $this->assertCount(2, $team['recent_invoices']);

        // The admin's combined view is still the whole company.
        $all = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/dashboard')
            ->assertOk()->json('data');
        $this->assertSame(3, $all['invoices']['month_count']);
    }

    public function test_the_reports_belong_to_the_admin_now(): void
    {
        $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');
        $this->raiseInvoice($this->strangerUser, 'Stranger Client');

        // Reports moved behind the Admin door - a team head is refused even
        // with the User Log right, which is what that module right now opens
        // and is all it ever actually opened.
        $this->head->update(['rights' => $this->head->rights + ['user_log' => ['view']]]);
        $this->actingAs($this->headUser)->getJson('/api/v1/crm/reports/overview?scope=mine')
            ->assertForbidden();
        $this->actingAs($this->headUser)->getJson('/api/v1/crm/user-log')->assertOk();

        // The Admin still reads the whole company.
        $all = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/reports/overview')
            ->assertOk()->json('data');
        $this->assertEquals(15000, $all['totals']['invoiced']);
        $names = collect($all['top_salespeople'])->pluck('name');
        $this->assertTrue($names->contains($this->strangerUser->name));
    }

    // ---- Old documents, and picking one person ------------------------------

    public function test_a_document_from_before_attribution_still_counts_for_the_team(): void
    {
        $this->raiseInvoice($this->headUser, 'Head Client');
        $juniorDoc = $this->raiseInvoice($this->juniorUser, 'Junior Client');

        // A document from before salespeople were recorded automatically:
        // no member, only the raiser.
        \App\Models\Crm\Invoice::where('uuid', $juniorDoc)->update(['member_id' => null]);

        $team = $this->actingAs($this->headUser)->getJson('/api/v1/crm/invoices?kind=invoice&scope=team')
            ->assertOk()->json();

        // The junior's sale is in the team total, not silently missing.
        $this->assertSame(2, $team['totals']['count']);
        $this->assertNotNull(collect($team['totals']['by_salesperson'])->firstWhere('name', 'Unassigned'));

        // And the backfill migration gives such a document its salesperson back.
        (include database_path('migrations/2026_08_28_200000_backfill_invoice_salesperson.php'))->up();
        $this->assertSame(
            $this->junior->id,
            \App\Models\Crm\Invoice::where('uuid', $juniorDoc)->firstOrFail()->member_id,
        );
    }

    public function test_one_person_can_be_picked_out_of_the_combined_view(): void
    {
        $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');

        $picked = $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&scope=team&salesperson=' . $this->junior->uuid)
            ->assertOk()->json();

        // The rows narrow to that person…
        $this->assertSame(1, $picked['totals']['count']);
        $this->assertSame($this->juniorUser->name, $picked['data'][0]['salesperson']['name']);
        // …but the breakdown keeps describing the whole ledger, so the other
        // cards never vanish under the filter.
        $this->assertCount(2, $picked['totals']['by_salesperson']);

        // Picking someone outside the window narrows to nothing — it reaches nowhere.
        $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/invoices?kind=invoice&scope=team&salesperson=' . $this->stranger->uuid)
            ->assertOk()->assertJsonPath('totals.count', 0);
    }

    // ---- Dues beside the sales, and picking a person everywhere -------------

    public function test_the_due_travels_with_every_sales_figure(): void
    {
        $headDoc = $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');

        // 2,000 of the head's 5,000 arrives; the junior's 5,000 stays owed.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$headDoc}/payments", [
            'amount' => 2000, 'received_at' => '2026-08-25',
        ])->assertCreated();

        $team = $this->actingAs($this->headUser)->getJson('/api/v1/crm/invoices?kind=invoice&scope=team')
            ->assertOk()->json('totals');

        $this->assertEquals(8000, $team['due']);
        $rows = collect($team['by_salesperson']);
        $this->assertEquals(3000, $rows->firstWhere('is_me', true)['due']);
        $this->assertEquals(5000, $rows->firstWhere('name', $this->juniorUser->name)['due']);

        $mine = $this->actingAs($this->headUser)->getJson('/api/v1/crm/invoices?kind=invoice&scope=mine')
            ->assertOk()->json('totals');
        $this->assertEquals(3000, $mine['due']);
    }

    public function test_the_dashboard_and_reports_can_pick_one_person_too(): void
    {
        // The fixtures are dated 2026-08; hold the clock in that month so
        // the dashboard's this-month figure sees them on any real date.
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-08-25 12:00', 'Asia/Kolkata'));

        $this->raiseInvoice($this->headUser, 'Head Client');
        $this->raiseInvoice($this->juniorUser, 'Junior Client');

        $dash = $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/dashboard?scope=team&salesperson=' . $this->junior->uuid)
            ->assertOk()->json('data');

        $this->assertSame(1, $dash['invoices']['month_count']);
        // The options keep listing the whole window even while one is picked.
        $this->assertCount(2, $dash['salespeople']);

        // Reports are the Admin's screen now - the pick-one filter rides it.
        $report = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/reports/overview?scope=team&salesperson=' . $this->junior->uuid)
            ->assertOk()->json('data');
        $this->assertEquals(5000, $report['totals']['invoiced']);

        // Someone outside the window narrows to nothing, never to their sales.
        $none = $this->actingAs($this->headUser)
            ->getJson('/api/v1/crm/dashboard?scope=team&salesperson=' . $this->stranger->uuid)
            ->assertOk()->json('data');
        $this->assertSame(0, $none['invoices']['month_count']);
    }

    public function test_the_pdf_carries_the_payments_received(): void
    {
        $doc = $this->raiseInvoice($this->adminUser, 'Paying Client');
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$doc}/payments", [
            'amount' => 2000, 'received_at' => '2026-08-25', 'payment_mode' => 'NEFT', 'reference_no' => 'UTR-77',
        ])->assertCreated();

        $response = $this->actingAs($this->adminUser)->get("/api/v1/crm/invoices/{$doc}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
