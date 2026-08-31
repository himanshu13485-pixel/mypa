<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Targets read over a run of months, the head count of clients behind the
 * money, and the growth map. What matters: a span adds the months' own
 * targets (never invents one), a client billed twice is still one client,
 * and every bucket knows what the same bucket did a year earlier.
 */
class CrmTargetSpanTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $alice;
    protected Member $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');
        $this->bobUser = $this->makeUser('bob@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'is_salesperson' => true, 'rights' => ['targets' => ['view']],
        ]);
        $this->bob = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bobUser->id, 'crm_role' => 'employee',
            'is_salesperson' => true, 'rights' => ['targets' => ['view']],
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function invoice(Member $member, float $total, string $date, string $clientName = 'Client A'): Invoice
    {
        $client = Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => $clientName],
            ['created_by' => $this->adminUser->id],
        );
        $company = IssuingCompany::firstOrCreate(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);

        return Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . fake()->unique()->numberBetween(1, 999999),
            'issuing_company_id' => $company->id,
            'client_id' => $client->id,
            'member_id' => $member->id,
            'created_by' => $member->user_id,
            'invoice_date' => $date,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    private function setTarget(Member $member, int $year, int $month, float $amount): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => $year, 'month' => $month,
            'targets' => [['member_uuid' => $member->uuid, 'target_amount' => $amount]],
        ])->assertOk();
    }

    // ---- Reading many months together ---------------------------------------

    public function test_a_span_adds_the_months_targets_and_their_sales(): void
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonth();

        $this->setTarget($this->alice, $lastMonth->year, $lastMonth->month, 100000);
        $this->setTarget($this->alice, $thisMonth->year, $thisMonth->month, 150000);
        $this->invoice($this->alice, 40000, $lastMonth->toDateString());
        $this->invoice($this->alice, 60000, $thisMonth->toDateString());

        // One month alone is the screen as it always was, and stays editable.
        $single = $this->actingAs($this->adminUser)->getJson(
            '/api/v1/crm/targets?year=' . $thisMonth->year . '&month=' . $thisMonth->month
        )->assertOk()->assertJsonPath('months', 1)->assertJsonPath('editable', true)->json();
        $this->assertEquals(150000, $single['totals']['target']);
        $this->assertEquals(60000, $single['totals']['achieved']);

        // The two months together: the sum of what was already set, and a
        // reading that cannot be typed into.
        $span = $this->actingAs($this->adminUser)->getJson(
            '/api/v1/crm/targets?year=' . $lastMonth->year . '&month=' . $lastMonth->month
            . '&end_year=' . $thisMonth->year . '&end_month=' . $thisMonth->month
        )->assertOk()->assertJsonPath('months', 2)->assertJsonPath('editable', false)->json();

        $this->assertEquals(250000, $span['totals']['target']);
        $this->assertEquals(100000, $span['totals']['achieved']);
        $this->assertEquals(150000, $span['totals']['due']);
        $this->assertSame(
            $lastMonth->format('M Y') . ' — ' . $thisMonth->format('M Y'),
            $span['label'],
        );
    }

    public function test_a_span_that_runs_backwards_or_too_wide_is_refused(): void
    {
        $now = now()->startOfMonth();
        $back = $now->copy()->subMonth();

        $this->actingAs($this->adminUser)->getJson(
            '/api/v1/crm/targets?year=' . $now->year . '&month=' . $now->month
            . '&end_year=' . $back->year . '&end_month=' . $back->month
        )->assertStatus(422);

        $far = $now->copy()->subMonths(30);
        $this->actingAs($this->adminUser)->getJson(
            '/api/v1/crm/targets?year=' . $far->year . '&month=' . $far->month
            . '&end_year=' . $now->year . '&end_month=' . $now->month
        )->assertStatus(422);
    }

    // ---- How many clients the money came from -------------------------------

    public function test_the_client_head_count_never_double_counts(): void
    {
        $month = now()->startOfMonth();

        // Alice bills one client twice and a second client once.
        $this->invoice($this->alice, 30000, $month->toDateString(), 'Bhavya Steel');
        $this->invoice($this->alice, 20000, $month->toDateString(), 'Bhavya Steel');
        $this->invoice($this->alice, 10000, $month->toDateString(), 'Surat Textiles');
        // Bob bills the same first client — one client to the company.
        $this->invoice($this->bob, 40000, $month->toDateString(), 'Bhavya Steel');

        $body = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/targets?year=' . $month->year . '&month=' . $month->month)
            ->assertOk()->json();

        $alice = collect($body['data'])->firstWhere('member_uuid', $this->alice->uuid);
        $this->assertSame(2, $alice['clients']);
        $this->assertSame(3, $alice['invoices']);
        $this->assertEquals(30000, $alice['per_client']);

        $bob = collect($body['data'])->firstWhere('member_uuid', $this->bob->uuid);
        $this->assertSame(1, $bob['clients']);

        // Two desks, three billings of two names: the floor saw two clients.
        $this->assertSame(2, $body['totals']['clients']);
        $this->assertSame(4, $body['totals']['invoices']);
        $this->assertEquals(100000, $body['totals']['achieved']);
        $this->assertEquals(50000, $body['totals']['per_client']);
    }

    // ---- The growth map ------------------------------------------------------

    public function test_the_growth_map_compares_each_period_with_the_one_before_and_last_year(): void
    {
        $thisMonth = now()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonth();
        $lastYear = $thisMonth->copy()->subYear();

        $this->invoice($this->alice, 50000, $lastYear->toDateString(), 'Old Client');
        $this->invoice($this->alice, 40000, $lastMonth->toDateString(), 'Bhavya Steel');
        $this->invoice($this->alice, 60000, $thisMonth->toDateString(), 'Bhavya Steel');
        $this->setTarget($this->alice, $thisMonth->year, $thisMonth->month, 100000);

        $data = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/targets/growth?period=month')
            ->assertOk()->json('data');

        $this->assertCount(12, $data['buckets']);
        $buckets = collect($data['buckets'])->keyBy('key');

        $current = $buckets[$thisMonth->format('Y-m')];
        $this->assertEquals(60000, $current['achieved']);
        $this->assertEquals(100000, $current['target']);
        $this->assertSame(1, $current['clients']);
        // 40,000 last month became 60,000: a half again as much.
        $this->assertEquals(50, $current['growth']);
        // And 50,000 in the same month a year ago: a fifth more.
        $this->assertEquals(50000, $current['last_year']);
        $this->assertEquals(20, $current['yoy']);

        // Last year's sale sits outside the twelve buckets shown, so the
        // window's own total is only the two recent sales.
        $this->assertEquals(100000, $data['totals']['achieved']);
        $this->assertEquals(50000, $data['totals']['last_year']);
        $this->assertEquals(100, $data['totals']['yoy']);
        // One name billed in two months is one client over the window.
        $this->assertSame(1, $data['totals']['clients']);
        $this->assertSame($thisMonth->format('M y'), $data['totals']['best']);
    }

    public function test_the_growth_map_buckets_by_quarter_half_and_year(): void
    {
        $today = now();
        $this->invoice($this->alice, 25000, $today->copy()->startOfMonth()->toDateString());

        foreach ([['quarter', 8], ['half', 6], ['year', 5]] as [$period, $points]) {
            $data = $this->actingAs($this->adminUser)
                ->getJson('/api/v1/crm/targets/growth?period=' . $period)
                ->assertOk()->assertJsonPath('data.period', $period)->json('data');

            $this->assertCount($points, $data['buckets']);
            // Today's sale always lands in the last bucket, whatever the size.
            $this->assertEquals(25000, end($data['buckets'])['achieved']);
        }

        // An unknown period falls back to months rather than erroring out.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/targets/growth?period=fortnight')
            ->assertOk()->assertJsonPath('data.period', 'month');
    }

    public function test_the_growth_map_narrows_to_one_salesperson(): void
    {
        $month = now()->startOfMonth()->toDateString();
        $this->invoice($this->alice, 30000, $month, 'Bhavya Steel');
        $this->invoice($this->bob, 70000, $month, 'Surat Textiles');

        $all = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/targets/growth?period=month')
            ->assertOk()->json('data');
        $this->assertEquals(100000, $all['totals']['achieved']);
        $this->assertCount(3, $all['salespeople']);

        $justBob = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/targets/growth?period=month&salesperson=' . $this->bob->uuid)
            ->assertOk()->assertJsonPath('data.salesperson', $this->bob->uuid)->json('data');
        $this->assertEquals(70000, $justBob['totals']['achieved']);

        // An employee's own window is their own sales, and naming a
        // colleague cannot widen it.
        $mine = $this->actingAs($this->aliceUser)->getJson('/api/v1/crm/targets/growth?period=month')
            ->assertOk()->json('data');
        $this->assertEquals(30000, $mine['totals']['achieved']);

        $poach = $this->actingAs($this->aliceUser)
            ->getJson('/api/v1/crm/targets/growth?period=month&salesperson=' . $this->bob->uuid)
            ->assertOk()->json('data');
        $this->assertEquals(0, $poach['totals']['achieved']);
        $this->assertNull($poach['salesperson']);
    }
}
