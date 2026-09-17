<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\OfflineEmployee;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The offline register keeps remarks the way the payroll does.
 *
 * Same questions, different people: why this amount, which account it went
 * from, what was odd about the month - and one remark against a selection,
 * because "paid from the ICICI account" is true of some of a list and false
 * of the rest.
 */
class CrmOfflineSalaryNotesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private OfflineEmployee $ram;

    private OfflineEmployee $santosh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->person('boss@grapout.test', 'Himanshu Sachdeva');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->ram = $this->hire('OFF-101', 'Ram Chand Sachdev');
        $this->santosh = $this->hire('OFF-102', 'Santosh Sachdeva');

        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/offline-salaries/generate', ['year' => 2026, 'month' => 8])
            ->assertOk();
    }

    private function person(string $email, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function hire(string $code, string $name): OfflineEmployee
    {
        return OfflineEmployee::create([
            'organization_id' => $this->org->id,
            'employee_code' => $code,
            'name' => $name,
            'monthly_amount' => 10000,
            'status' => 'active',
            'joined_on' => '2025-01-01',
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function register(): array
    {
        return $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/offline-salaries?month_from=2026-08&month_to=2026-08')
            ->assertOk()->json();
    }

    private function note(array $payload)
    {
        return $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/offline-salaries/notes', $payload + ['year' => 2026, 'month' => 8]);
    }

    public function test_a_remark_sits_against_one_persons_pay(): void
    {
        $this->note(['employee_uuid' => $this->ram->uuid, 'body' => 'Half the month on site.'])->assertCreated();

        $rows = collect($this->register()['data']);
        $ram = $rows->firstWhere('employee.uuid', $this->ram->uuid);
        $santosh = $rows->firstWhere('employee.uuid', $this->santosh->uuid);

        $this->assertSame('Half the month on site.', $ram['notes'][0]['body']);
        $this->assertSame('Himanshu Sachdeva', $ram['notes'][0]['author']);
        // One person's remark is not everybody's.
        $this->assertSame([], $santosh['notes']);
    }

    public function test_one_remark_can_cover_several_people_at_once(): void
    {
        $rows = collect($this->register()['data']);
        $uuids = $rows->pluck('uuid')->all();

        $this->note(['uuids' => $uuids, 'body' => 'Paid from the ICICI account, not the usual one.'])
            ->assertCreated()
            ->assertJsonPath('message', 'Noted against 2 salaries.');

        foreach ($this->register()['data'] as $row) {
            $this->assertSame('Paid from the ICICI account, not the usual one.', $row['notes'][0]['body']);
        }
    }

    public function test_the_month_carries_its_own(): void
    {
        $this->note(['body' => 'Everyone paid a week late this month.'])->assertCreated();

        $register = $this->register();
        $this->assertCount(1, $register['notes']);
        $this->assertSame('Everyone paid a week late this month.', $register['notes'][0]['body']);
        // Not hung on anybody's pay.
        $this->assertSame([], $register['data'][0]['notes']);
    }

    public function test_it_belongs_to_its_own_month(): void
    {
        $this->note(['body' => 'August only.'])->assertCreated();

        $september = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/offline-salaries?month_from=2026-09&month_to=2026-09')
            ->assertOk()->json();

        $this->assertSame([], $september['notes']);
    }

    public function test_the_two_registers_do_not_show_each_others_remarks(): void
    {
        $this->note(['body' => 'The offline lot were paid late.'])->assertCreated();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/salary/notes', [
            'year' => 2026, 'month' => 8, 'body' => 'The payroll went out on time.',
        ])->assertCreated();

        $this->assertSame('The offline lot were paid late.', $this->register()['notes'][0]['body']);
        $this->assertCount(1, $this->register()['notes']);

        $payroll = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/salary?year=2026&month=8')->assertOk()->json();
        $this->assertSame('The payroll went out on time.', $payroll['notes'][0]['body']);
        $this->assertCount(1, $payroll['notes']);
    }

    public function test_a_remark_can_be_taken_back(): void
    {
        $id = $this->note(['body' => 'Wrong month.'])->assertCreated()->json('data.id');

        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/offline-salaries/notes/' . $id)->assertOk();
        $this->assertSame([], $this->register()['notes']);
    }

    public function test_another_company_cannot_reach_it(): void
    {
        $id = $this->note(['body' => 'Ours.'])->assertCreated()->json('data.id');

        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVL']);
        $theirBoss = $this->person('boss@rival.test', 'Someone Else');
        Member::create([
            'organization_id' => $other->id, 'user_id' => $theirBoss->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->actingAs($theirBoss)->deleteJson('/api/v1/crm/offline-salaries/notes/' . $id)->assertNotFound();
        $this->assertCount(1, $this->register()['notes']);
    }
}
