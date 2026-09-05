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
 * A company's name in the address bar.
 *
 * The slug is not decoration. /crm/bhavya-steel/leads is what the browser
 * sends as the company header, so it decides whose records come back — which
 * is what makes a pasted CRM link mean the same thing to the person who
 * opens it as to the person who sent it.
 *
 * That puts a weight on the slug it did not have as a display name: it has
 * to exist for every company, be unique, and never be a word one of the
 * CRM's own screens already answers to.
 */
class CrmCompanySlugTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->superAdmin = $this->person();
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function member(Organization $org, string $role = 'admin'): User
    {
        $user = $this->person();
        Member::create(['organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => $role]);

        return $user;
    }

    public function test_a_company_is_given_an_address_made_from_its_name(): void
    {
        $org = Organization::create(['name' => 'Bhavya Steel & Alloys', 'code' => 'BSA']);

        $this->assertSame('bhavya-steel-alloys', $org->slug);
    }

    public function test_two_companies_with_the_same_name_do_not_share_one(): void
    {
        // Names are not unique and never will be. The slug is, because it is
        // what a URL resolves by.
        $first = Organization::create(['name' => 'Sharma Traders', 'code' => 'ST1']);
        $second = Organization::create(['name' => 'Sharma Traders', 'code' => 'ST2']);

        $this->assertSame('sharma-traders', $first->slug);
        $this->assertSame('sharma-traders-2', $second->slug);
    }

    public function test_a_company_never_lands_on_one_of_the_crm_screens(): void
    {
        /*
         * A company slugged "reports" would sit behind /crm/reports, which is
         * the reports screen — the route wins and the company can never be
         * opened at all.
         */
        $org = Organization::create(['name' => 'Reports', 'code' => 'RPT']);

        $this->assertSame('reports-co', $org->slug);
        $this->assertNotContains($org->slug, Organization::RESERVED_SLUGS);
    }

    public function test_a_name_the_slugger_cannot_read_still_gets_an_address(): void
    {
        // Devanagari transliterates to nothing here. A company with no slug
        // is a company that cannot be opened, so there is no such thing.
        $org = Organization::create(['name' => "\u{092D}\u{0935}\u{094D}\u{092F}", 'code' => 'BHV']);

        $this->assertNotEmpty($org->slug);
    }

    public function test_the_slug_is_what_the_workspace_answers_as(): void
    {
        $acme = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $rival = Organization::create(['name' => 'Rival Exports', 'code' => 'RIVAL']);

        // One account, two companies — the case the URL exists for.
        $user = $this->member($acme);
        Member::create(['organization_id' => $rival->id, 'user_id' => $user->id, 'crm_role' => 'admin']);

        $this->actingAs($user)->withHeader('X-Crm-Org', 'rival-exports')
            ->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Rival Exports')
            ->assertJsonPath('data.organization.slug', 'rival-exports');

        $this->actingAs($user)->withHeader('X-Crm-Org', 'acme-pvt-ltd')
            ->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Acme Pvt Ltd');
    }

    public function test_a_uuid_still_works_because_open_sessions_send_one(): void
    {
        // A browser that was inside the CRM when this shipped has a uuid in
        // its localStorage and will keep sending it until it navigates.
        $org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $user = $this->member($org);

        $this->actingAs($user)->withHeader('X-Crm-Org', $org->uuid)
            ->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.organization.slug', 'acme-pvt-ltd');
    }

    public function test_asking_as_a_company_you_are_not_in_is_refused(): void
    {
        /*
         * The URL decides which company answers, so the URL is also a way to
         * ask for one you have no business seeing. Guessing a slug is a great
         * deal easier than guessing a uuid, which is exactly why this is
         * checked against membership and not against the header alone.
         */
        $mine = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $theirs = Organization::create(['name' => 'Rival Exports', 'code' => 'RIVAL']);
        $this->member($theirs);
        $user = $this->member($mine);

        $this->actingAs($user)->withHeader('X-Crm-Org', 'rival-exports')
            ->getJson('/api/v1/crm/leads')
            ->assertStatus(403);

        // And /crm/me, which is outside the door, simply says nobody.
        $this->actingAs($user)->withHeader('X-Crm-Org', 'rival-exports')
            ->getJson('/api/v1/crm/me')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    public function test_the_super_admin_is_told_where_a_company_lives(): void
    {
        $org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        // Entering hands back the address to navigate to, so the browser does
        // not have to go to /crm and be redirected to find out.
        $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/crm/organizations/{$org->uuid}/enter")
            ->assertOk()
            ->assertJsonPath('data.organization_slug', 'acme-pvt-ltd');
    }

    public function test_creating_a_company_gives_it_one(): void
    {
        $this->actingAs($this->superAdmin)->postJson('/api/v1/admin/crm/organizations', [
            'name' => 'Bhavya Steel',
            'admin_name' => 'Jaimin',
            'admin_email' => 'jaimin@bhavya.test',
            'admin_password' => 'password123',
        ])->assertCreated()->assertJsonPath('data.slug', 'bhavya-steel');
    }

    public function test_an_address_may_be_corrected_but_not_to_a_screens_name(): void
    {
        $org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/admin/crm/organizations/{$org->uuid}", ['slug' => 'acme'])
            ->assertOk();
        $this->assertSame('acme', $org->fresh()->slug);

        // The route would win, and the company would be unreachable.
        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/admin/crm/organizations/{$org->uuid}", ['slug' => 'invoices'])
            ->assertStatus(422);

        // Nor anything a URL cannot carry unchanged.
        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/admin/crm/organizations/{$org->uuid}", ['slug' => 'Acme Pvt Ltd'])
            ->assertStatus(422);

        $this->assertSame('acme', $org->fresh()->slug);
    }

    public function test_the_reserved_list_matches_the_screens_the_router_knows(): void
    {
        /*
         * Two copies of one fact: the backend refuses these slugs, and the
         * frontend uses them to tell a company segment from a screen. They
         * are in different languages and cannot share a file, so this is
         * what keeps them the same.
         *
         * Drift here is quiet and nasty. A screen the frontend knows and the
         * backend does not is a slug somebody can be given, and the company
         * that gets it can never be opened.
         */
        $path = base_path('../frontend/src/lib/crmPath.ts');

        if (! is_file($path)) {
            $this->markTestSkipped('The frontend is not checked out beside the backend.');
        }

        $source = file_get_contents($path);
        $list = substr($source, $start = strpos($source, 'CRM_SECTIONS = new Set(['));
        $list = substr($list, 0, strpos($list, '])'));

        preg_match_all("/'([a-z0-9-]+)'/", $list, $m);

        $this->assertNotEmpty($m[1]);
        $this->assertSame([], array_values(array_diff($m[1], Organization::RESERVED_SLUGS)),
            'The frontend knows a CRM screen the backend does not reserve.');
    }

    public function test_two_companies_cannot_be_moved_onto_one_address(): void
    {
        Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $other = Organization::create(['name' => 'Rival Exports', 'code' => 'RIVAL']);

        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/admin/crm/organizations/{$other->uuid}", ['slug' => 'acme-pvt-ltd'])
            ->assertStatus(422);
    }
}
