<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two companies may share a name; one person may not quietly follow a client.
 *
 * A name is not an identity - two firms are called "Sharma Traders". What
 * makes two records one client is the same GST number, or the same name and
 * the same person behind it. The other way round - a company nobody has
 * entered, but an e-mail or phone the books already know - is the contact
 * changing jobs, and that waits for the Company Admin.
 */
class CrmClientNameAndContactTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private User $aliceUser;
    private Member $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');

        $admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $admin->id, 'rights' => ['clients' => ['view', 'create', 'edit']],
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function add(User $who, array $client)
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/clients', $client);
    }

    public function test_two_firms_of_the_same_name_are_two_clients_when_the_people_differ(): void
    {
        $this->add($this->aliceUser, [
            'company_name' => 'Sharma Traders',
            'contact_person' => 'Ravi Sharma',
            'email' => 'ravi@sharma-one.test',
            'mobile' => '9810011111',
        ])->assertCreated();

        $second = $this->add($this->aliceUser, [
            'company_name' => 'Sharma Traders',
            'contact_person' => 'Neeta Sharma',
            'email' => 'neeta@sharma-two.test',
            'mobile' => '9820022222',
        ])->assertCreated();

        $this->assertSame('approved', $second->json('data.approval_status'));
        $this->assertSame(2, Client::where('company_name', 'Sharma Traders')->count());
    }

    public function test_the_same_name_and_the_same_person_is_still_one_client(): void
    {
        $this->add($this->aliceUser, [
            'company_name' => 'Sharma Traders',
            'email' => 'ravi@sharma-one.test',
            'mobile' => '9810011111',
        ])->assertCreated();

        // The same mobile, written the way a phone shows it.
        $this->add($this->aliceUser, [
            'company_name' => 'SHARMA  TRADERS.',
            'email' => 'accounts@sharma-one.test',
            'mobile' => '+91 98100 11111',
        ])->assertStatus(422);

        $this->assertSame(1, Client::count());
    }

    public function test_a_name_with_nothing_to_tell_it_apart_is_still_refused(): void
    {
        $this->add($this->aliceUser, ['company_name' => 'Bhavya Steel'])->assertCreated();
        $this->add($this->aliceUser, ['company_name' => 'Bhavya Steel'])->assertStatus(422);
    }

    public function test_a_known_contact_under_a_new_company_waits_for_the_admin(): void
    {
        $first = $this->add($this->aliceUser, [
            'company_name' => 'ABC Exports',
            'contact_person' => 'Shivangi Singh',
            'email' => 'shivangi@abc.test',
            'mobile' => '9310325393',
        ])->assertCreated()->json('data');

        // She has left ABC and joined XYZ - same person, new company.
        $moved = $this->add($this->aliceUser, [
            'company_name' => 'XYZ Global',
            'contact_person' => 'Shivangi Singh',
            'email' => 'shivangi@abc.test',
            'mobile' => '9310325393',
        ])->assertCreated()->json();

        $this->assertTrue($moved['needs_approval']);
        $this->assertSame('pending', $moved['data']['approval_status']);
        $this->assertStringContainsString('approval', $moved['message']);

        // Not billable while it waits: the invoice picker cannot see it.
        $picker = collect($this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients/options')->assertOk()->json('data'));
        $this->assertSame(['ABC Exports'], $picker->pluck('company_name')->all());

        // It is on the admin's queue, saying what it matched.
        $queue = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/clients/approvals')->assertOk();
        $this->assertTrue($queue->json('can_decide'));
        $this->assertSame('XYZ Global', $queue->json('data.0.company_name'));
        $this->assertSame('ABC Exports', $queue->json('data.0.matched_client.company_name'));

        // Approved, it is an ordinary client.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/clients/' . $moved['data']['uuid'] . '/approval', ['decision' => 'approve'])
            ->assertOk();
        $this->assertSame('approved', Client::where('uuid', $moved['data']['uuid'])->value('approval_status'));
        $this->assertSame(2, collect($this->actingAs($this->aliceUser)->getJson('/api/v1/crm/clients/options')->json('data'))->count());

        // And the first record was never touched.
        $this->assertSame('approved', Client::where('uuid', $first['uuid'])->value('approval_status'));
    }

    public function test_a_refusal_keeps_the_record_and_says_why(): void
    {
        $this->add($this->aliceUser, [
            'company_name' => 'ABC Exports', 'email' => 'shivangi@abc.test',
        ])->assertCreated();
        $held = $this->add($this->aliceUser, [
            'company_name' => 'XYZ Global', 'email' => 'shivangi@abc.test',
        ])->assertCreated()->json('data');

        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/clients/' . $held['uuid'] . '/approval', [
                'decision' => 'reject', 'note' => 'Still ABC’s client.',
            ])->assertOk();

        $client = Client::where('uuid', $held['uuid'])->firstOrFail();
        $this->assertSame('rejected', $client->approval_status);
        $this->assertSame('inactive', $client->status);
        $this->assertSame('Still ABC’s client.', $client->approval_reason);

        // Decided once is decided.
        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/clients/' . $held['uuid'] . '/approval', ['decision' => 'approve'])
            ->assertStatus(422);
    }

    public function test_a_manager_adding_it_has_already_decided(): void
    {
        $this->add($this->adminUser, [
            'company_name' => 'ABC Exports', 'mobile' => '9310325393',
        ])->assertCreated();

        $second = $this->add($this->adminUser, [
            'company_name' => 'XYZ Global', 'mobile' => '9310325393',
        ])->assertCreated();

        $this->assertFalse($second->json('needs_approval'));
        $this->assertSame('approved', $second->json('data.approval_status'));
    }

    public function test_an_employee_cannot_decide_their_own_held_client(): void
    {
        $this->add($this->aliceUser, ['company_name' => 'ABC Exports', 'mobile' => '9310325393'])->assertCreated();
        $held = $this->add($this->aliceUser, ['company_name' => 'XYZ Global', 'mobile' => '9310325393'])
            ->assertCreated()->json('data');

        $this->actingAs($this->aliceUser)
            ->postJson('/api/v1/crm/clients/' . $held['uuid'] . '/approval', ['decision' => 'approve'])
            ->assertForbidden();

        // Though they can see that it is waiting.
        $this->assertSame('XYZ Global', $this->actingAs($this->aliceUser)
            ->getJson('/api/v1/crm/clients/approvals')->assertOk()->json('data.0.company_name'));
    }
}
