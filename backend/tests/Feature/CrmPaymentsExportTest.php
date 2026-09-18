<?php

namespace Tests\Feature;

use App\Models\Crm\BankAccount;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payments ledger, taken away as a file.
 *
 * A spreadsheet of every credit the company received leaves the building the
 * moment it is downloaded, so it is the Company Admin's — and a Subadmin's
 * only where the Admin has said so by name.
 */
class CrmPaymentsExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private int $companyId;

    private int $bankId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->person('boss@grapout.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->companyId = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Grapout Billing'])->id;
        $this->bankId = BankAccount::create([
            'organization_id' => $this->org->id, 'issuing_company_id' => $this->companyId,
            'label' => 'HDFC current', 'bank_name' => 'HDFC', 'account_no' => '0484', 'ifsc' => 'HDFC0000707',
        ])->id;
    }

    private function person(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function credit(array $attrs = []): PaymentInboxEntry
    {
        return PaymentInboxEntry::create($attrs + [
            'organization_id' => $this->org->id,
            'issuing_company_id' => $this->companyId,
            'bank_account_id' => $this->bankId,
            'received_on' => '2026-09-11',
            'payment_mode' => 'NEFT',
            'amount' => 764.50,
            'currency' => 'USD',
            'details' => 'Stripe payout',
            'status' => 'unclaimed',
        ]);
    }

    public function test_the_admin_can_take_the_ledger_away(): void
    {
        $this->credit();

        $this->actingAs($this->adminUser)
            ->get('/api/v1/crm/payments/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_a_subadmin_cannot_until_the_admin_says_so(): void
    {
        $this->credit();
        $subUser = $this->person('sub@grapout.test');
        $sub = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $subUser->id, 'crm_role' => 'subadmin',
            'status' => 'active', 'rights' => ['payments' => ['view', 'create']],
        ]);

        $this->actingAs($subUser)->get('/api/v1/crm/payments/export')->assertForbidden();

        // Named by the Admin, the same Subadmin may.
        $sub->update(['capabilities' => ['payments.export']]);
        $this->actingAs($subUser)->get('/api/v1/crm/payments/export')->assertOk();
    }

    public function test_the_screen_is_told_whether_to_offer_the_button(): void
    {
        $this->credit();

        $this->assertTrue(
            $this->actingAs($this->adminUser)->getJson('/api/v1/crm/payments')->assertOk()->json('can_export'),
        );

        $employeeUser = $this->person('hand@grapout.test');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $employeeUser->id, 'crm_role' => 'employee',
            'status' => 'active', 'rights' => ['payments' => ['view']],
        ]);

        $this->assertFalse(
            $this->actingAs($employeeUser)->getJson('/api/v1/crm/payments')->assertOk()->json('can_export'),
        );
    }

    public function test_the_download_answers_the_question_the_screen_asked(): void
    {
        $this->credit(['received_on' => '2026-09-11']);

        // A range with nothing in it is refused rather than handing back an
        // empty sheet somebody would take for "no payments that month".
        $this->actingAs($this->adminUser)
            ->get('/api/v1/crm/payments/export?date_from=2026-01-01&date_to=2026-01-31')
            ->assertStatus(422);

        $this->actingAs($this->adminUser)
            ->get('/api/v1/crm/payments/export?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk();
    }

    public function test_another_companys_credits_are_not_in_it(): void
    {
        $this->credit();

        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVL']);
        $theirBoss = $this->person('boss@rival.test');
        Member::create([
            'organization_id' => $other->id, 'user_id' => $theirBoss->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        // Their own company has no payments at all, so there is nothing to
        // download — which is also the proof that ours are not in theirs.
        $this->actingAs($theirBoss)->get('/api/v1/crm/payments/export')->assertStatus(422);
    }
}
