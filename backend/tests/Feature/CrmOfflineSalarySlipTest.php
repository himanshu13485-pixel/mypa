<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\OfflineSalary;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An offline employee's salary, the way somebody on the rolls has one: a
 * structure, a slip a month prorated by days paid, additions and other
 * deductions with reasons, paid in a run, a PDF payslip and an Excel
 * register - and still counted only in the P&L.
 */
class CrmOfflineSalarySlipTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = User::factory()->create(['name' => 'Boss']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        Member::create(['organization_id' => $org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin', 'status' => 'active']);
    }

    private function admin()
    {
        return $this->actingAs($this->adminUser);
    }

    public function test_a_structure_makes_the_slip_prorates_it_and_pays_it(): void
    {
        $person = $this->admin()->postJson('/api/v1/crm/offline-employees', [
            'employee_code' => 'OFF-101',
            'name' => 'Ram Chand Sachdev',
            'designation' => 'Driver',
            'structure' => [
                'earnings' => [
                    ['label' => 'Basic', 'amount' => 20000],
                    ['label' => 'HRA', 'amount' => 8000],
                    ['label' => 'Conveyance', 'amount' => 2000],
                ],
                'deductions' => [['label' => 'Advance recovery', 'amount' => 1000]],
            ],
            'bank_name' => 'HDFC', 'account_no' => '50100012345678', 'ifsc' => 'HDFC0001234',
        ])->assertCreated()
            ->assertJsonPath('data.monthly_amount', 30000)
            ->assertJsonPath('data.monthly_net', 29000)
            ->json('data');

        $this->admin()->postJson('/api/v1/crm/offline-salaries/generate', ['year' => 2026, 'month' => 8])
            ->assertOk()->assertJsonPath('created', 1);

        $slip = $this->admin()->getJson('/api/v1/crm/offline-salaries?month_from=2026-08&month_to=2026-08')
            ->assertOk()->json('data.0');
        $this->assertEquals(30000, $slip['gross']);
        $this->assertEquals(1000, $slip['deductions']);
        $this->assertEquals(29000, $slip['net']);
        $this->assertCount(3, $slip['earnings']);
        $this->assertSame('pending', $slip['status']);

        // Three days without pay in a 31-day month, a bonus and a canteen bill.
        $adjusted = $this->admin()->putJson('/api/v1/crm/offline-salaries/' . $slip['uuid'], [
            'lop_days' => 3,
            'additions' => 500, 'addition_note' => 'Diwali',
            'other_deductions' => 200, 'other_deduction_note' => 'Canteen',
        ])->assertOk()->json('data');

        // 28/31 of each earning, the advance in full, then the hand-typed money.
        $this->assertEqualsWithDelta(27096.78, $adjusted['gross'], 0.01);
        $this->assertEqualsWithDelta(26396.78, $adjusted['net'], 0.01);
        $this->assertEquals(28, $adjusted['payable_days']);

        // The P&L counts the net as Offline Salary.
        $line = collect($this->admin()->getJson('/api/v1/crm/pl?month_from=2026-08&month_to=2026-08')->json('data.months.0.expenses'))
            ->firstWhere('label', 'Offline Salary');
        $this->assertEqualsWithDelta(26396.78, $line['amount'], 0.01);

        // Filters: paid or not, and which people.
        $count = fn (array $query) => count($this->admin()->getJson('/api/v1/crm/offline-salaries?' . http_build_query(
            ['month_from' => '2026-08', 'month_to' => '2026-08'] + $query,
        ))->assertOk()->json('data'));
        $this->assertSame(1, $count(['status' => ['pending']]));
        $this->assertSame(0, $count(['status' => ['paid']]));
        $this->assertSame(1, $count(['employee' => [$person['uuid']]]));
        $this->assertSame(0, $count(['employee' => ['__none__']]));

        // Paid in a run.
        $this->admin()->postJson('/api/v1/crm/offline-salaries/mark-paid', ['uuids' => [$slip['uuid']], 'payment_mode' => 'UPI'])->assertOk();
        $paid = OfflineSalary::where('uuid', $slip['uuid'])->firstOrFail();
        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->paid_on);
        $this->assertSame(1, $count(['status' => ['paid']]));

        // A payslip, and the register.
        $this->admin()->get('/api/v1/crm/offline-salaries/' . $slip['uuid'] . '/pdf')->assertOk();

        $bytes = $this->admin()->get('/api/v1/crm/offline-salaries/export?month_from=2026-08&month_to=2026-08')->assertOk()->streamedContent();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        foreach (['Ram Chand Sachdev', 'Basic', 'HRA', 'Advance recovery', 'Net salary'] as $text) {
            $this->assertStringContainsString($text, $sheet);
        }
    }

    public function test_a_person_needs_a_structure_or_a_monthly_amount(): void
    {
        $this->admin()->postJson('/api/v1/crm/offline-employees', [
            'employee_code' => 'OFF-9', 'name' => 'No Pay Set',
        ])->assertStatus(422);

        // A plain monthly amount still works, as one line.
        $this->admin()->postJson('/api/v1/crm/offline-employees', [
            'employee_code' => 'OFF-10', 'name' => 'Plain Amount', 'monthly_amount' => 9000,
        ])->assertCreated()->assertJsonPath('data.monthly_net', 9000);

        $this->admin()->postJson('/api/v1/crm/offline-salaries/generate', ['year' => 2026, 'month' => 8])->assertOk();
        $slip = $this->admin()->getJson('/api/v1/crm/offline-salaries?month_from=2026-08&month_to=2026-08')->json('data.0');
        $this->assertSame('Monthly salary', $slip['earnings'][0]['label']);
        $this->assertEquals(9000, $slip['net']);
    }
}
