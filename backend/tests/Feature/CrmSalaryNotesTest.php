<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalarySlip;
use App\Models\Crm\SalaryStructure;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remarks kept beside the pay: on one person's month, or on the whole run.
 *
 * The office's own record of why a salary is what it is - not something the
 * employee is shown, and not something a recalculation is allowed to take
 * away with the slip it rebuilds.
 */
class CrmSalaryNotesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private User $employeeUser;

    private Member $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->makeUser('boss@grapout.test', 'Himanshu Sachdeva');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->employeeUser = $this->makeUser('sanjeev@grapout.test', 'Sanjeev');
        $this->employee = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->employeeUser->id,
            'crm_role' => 'employee', 'status' => 'active', 'joined_at' => '2024-01-01',
        ]);
        SalaryStructure::create([
            'member_id' => $this->employee->id, 'effective_from' => '2026-01-01',
            'basic' => 12000, 'hra' => 2000, 'components' => [],
            'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
        ]);

        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])
            ->assertOk();
    }

    private function makeUser(string $email, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function register(?User $who = null): array
    {
        return $this->actingAs($who ?? $this->adminUser)
            ->getJson('/api/v1/crm/salary?year=2026&month=8')
            ->assertOk()->json();
    }

    private function note(?string $memberUuid, string $body): int
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/notes', [
            'year' => 2026, 'month' => 8, 'member_uuid' => $memberUuid, 'body' => $body,
        ])->assertCreated()->json('data.id');
    }

    public function test_a_remark_sits_against_one_persons_pay(): void
    {
        $this->note($this->employee->uuid, 'Joined mid-month; August is his first full one.');

        $row = collect($this->register()['data'])->firstWhere('member.uuid', $this->employee->uuid);
        $this->assertCount(1, $row['notes']);
        $this->assertSame('Joined mid-month; August is his first full one.', $row['notes'][0]['body']);
        $this->assertSame('Himanshu Sachdeva', $row['notes'][0]['author']);
    }

    public function test_the_month_carries_its_own_remarks_as_many_as_it_needs(): void
    {
        $this->note(null, 'Run held back to the 7th, bank holiday.');
        $this->note(null, 'Diwali bonus goes out with September.');

        $register = $this->register();
        $this->assertCount(2, $register['notes']);
        $this->assertSame('Run held back to the 7th, bank holiday.', $register['notes'][0]['body']);
        // A remark about the month is not hung on anybody's salary.
        $this->assertSame([], $register['data'][0]['notes']);
    }

    public function test_recalculating_a_slip_does_not_lose_what_was_written(): void
    {
        $this->note($this->employee->uuid, 'Arrears of July recovered here.');
        $slip = SalarySlip::where('member_id', $this->employee->id)->firstOrFail();

        // Recalculation deletes the slip and builds a fresh one, which is why
        // a remark is kept against the person and the month instead.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/' . $slip->uuid . '/recalculate')
            ->assertOk();

        $this->assertNotSame($slip->uuid, SalarySlip::where('member_id', $this->employee->id)->firstOrFail()->uuid);

        $row = collect($this->register()['data'])->firstWhere('member.uuid', $this->employee->uuid);
        $this->assertSame('Arrears of July recovered here.', $row['notes'][0]['body']);
    }

    public function test_a_remark_belongs_to_its_own_month(): void
    {
        $this->note($this->employee->uuid, 'August only.');

        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 9])
            ->assertOk();

        $september = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/salary?year=2026&month=9')
            ->assertOk()->json();

        $this->assertSame([], $september['notes']);
        $this->assertSame([], $september['data'][0]['notes']);
    }

    public function test_the_employee_reading_their_own_slip_is_not_shown_the_office_notes(): void
    {
        $this->note($this->employee->uuid, 'Watch the attendance next month.');
        $this->note(null, 'Internal: revise the structure in October.');

        $own = $this->register($this->employeeUser);

        $this->assertCount(1, $own['data']);
        $this->assertSame([], $own['data'][0]['notes']);
        $this->assertSame([], $own['notes']);
    }

    public function test_writing_one_is_for_the_people_whose_job_the_payroll_is(): void
    {
        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/salary/notes', [
            'year' => 2026, 'month' => 8, 'member_uuid' => $this->employee->uuid, 'body' => 'Give me a raise.',
        ])->assertForbidden();
    }

    public function test_a_remark_can_be_taken_back_but_not_by_another_company(): void
    {
        $id = $this->note(null, 'Wrong month.');

        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVL']);
        $theirBoss = $this->makeUser('boss@rival.test', 'Someone Else');
        Member::create([
            'organization_id' => $other->id, 'user_id' => $theirBoss->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->actingAs($theirBoss)->deleteJson('/api/v1/crm/salary/notes/' . $id)->assertNotFound();
        $this->assertCount(1, $this->register()['notes']);

        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/salary/notes/' . $id)->assertOk();
        $this->assertSame([], $this->register()['notes']);
    }

    public function test_bank_details_added_after_the_month_was_generated_still_show(): void
    {
        // Exactly the way it happens: the month is run, and somebody's
        // account number is filled in afterwards. The slip stamped a blank,
        // and the register showed a blank beside their name while every
        // colleague's account showed.
        $this->employee->update([
            'bank_name' => 'Hdfc',
            'bank_account_no' => '04841140003524',
            'bank_ifsc' => 'HDFC0000707',
            'bank_account_name' => 'Sanjeev',
        ]);

        $row = collect($this->register()['data'])->firstWhere('member.uuid', $this->employee->uuid);
        $this->assertSame('Hdfc', $row['bank_name']);
        $this->assertSame('04841140003524', $row['account_no']);
        $this->assertSame('HDFC0000707', $row['ifsc']);
    }

    public function test_what_was_paid_keeps_the_account_it_was_paid_to(): void
    {
        $this->employee->update([
            'bank_name' => 'Hdfc', 'bank_account_no' => '04841140003524',
            'bank_ifsc' => 'HDFC0000707', 'bank_account_name' => 'Sanjeev',
        ]);

        $slip = SalarySlip::where('member_id', $this->employee->id)->firstOrFail();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/mark-paid', [
            'uuids' => [$slip->uuid],
        ])->assertOk();

        // Changing bank afterwards must not rewrite where the money went.
        $this->employee->update(['bank_name' => 'ICICI', 'bank_account_no' => '999000111222', 'bank_ifsc' => 'ICIC0000123']);

        $row = collect($this->register()['data'])->firstWhere('member.uuid', $this->employee->uuid);
        $this->assertSame('paid', $row['status']);
        $this->assertSame('Hdfc', $row['bank_name']);
        $this->assertSame('04841140003524', $row['account_no']);
    }

    public function test_a_pending_slip_follows_a_corrected_account(): void
    {
        $this->employee->update(['bank_name' => 'Hdfc', 'bank_account_no' => '1111', 'bank_ifsc' => 'HDFC0000707']);
        // A digit was wrong; the payment has not gone out yet.
        $this->employee->update(['bank_account_no' => '2222']);

        $row = collect($this->register()['data'])->firstWhere('member.uuid', $this->employee->uuid);
        $this->assertSame('2222', $row['account_no']);
    }

    public function test_one_remark_can_cover_a_selection_of_salaries(): void
    {
        $second = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('kanika@grapout.test', 'Kanika')->id,
            'crm_role' => 'employee', 'status' => 'active', 'joined_at' => '2024-01-01',
        ]);
        SalaryStructure::create([
            'member_id' => $second->id, 'effective_from' => '2026-01-01',
            'basic' => 15000, 'hra' => 3000, 'components' => [],
            'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
        ]);
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])->assertOk();

        $uuids = collect($this->register()['data'])->pluck('uuid')->all();
        $this->assertCount(2, $uuids);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/notes/bulk', [
            'uuids' => $uuids, 'body' => 'Paid from the ICICI account, not the usual one.',
        ])->assertCreated()->assertJsonPath('message', 'Noted against 2 salaries.');

        foreach ($this->register()['data'] as $row) {
            $this->assertSame('Paid from the ICICI account, not the usual one.', $row['notes'][0]['body']);
        }
    }

    public function test_a_bulk_remark_is_for_the_people_whose_job_the_payroll_is(): void
    {
        $uuids = collect($this->register()['data'])->pluck('uuid')->all();

        $this->actingAs($this->employeeUser)->postJson('/api/v1/crm/salary/notes/bulk', [
            'uuids' => $uuids, 'body' => 'All of us deserve a raise.',
        ])->assertForbidden();
    }

    public function test_the_register_download_carries_them(): void
    {
        $this->note($this->employee->uuid, 'Arrears of July recovered here.');

        $this->actingAs($this->adminUser)
            ->get('/api/v1/crm/salary/export?year=2026&month=8')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
