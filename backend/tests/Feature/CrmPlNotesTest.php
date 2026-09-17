<?php

namespace Tests\Feature;

use App\Models\Crm\Expense;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Why the figure is what it is.
 *
 * A P&L says how much and never why, so every month the same questions came
 * back - what was that odd income, why were the bank charges four times the
 * usual. A note hangs on one entry, or on the month itself, and there can be
 * as many as the month deserves.
 */
class CrmPlNotesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->person('boss@grapout.test');
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Grapout Billing'])->id;
        $clientId = \App\Models\Crm\Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'C1', 'created_by' => $this->adminUser->id,
        ])->id;

        Invoice::create([
            'organization_id' => $this->org->id, 'kind' => 'invoice', 'number' => 'INV-1',
            'issuing_company_id' => $companyId, 'client_id' => $clientId, 'member_id' => $this->admin->id,
            'invoice_date' => '2026-08-10', 'subtotal' => 10000, 'total' => 11800,
        ]);
        Expense::create([
            'organization_id' => $this->org->id, 'expense_date' => '2026-08-12', 'vendor_name' => 'HDFC',
            'category' => 'Bank Charges', 'base_amount' => 4000, 'total_amount' => 4000, 'created_by' => $this->adminUser->id,
        ]);
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Himanshu Sachdeva']);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function month(): array
    {
        return $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/pl?month_from=2026-08&month_to=2026-08')
            ->assertOk()->json('data.months.0');
    }

    private function note(?string $lineKey, string $body): int
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/pl/notes', [
            'month' => '2026-08', 'line_key' => $lineKey, 'body' => $body,
        ])->assertCreated()->json('data.id');
    }

    public function test_an_entry_worked_out_by_the_system_can_still_be_explained(): void
    {
        // Neither of these is a row anywhere: one is the sales total, the
        // other a category of the expense book. Both are entries a person
        // looks at and wants a reason for.
        $month = $this->month();
        $sales = collect($month['income'])->firstWhere('source', 'sales');
        $bank = collect($month['expenses'])->firstWhere('label', 'Bank Charges');

        $this->note($sales['key'], 'One big renewal, not a trend.');
        $this->note($bank['key'], 'Includes the annual locker fee.');

        $month = $this->month();
        $sales = collect($month['income'])->firstWhere('source', 'sales');
        $bank = collect($month['expenses'])->firstWhere('label', 'Bank Charges');

        $this->assertSame('One big renewal, not a trend.', $sales['notes'][0]['body']);
        $this->assertSame('Himanshu Sachdeva', $sales['notes'][0]['author']);
        $this->assertSame('Includes the annual locker fee.', $bank['notes'][0]['body']);
        // One explanation does not wander onto the other side's entries.
        $this->assertSame([], $bank['notes'][1] ?? []);
    }

    public function test_a_hand_added_line_keeps_its_notes(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/pl/lines', [
            'month' => '2026-08', 'side' => 'expense', 'label' => 'CGPL Bank Charges', 'amount' => 474737,
        ])->assertCreated();

        $line = collect($this->month()['expenses'])->firstWhere('label', 'CGPL Bank Charges');
        $this->note($line['key'], 'Quarterly sweep, cleared on the 30th.');

        $again = collect($this->month()['expenses'])->firstWhere('label', 'CGPL Bank Charges');
        $this->assertSame('Quarterly sweep, cleared on the 30th.', $again['notes'][0]['body']);
    }

    public function test_the_month_itself_carries_as_many_remarks_as_it_needs(): void
    {
        $this->note(null, 'Diwali fell in this month, so two weeks were quiet.');
        $this->note(null, 'Corpcio invoices raised late; they land in September.');

        $month = $this->month();
        $this->assertCount(2, $month['notes']);
        $this->assertSame('Diwali fell in this month, so two weeks were quiet.', $month['notes'][0]['body']);
        $this->assertSame('Corpcio invoices raised late; they land in September.', $month['notes'][1]['body']);
        // The month's own remarks are not hung on any one entry.
        foreach (array_merge($month['income'], $month['expenses']) as $line) {
            $this->assertSame([], $line['notes']);
        }
    }

    public function test_a_note_belongs_to_its_own_month(): void
    {
        $this->note(null, 'August was the odd one.');

        $september = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/pl?month_from=2026-09&month_to=2026-09')
            ->assertOk()->json('data.months.0');

        $this->assertSame([], $september['notes']);
    }

    public function test_a_remark_can_be_taken_back(): void
    {
        $id = $this->note(null, 'Wrong month, sorry.');

        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/pl/notes/' . $id)->assertOk();
        $this->assertSame([], $this->month()['notes']);

        // And not a second time.
        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/pl/notes/' . $id)->assertNotFound();
    }

    public function test_the_p_and_l_is_still_the_admins_alone(): void
    {
        $sub = $this->person('sub@grapout.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $sub->id, 'crm_role' => 'subadmin',
            'status' => 'active', 'rights' => ['invoices' => ['view']],
        ]);

        $this->actingAs($sub)->postJson('/api/v1/crm/pl/notes', [
            'month' => '2026-08', 'line_key' => null, 'body' => 'Not mine to write.',
        ])->assertForbidden();
    }

    public function test_another_companys_note_is_not_theirs_to_delete(): void
    {
        $id = $this->note(null, 'Ours.');

        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVL']);
        $theirAdmin = $this->person('boss@rival.test');
        Member::create([
            'organization_id' => $other->id, 'user_id' => $theirAdmin->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->actingAs($theirAdmin)->deleteJson('/api/v1/crm/pl/notes/' . $id)->assertNotFound();
        $this->assertCount(1, $this->month()['notes']);
    }

    public function test_the_explanations_travel_with_the_download(): void
    {
        $bank = collect($this->month()['expenses'])->firstWhere('label', 'Bank Charges');
        $this->note($bank['key'], 'Includes the annual locker fee.');
        $this->note(null, 'Diwali month.');

        $this->actingAs($this->adminUser)
            ->get('/api/v1/crm/pl/export?month_from=2026-08&month_to=2026-08')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
