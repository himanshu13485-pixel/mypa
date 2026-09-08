<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding a client.
 *
 * Whoever raises an invoice has whatever the enquiry gave them — a company
 * name, a person, an e-mail, a mobile, a GST number — and any of them should
 * find the record.
 *
 * The two screens that look for a client used to search different sets of
 * fields, so one found by e-mail on the Clients list could not be found at
 * all in the picker on a new invoice. They ask through one scope now, and
 * these tests run the same cases against both.
 */
class CrmClientSearchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->adminUser->id,
            'crm_role' => 'admin',
            'status' => 'active',
        ]);

        $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Meridian Motors',
            'contact_person' => 'Nandini Mehta',
            'email' => 'nandini@meridian.test',
            'alternate_email' => 'accounts@meridian.test',
            'mobile' => '9876543210',
            'telephone' => '022 4455 6677',
            'gst_no' => '27AABCM1234C1ZX',
            'city' => 'Pune',
        ])->assertCreated();

        // Somebody else entirely, so a match has to be a real match.
        $this->as()->postJson('/api/v1/crm/clients', [
            'company_name' => 'Kalyani Engineering',
            'contact_person' => 'Arjun Thakur',
            'email' => 'arjun@kalyani.test',
            'mobile' => '9000011111',
            'city' => 'Nashik',
        ])->assertCreated();
    }

    private function as()
    {
        return $this->actingAs($this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** The company names each screen returns for a search term. */
    private function found(string $screen, string $term): array
    {
        $url = $screen === 'list'
            ? '/api/v1/crm/clients?search=' . urlencode($term)
            : '/api/v1/crm/clients/options?search=' . urlencode($term);

        return array_column($this->as()->getJson($url)->assertOk()->json('data'), 'company_name');
    }

    public static function terms(): array
    {
        return [
            'company name' => ['Meridian'],
            'contact person' => ['Nandini'],
            'e-mail' => ['nandini@meridian.test'],
            'the alternate e-mail' => ['accounts@meridian.test'],
            'mobile' => ['9876543210'],
            'landline' => ['4455 6677'],
            'GST number' => ['27AABCM1234C1ZX'],
            'city' => ['Pune'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terms')]
    public function test_a_client_is_found_by_any_of_its_details(string $term): void
    {
        // Both screens, because the picker searching less than the list is
        // exactly the bug this covers.
        $this->assertSame(['Meridian Motors'], $this->found('list', $term));
        $this->assertSame(['Meridian Motors'], $this->found('picker', $term));
    }

    public function test_a_term_matching_nobody_finds_nobody(): void
    {
        $this->assertSame([], $this->found('list', 'Somebody Else Ltd'));
        $this->assertSame([], $this->found('picker', 'Somebody Else Ltd'));
    }

    public function test_the_picker_returns_the_mobile_it_can_now_be_searched_by(): void
    {
        // Finding a client by a number and then being shown a row that does
        // not mention one leaves you guessing whether it is the right client.
        $row = $this->as()->getJson('/api/v1/crm/clients/options?search=9876543210')
            ->assertOk()->json('data.0');

        $this->assertSame('9876543210', $row['mobile']);
        $this->assertSame('nandini@meridian.test', $row['email']);
    }

    public function test_an_empty_search_still_lists_everybody(): void
    {
        $this->assertCount(2, $this->found('picker', ''));
        $this->assertCount(2, $this->found('list', ''));
    }

    public function test_a_partial_term_matches(): void
    {
        // Half a phone number, as somebody reading it off an enquiry types it.
        $this->assertSame(['Meridian Motors'], $this->found('picker', '98765'));
        $this->assertSame(['Meridian Motors'], $this->found('picker', 'meridian.test'));
    }
}
