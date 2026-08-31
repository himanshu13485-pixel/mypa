<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The document as a file. Browsers embedded in desktop apps refuse the print
 * dialog outright, so the paper copy is rendered server-side — and it obeys
 * the same ledger window and the same Work Order method as the screen.
 */
class CrmInvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $alice;
    protected Member $bob;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeUser('root@netvork.test');
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');
        $this->bobUser = $this->makeUser('bob@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = ['clients' => ['view', 'create'], 'invoices' => ['view', 'create']];
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        $this->bob = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bobUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
                'gstin' => '24AAACS1234A1Z5', 'address' => '4th Floor, Ring Road, Surat',
            ])->assertCreated()->json('data.id');
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function raise(User $who, string $kind = 'invoice', array $extra = []): string
    {
        $clientUuid = $this->actingAs($who)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel',
            'contact_person' => 'Jaimin',
            'city' => 'Surat',
            'gst_no' => '24AAACB9999A1Z1',
        ])->assertCreated()->json('data.uuid');

        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => $kind,
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'items' => [[
                'membership' => 'GOLD',
                'plan_name' => 'ARTIS - I',
                'description' => 'Annual listing',
                'qty' => 1,
                'unit_price' => 10000,
            ]],
        ] + $extra)->assertCreated()->json('data.uuid');
    }

    public function test_an_invoice_downloads_as_a_pdf(): void
    {
        $uuid = $this->raise($this->adminUser);

        $response = $this->actingAs($this->adminUser)->get("/api/v1/crm/invoices/{$uuid}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString('INV-1.pdf', $response->headers->get('content-disposition'));
        // A real PDF, not an error page rendered into the body.
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_proforma_downloads_under_its_own_number(): void
    {
        $uuid = $this->raise($this->adminUser, 'proforma');

        $response = $this->actingAs($this->adminUser)->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertOk();

        $this->assertStringContainsString('PI-1.pdf', $response->headers->get('content-disposition'));
    }

    public function test_the_file_stays_inside_the_ledger_window(): void
    {
        $uuid = $this->raise($this->aliceUser);

        // Alice raised it, so it is hers to print.
        $this->actingAs($this->aliceUser)->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertOk();

        // Bob cannot reach the document, so he cannot reach its PDF either.
        $this->actingAs($this->bobUser)->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertNotFound();

        // And it is not a public URL.
        $this->post('/api/v1/logout');
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/crm/invoices/{$uuid}/pdf")->assertUnauthorized();
    }

    public function test_another_company_cannot_print_this_document(): void
    {
        $uuid = $this->raise($this->adminUser);

        $outsiderUser = $this->makeUser('b@globex.test');
        $globex = Organization::create(['name' => 'Globex Ltd', 'code' => 'GLOBEX']);
        Member::create(['organization_id' => $globex->id, 'user_id' => $outsiderUser->id, 'crm_role' => 'admin']);

        $this->actingAs($outsiderUser)->get("/api/v1/crm/invoices/{$uuid}/pdf")->assertNotFound();
    }
}
