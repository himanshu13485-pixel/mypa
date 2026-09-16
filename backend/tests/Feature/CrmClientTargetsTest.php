<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\Target;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two floors, judged two ways.
 *
 * One desk is given money to bill and is read out of the invoice ledger; the
 * other is given clients to bring in and is read out of the client book -
 * how many were added, how many of them were new business, and what they have
 * billed so far. The two are never added together.
 */
class CrmClientTargetsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private Member $hunter;   // client-oriented
    private Member $seller;   // sales-oriented
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminUser = $this->makeUser('boss@acme.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->hunter = $this->salesperson('hunter@acme.test');
        $this->seller = $this->salesperson('seller@acme.test');

        $this->companyId = IssuingCompany::create([
            'organization_id' => $this->org->id, 'name' => 'Acme Billing',
        ])->id;
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function salesperson(string $email): Member
    {
        return Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser($email)->id,
            'crm_role' => 'employee', 'status' => 'active', 'is_salesperson' => true,
        ]);
    }

    private function client(Member $owner, string $name, string $category): Client
    {
        return Client::create([
            'organization_id' => $this->org->id,
            'company_name' => $name,
            'category' => $category,
            'assigned_member_id' => $owner->id,
            'created_by' => $this->adminUser->id,
        ]);
    }

    private function bill(Member $who, Client $client, float $amount): void
    {
        Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . random_int(1000, 999999),
            'issuing_company_id' => $this->companyId,
            'client_id' => $client->id,
            'member_id' => $who->id,
            'client_category' => $client->category,
            'invoice_date' => now()->toDateString(),
            'subtotal' => $amount,
            'total' => $amount,
        ]);
    }

    private function board(): array
    {
        return $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/targets?year=' . now()->year . '&month=' . now()->month)
            ->assertOk()->json();
    }

    private function rowFor(array $board, Member $member): array
    {
        return collect($board['data'])->firstWhere('member_uuid', $member->uuid);
    }

    public function test_a_client_target_counts_the_new_clients_closed_this_month(): void
    {
        // Ten new clients asked of one desk; money asked of the other.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [
                ['member_uuid' => $this->hunter->uuid, 'kind' => 'clients', 'target_amount' => 0, 'client_target' => 10],
                ['member_uuid' => $this->seller->uuid, 'kind' => 'sales', 'target_amount' => 100000],
            ],
        ])->assertOk();

        // Eight closed this month: five new, three that had dealt with the
        // firm before. Only the five count towards the target.
        foreach (range(1, 5) as $i) {
            $this->bill($this->hunter, $this->client($this->hunter, 'New Co ' . $i, 'new'), 1000);
        }
        foreach (range(1, 3) as $i) {
            $this->bill($this->hunter, $this->client($this->hunter, 'Old Co ' . $i, 'existing'), 500);
        }
        // The other desk's own sale, which must not leak into the client count.
        $this->bill($this->seller, $this->client($this->seller, 'Seller Co', 'new'), 60000);

        $board = $this->board();
        $hunter = $this->rowFor($board, $this->hunter);

        $this->assertSame('clients', $hunter['kind']);
        $this->assertSame(10, $hunter['client_target']);
        // The target is the new ones; the whole month's head count rides beside it.
        $this->assertSame(5, $hunter['clients_new']);
        $this->assertSame(3, $hunter['clients_existing']);
        $this->assertSame(8, $hunter['clients_closed']);
        $this->assertSame(5, $hunter['clients_due']);
        $this->assertEquals(50.0, $hunter['client_percent']);
        // Five of 1,000 and three of 500.
        $this->assertEquals(6500, $hunter['client_sales']);
        $this->assertEquals(5000, $hunter['client_sales_new']);
        $this->assertEquals(1500, $hunter['client_sales_existing']);
        // And the portfolio behind the month: everything on that desk.
        $this->assertSame(8, $hunter['clients_total']);

        $seller = $this->rowFor($board, $this->seller);
        $this->assertSame('sales', $seller['kind']);
        $this->assertEquals(60000, $seller['achieved']);

        // The two floors are totalled apart.
        $this->assertSame(10, $board['client_totals']['client_target']);
        $this->assertSame(5, $board['client_totals']['clients_new']);
        $this->assertSame(8, $board['client_totals']['clients_closed']);
        $this->assertSame(1, $board['client_totals']['people']);
        $this->assertEquals(100000, $board['totals']['target']);
        $this->assertSame(1, $board['totals']['people']);
    }

    public function test_the_kind_sticks_to_the_person_and_to_the_month_it_was_set(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [['member_uuid' => $this->hunter->uuid, 'kind' => 'clients', 'target_amount' => 0, 'client_target' => 4]],
        ])->assertOk();

        // The company now judges this desk on clients, so next month starts there.
        $this->assertSame('clients', $this->hunter->fresh()->target_kind);

        // Copying the month carries the kind and the number with it.
        $next = now()->addMonthNoOverflow();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets/copy-previous', [
            'year' => $next->year, 'month' => $next->month,
        ])->assertOk();

        $copied = Target::where('member_id', $this->hunter->id)
            ->where('year', $next->year)->where('month', $next->month)->firstOrFail();
        $this->assertSame('clients', $copied->kind);
        $this->assertSame(4, $copied->client_target);
    }

    public function test_a_client_still_waiting_for_approval_is_not_on_the_desk_yet(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [['member_uuid' => $this->hunter->uuid, 'kind' => 'clients', 'target_amount' => 0, 'client_target' => 2]],
        ])->assertOk();

        $this->client($this->hunter, 'Counted Co', 'new');
        $this->client($this->hunter, 'Held Co', 'new')->update(['approval_status' => 'pending']);

        // The portfolio counts what is on the books, and a held record is not.
        $this->assertSame(1, $this->rowFor($this->board(), $this->hunter)['clients_total']);
    }
}
