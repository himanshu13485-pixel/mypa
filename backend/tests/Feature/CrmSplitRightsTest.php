<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One right per screen, now that the rights screen matches the menu.
 *
 * The valuable half is proforma against invoices. A proforma is a quote and a
 * tax invoice is a demand for money, and they used to share a single right —
 * so a junior trusted to send quotes was handed the power to issue bills, and
 * there was no way to say otherwise.
 */
class CrmSplitRightsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Client $client;
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $this->client = Client::create([
            'organization_id' => $this->org->id,
            'company_name' => 'Bhavya Steel',
            'status' => 'active',
        ]);

        $this->companyId = IssuingCompany::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Billing',
            'invoice_prefix' => 'INV-',
            'proforma_prefix' => 'PI-',
        ])->id;
    }

    private function staff(array $rights): User
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'crm_role' => 'employee',
            'rights' => $rights,
        ]);

        return $user;
    }

    private function raise(User $who, string $kind)
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->companyId,
            'client_uuid' => $this->client->uuid,
            'invoice_date' => '2026-09-01',
            'items' => [[
                'plan_name' => 'Listing',
                'description' => 'Annual',
                'qty' => 1,
                'unit_price' => 1000,
            ]],
        ]);
    }

    public function test_somebody_trusted_with_quotes_cannot_issue_a_bill(): void
    {
        // The whole point of the split, in one test.
        $junior = $this->staff(['proforma' => ['view', 'create']]);

        $this->raise($junior, 'proforma')->assertCreated();
        $this->raise($junior, 'invoice')->assertStatus(403);
    }

    public function test_and_the_other_way_round(): void
    {
        $biller = $this->staff(['invoices' => ['view', 'create']]);

        $this->raise($biller, 'invoice')->assertCreated();
        $this->raise($biller, 'proforma')->assertStatus(403);
    }

    public function test_the_lists_are_separate_too(): void
    {
        $junior = $this->staff(['proforma' => ['view']]);

        $this->actingAs($junior)->getJson('/api/v1/crm/invoices?kind=proforma')->assertOk();
        $this->actingAs($junior)->getJson('/api/v1/crm/invoices?kind=invoice')->assertStatus(403);

        // And the logs behind them.
        $this->actingAs($junior)->getJson('/api/v1/crm/invoice-log?kind=proforma')->assertOk();
        $this->actingAs($junior)->getJson('/api/v1/crm/invoice-log?kind=invoice')->assertStatus(403);
    }

    public function test_converting_a_quote_into_a_bill_needs_both(): void
    {
        /*
         * Their own proforma, deliberately. A document raised by somebody
         * else is invisible to them anyway — the ledger window would answer
         * 404 and the test would pass without proving anything about rights.
         */
        $quotesOnly = $this->staff(['proforma' => ['view', 'create']]);

        $uuid = $this->raise($quotesOnly, 'proforma')->assertCreated()->json('data.uuid');

        // Somebody trusted only with quotes must not be able to turn one into
        // a tax invoice — that would be the split defeated by one button.
        $this->actingAs($quotesOnly)->postJson("/api/v1/crm/invoices/{$uuid}/convert")
            ->assertStatus(403);

        // With both, it goes through.
        $both = $this->staff(['proforma' => ['view', 'create'], 'invoices' => ['view', 'create']]);
        $theirs = $this->raise($both, 'proforma')->assertCreated()->json('data.uuid');

        $this->actingAs($both)->postJson("/api/v1/crm/invoices/{$theirs}/convert")->assertCreated();
    }

    public function test_a_lead_right_no_longer_carries_the_lead_log(): void
    {
        $seller = $this->staff(['leads' => ['view']]);

        $this->actingAs($seller)->getJson('/api/v1/crm/leads')->assertOk();
        $this->actingAs($seller)->getJson('/api/v1/crm/lead-log')->assertStatus(403);

        $withLog = $this->staff(['leads' => ['view'], 'lead_log' => ['view']]);
        $this->actingAs($withLog)->getJson('/api/v1/crm/lead-log')->assertOk();
    }

    public function test_expenses_vendors_and_commissions_stand_apart(): void
    {
        $spender = $this->staff(['expenses' => ['view']]);

        $this->actingAs($spender)->getJson('/api/v1/crm/expenses')->assertOk();
        $this->actingAs($spender)->getJson('/api/v1/crm/vendors')->assertStatus(403);
        $this->actingAs($spender)->getJson('/api/v1/crm/commissions')->assertStatus(403);
    }

    /**
     * The migration's promise: nobody arrives on Monday locked out.
     *
     * Somebody holding the old wide right is given every right it used to
     * open, with the same abilities — so their access is spelled out rather
     * than changed.
     */
    public function test_the_split_hands_the_old_rights_to_everyone_who_held_them(): void
    {
        $user = $this->staff([]);
        $member = Member::where('user_id', $user->id)->firstOrFail();

        // The world as it was before today: one wide right, view and create.
        \Illuminate\Support\Facades\DB::table('crm_members')->where('id', $member->id)->update([
            'rights' => json_encode(['invoices' => ['view', 'create'], 'leads' => ['view']]),
        ]);

        $this->runSplitMigration();

        $rights = json_decode(
            (string) \Illuminate\Support\Facades\DB::table('crm_members')->where('id', $member->id)->value('rights'),
            true,
        );

        foreach (['proforma', 'invoices', 'recurring'] as $slug) {
            $this->assertEqualsCanonicalizing(['view', 'create'], $rights[$slug], "lost {$slug}");
        }
        $this->assertEqualsCanonicalizing(['view'], $rights['leads']);
        $this->assertEqualsCanonicalizing(['view'], $rights['lead_log']);
    }

    private function runSplitMigration(): void
    {
        $file = glob(database_path('migrations/*one_right_per_screen*.php'));
        $this->assertNotEmpty($file, 'the split migration is missing');

        (require $file[0])->up();
    }
}
