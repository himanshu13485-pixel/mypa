<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
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
 * "Compared with what?"
 *
 * A target says whether a desk is where it was asked to be. It does not say
 * which way that desk is travelling, or how it reads against the desk beside
 * it — and those are the two things people argue about in a sales meeting.
 *
 * So every span now carries the span immediately before it, the same length,
 * and every desk carries where it stands in the room.
 */
class CrmTargetComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private Member $alice;

    private Member $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // A fixed month, so "the span before" is never the turn of a year
        // by accident.
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00'));

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('alice@acme.test')->id,
            'crm_role' => 'employee', 'is_salesperson' => true,
        ]);
        $this->bob = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('bob@acme.test')->id,
            'crm_role' => 'employee', 'is_salesperson' => true,
        ]);
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
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function invoice(Member $member, float $total, string $date, string $clientName, string $category = 'regular'): void
    {
        $client = Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => $clientName],
            ['created_by' => $this->adminUser->id],
        );
        $company = IssuingCompany::firstOrCreate(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);

        Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . fake()->unique()->numberBetween(1, 999999),
            'issuing_company_id' => $company->id,
            'client_id' => $client->id,
            'member_id' => $member->id,
            'created_by' => $member->user_id,
            'invoice_date' => $date,
            'client_category' => $category,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    /** @return array<string, mixed> */
    private function read(string $query = 'year=2026&month=9'): array
    {
        return $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/targets?' . $query)
            ->assertOk()->json();
    }

    public function test_a_month_is_read_against_the_month_before_it(): void
    {
        $this->invoice($this->alice, 100000, '2026-08-10', 'Bhavya Steel');
        $this->invoice($this->alice, 150000, '2026-09-10', 'RR Engineers');

        $data = $this->read();

        $this->assertEquals(150000, $data['totals']['achieved']);
        $this->assertEquals(100000, $data['previous']['achieved']);
        $this->assertSame('August 2026', $data['previous']['label']);
        // Half as much again.
        $this->assertEquals(50.0, $data['previous']['growth_percent']);
    }

    public function test_a_quarter_is_read_against_the_quarter_before_it(): void
    {
        // April–June, then July–September.
        $this->invoice($this->alice, 60000, '2026-05-10', 'Bhavya Steel');
        $this->invoice($this->alice, 90000, '2026-08-10', 'RR Engineers');

        $data = $this->read('year=2026&month=7&end_year=2026&end_month=9');

        $this->assertSame(3, $data['months']);
        $this->assertEquals(90000, $data['totals']['achieved']);
        $this->assertEquals(60000, $data['previous']['achieved']);
        $this->assertSame('Apr 2026 — Jun 2026', $data['previous']['label']);
        $this->assertEquals(50.0, $data['previous']['growth_percent']);
    }

    public function test_growth_from_nothing_is_left_unsaid(): void
    {
        // The first month of trading is not a 100% improvement on silence,
        // and a column that says so is a column people learn to ignore.
        $this->invoice($this->alice, 50000, '2026-09-10', 'Bhavya Steel');

        $data = $this->read();

        $this->assertEquals(0, $data['previous']['achieved']);
        $this->assertNull($data['previous']['growth_percent']);
    }

    public function test_each_desk_carries_its_own_before_and_after(): void
    {
        $this->invoice($this->alice, 100000, '2026-08-10', 'Bhavya Steel');
        $this->invoice($this->alice, 50000, '2026-09-10', 'RR Engineers');
        $this->invoice($this->bob, 40000, '2026-09-11', 'Kailash International');

        $rows = collect($this->read()['data'])->keyBy('name');
        $alice = $rows->first(fn ($r) => str_contains((string) $r['member_uuid'], $this->alice->uuid));
        $bob = $rows->first(fn ($r) => str_contains((string) $r['member_uuid'], $this->bob->uuid));

        // Alice billed less than she did last month, and the row says so.
        $this->assertEquals(100000, $alice['previous_achieved']);
        $this->assertEquals(-50.0, $alice['growth_percent']);

        // Bob had no previous month at all, so there is nothing to compare.
        $this->assertEquals(0, $bob['previous_achieved']);
        $this->assertNull($bob['growth_percent']);
    }

    public function test_the_room_is_ranked_on_money_and_on_new_clients(): void
    {
        // Alice bills more; Bob brings in more new names.
        $this->invoice($this->alice, 200000, '2026-09-10', 'Bhavya Steel', 'existing');
        $this->invoice($this->bob, 50000, '2026-09-11', 'Kailash International');
        $this->invoice($this->bob, 50000, '2026-09-12', 'Boston Consulting');

        $rows = collect($this->read()['data'])->keyBy('member_uuid');
        $alice = $rows[$this->alice->uuid];
        $bob = $rows[$this->bob->uuid];

        $this->assertSame(1, $alice['sales_rank']);
        $this->assertSame(2, $bob['sales_rank']);
        $this->assertSame(1, $bob['client_rank']);

        // Two thirds of the floor's money, and none of its new clients.
        $this->assertEquals(66.7, $alice['sales_share']);
        $this->assertEquals(0.0, $alice['client_share']);
        $this->assertEquals(100.0, $bob['client_share']);
    }

    public function test_new_clients_are_compared_with_the_span_before(): void
    {
        $this->invoice($this->alice, 10000, '2026-08-10', 'Bhavya Steel');
        $this->invoice($this->alice, 10000, '2026-09-10', 'RR Engineers');
        $this->invoice($this->alice, 10000, '2026-09-11', 'Kailash International');

        $data = $this->read();
        $alice = collect($data['data'])->firstWhere('member_uuid', $this->alice->uuid);

        $this->assertSame(1, $alice['previous_clients_new']);
        $this->assertSame(2, $alice['clients_new']);
        $this->assertEquals(100.0, $alice['client_growth_percent']);
        $this->assertEquals(100.0, $data['previous']['client_growth_percent']);
    }
}
