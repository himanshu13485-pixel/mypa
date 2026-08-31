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
 * The CRM addon, end to end: the super admin switches it on for a company,
 * the company's admin registers staff and clients, raises a proforma,
 * converts it to a tax invoice and records the money. Also the two doors
 * that must stay shut: outsiders, and members of a *different* organization.
 */
class CrmAddonTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $orgAdmin;
    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->makeUser('root@netvork.test');
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->orgAdmin = $this->makeUser('boss@acme.test');
        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->orgAdmin->id,
            'crm_role' => 'admin',
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    public function test_the_addon_is_invisible_to_ordinary_users(): void
    {
        $outsider = $this->makeUser('nobody@netvork.test');

        $this->actingAs($outsider)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->actingAs($outsider)->getJson('/api/v1/crm/dashboard')->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/v1/crm/employees')->assertForbidden();
        $this->actingAs($outsider)->getJson('/api/v1/admin/crm/organizations')->assertForbidden();
    }

    public function test_super_admin_enables_the_addon_for_a_company(): void
    {
        $this->actingAs($this->superAdmin)->postJson('/api/v1/admin/crm/organizations', [
            'name' => 'Globex Ltd',
            'admin_name' => 'G. Boss',
            'admin_email' => 'boss@globex.test',
            'admin_password' => 'Sup3rSecret9',
        ])->assertCreated();

        // The named admin can now walk in through the front door.
        $boss = User::where('email', 'boss@globex.test')->firstOrFail();
        $this->actingAs($boss)->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.member.crm_role', 'admin')
            ->assertJsonPath('data.organization.name', 'Globex Ltd');
    }

    public function test_super_admin_can_enter_any_workspace_as_admin(): void
    {
        // Without entering, the super admin has no window into Acme.
        $this->actingAs($this->superAdmin)->getJson('/api/v1/crm/employees')->assertForbidden();

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/crm/organizations/' . $this->org->uuid . '/enter')
            ->assertOk()
            ->assertJsonPath('data.organization_uuid', $this->org->uuid);

        // With the org hat on, they are a full admin of that workspace…
        $this->actingAs($this->superAdmin)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.member.crm_role', 'admin')
            ->assertJsonPath('data.organization.name', 'Acme Pvt Ltd');
        $this->actingAs($this->superAdmin)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson('/api/v1/crm/employees')
            ->assertOk();

        // …and entering twice does not duplicate the membership.
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/crm/organizations/' . $this->org->uuid . '/enter')
            ->assertOk();
        $this->assertSame(1, Member::where('organization_id', $this->org->id)
            ->where('user_id', $this->superAdmin->id)->count());

        // A wrong org hat is refused, not silently redirected.
        $this->actingAs($this->superAdmin)
            ->withHeader('X-Crm-Org', 'no-such-org')
            ->getJson('/api/v1/crm/employees')
            ->assertForbidden();

        // Oversight is invisible inside the company: the org admin's Users
        // list and dropdowns never show the super admin, and the org card
        // counts stay unchanged.
        $emails = collect($this->actingAs($this->orgAdmin)->getJson('/api/v1/crm/employees')->json('data'))
            ->pluck('email');
        $this->assertFalse($emails->contains('root@netvork.test'));
        $memberNames = collect($this->actingAs($this->orgAdmin)->getJson('/api/v1/crm/masters')->json('data.members'))
            ->pluck('name');
        $this->assertFalse($memberNames->contains($this->superAdmin->name));
        $card = collect($this->actingAs($this->superAdmin)->getJson('/api/v1/admin/crm/organizations')->json('data'))
            ->firstWhere('uuid', $this->org->uuid);
        $this->assertSame(1, $card['members']); // just the real admin
    }

    public function test_super_admin_can_rename_an_org_and_reset_its_admin_password(): void
    {
        $this->actingAs($this->superAdmin)->putJson('/api/v1/admin/crm/organizations/' . $this->org->uuid, [
            'name' => 'Acme Renamed Ltd',
            'code' => 'ACME2',
            'admin_email' => 'boss@acme.test',
            'admin_password' => 'NewSecret123',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Acme Renamed Ltd')
            ->assertJsonPath('data.code', 'ACME2');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewSecret123', $this->orgAdmin->fresh()->password));

        // An email that is not this org's admin is refused.
        $this->actingAs($this->superAdmin)->putJson('/api/v1/admin/crm/organizations/' . $this->org->uuid, [
            'admin_email' => 'nobody@else.test',
            'admin_password' => 'NewSecret123',
        ])->assertStatus(422);
    }

    public function test_employee_registration_with_profile_salary_and_rights(): void
    {
        $response = $this->actingAs($this->orgAdmin)->postJson('/api/v1/crm/employees', [
            'name' => 'Sales Person',
            'email' => 'sales@acme.test',
            'password' => 'Str0ngPass123',
            'crm_role' => 'employee',
            'employee_code' => 'EMP-001',
            'department' => 'Sales',
            'designation' => 'Executive',
            'dob' => '1998-04-12',
            'joined_at' => '2026-08-01',
            'is_salesperson' => true,
            'pan_no' => 'ABCPE1234F',
            'rights' => ['clients' => ['view', 'create'], 'invoices' => ['view']],
            'salary' => ['amount' => 25000, 'effective_from' => '2026-08-01'],
        ])->assertCreated();

        $uuid = $response->json('data.uuid');

        // The full profile round-trips, salary history included.
        $this->actingAs($this->orgAdmin)->getJson("/api/v1/crm/employees/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.employee_code', 'EMP-001')
            ->assertJsonPath('data.dob', '1998-04-12')
            ->assertJsonPath('data.salary_records.0.effective_from', '2026-08-01');

        // The new employee's rights bite: clients yes, employees no.
        $employee = User::where('email', 'sales@acme.test')->firstOrFail();
        $this->actingAs($employee)->getJson('/api/v1/crm/clients')->assertOk();
        $this->actingAs($employee)->getJson('/api/v1/crm/employees')->assertForbidden();
        $this->actingAs($employee)->postJson('/api/v1/crm/employees', [])->assertForbidden();
    }

    public function test_salary_revision_with_designation_promotes_and_documents_upload(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');

        $uuid = $this->actingAs($this->orgAdmin)->postJson('/api/v1/crm/employees', [
            'name' => 'Promo Person', 'email' => 'promo@acme.test', 'password' => 'Str0ngPass123',
            'crm_role' => 'employee', 'designation' => 'Executive',
        ])->assertCreated()->json('data.uuid');

        // A revision naming a designation moves the profile with it.
        $this->actingAs($this->orgAdmin)->postJson("/api/v1/crm/employees/{$uuid}/salary", [
            'amount' => 9500, 'effective_from' => '2026-09-01',
            'designation' => 'Senior Executive', 'note' => 'promotion',
        ])->assertCreated();

        $shown = $this->actingAs($this->orgAdmin)->getJson("/api/v1/crm/employees/{$uuid}")->assertOk();
        $this->assertSame('Senior Executive', $shown->json('data.designation'));
        $this->assertSame('Senior Executive', $shown->json('data.salary_records.0.designation'));

        // Documents upload — with a name and, when blank, the file's own name.
        $this->actingAs($this->orgAdmin)->post("/api/v1/crm/employees/{$uuid}/documents", [
            'name' => 'Aadhaar card',
            'file' => \Illuminate\Http\UploadedFile::fake()->create('aadhaar.pdf', 100),
        ])->assertCreated()->assertJsonPath('data.name', 'Aadhaar card');
        $this->actingAs($this->orgAdmin)->post("/api/v1/crm/employees/{$uuid}/documents", [
            'file' => \Illuminate\Http\UploadedFile::fake()->create('pan-card.pdf', 100),
        ])->assertCreated()->assertJsonPath('data.name', 'pan-card.pdf');

        $this->assertCount(2, $this->actingAs($this->orgAdmin)->getJson("/api/v1/crm/employees/{$uuid}")->json('data.documents'));
    }

    public function test_the_last_admin_cannot_be_deactivated(): void
    {
        $member = Member::where('user_id', $this->orgAdmin->id)->first();

        $this->actingAs($this->orgAdmin)
            ->deleteJson('/api/v1/crm/employees/' . $member->uuid)
            ->assertStatus(422);
    }

    public function test_proforma_to_invoice_to_payment_lifecycle(): void
    {
        $admin = $this->actingAs($this->orgAdmin);

        $companyId = $admin->postJson('/api/v1/crm/masters/issuing-companies', [
            'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
        ])->assertCreated()->json('data.id');

        $bankId = $admin->postJson('/api/v1/crm/masters/bank-accounts', [
            'label' => 'HDFC (1234)',
        ])->assertCreated()->json('data.id');

        $clientUuid = $admin->postJson('/api/v1/crm/clients', [
            'company_name' => 'Shree Vinayak Enterprise',
            'contact_person' => 'Jaimin',
            'gst_no' => '24AAACS1234A1Z5',
        ])->assertCreated()->json('data.uuid');

        // A proforma with two lines; totals are computed server-side.
        $pi = $admin->postJson('/api/v1/crm/invoices', [
            'kind' => 'proforma',
            'issuing_company_id' => $companyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-25',
            'cgst' => 900,
            'sgst' => 900,
            'items' => [
                ['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 8000],
                ['plan_name' => 'B2B PAGES', 'qty' => 1, 'unit_price' => 2000],
            ],
        ])->assertCreated();

        $piUuid = $pi->json('data.uuid');
        $this->assertSame('PI-1', $pi->json('data.number'));
        $this->assertSame('10000.00', $pi->json('data.subtotal'));
        $this->assertSame('11800.00', $pi->json('data.total'));

        // Convert: the PI stays, the invoice takes the next INV number.
        $converted = $admin->postJson("/api/v1/crm/invoices/{$piUuid}/convert")->assertCreated();
        $invUuid = $converted->json('data.uuid');
        $this->assertSame('INV-1', $converted->json('data.number'));

        // A second conversion of the same proforma is refused.
        $admin->postJson("/api/v1/crm/invoices/{$piUuid}/convert")->assertStatus(422);

        // Money arrives in two parts; the status follows the arithmetic.
        $admin->postJson("/api/v1/crm/invoices/{$invUuid}/payments", [
            'amount' => 5000, 'received_at' => '2026-08-26', 'bank_account_id' => $bankId, 'payment_mode' => 'NEFT',
        ])->assertCreated()->assertJsonPath('payment_status', 'partial');

        $admin->postJson("/api/v1/crm/invoices/{$invUuid}/payments", [
            'amount' => 6800, 'received_at' => '2026-08-27',
        ])->assertCreated()->assertJsonPath('payment_status', 'paid');

        // Paid documents cannot be cancelled out from under the ledger.
        $admin->postJson("/api/v1/crm/invoices/{$invUuid}/cancel")->assertStatus(422);
    }

    public function test_organizations_cannot_see_into_each_other(): void
    {
        // A second company with its own admin and client.
        $rivalBoss = $this->makeUser('boss@rival.test');
        $rival = Organization::create(['name' => 'Rival Corp', 'code' => 'RIVAL']);
        Member::create(['organization_id' => $rival->id, 'user_id' => $rivalBoss->id, 'crm_role' => 'admin']);

        $clientUuid = $this->actingAs($this->orgAdmin)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Acme Secret Client',
        ])->json('data.uuid');

        // The rival sees an empty list and cannot reach Acme's client by uuid.
        $this->actingAs($rivalBoss)->getJson('/api/v1/crm/clients')
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->actingAs($rivalBoss)->getJson("/api/v1/crm/clients/{$clientUuid}")->assertNotFound();
    }
}
