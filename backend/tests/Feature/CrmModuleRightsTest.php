<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rights screen must say what each tick actually does.
 *
 * Two ways that stopped being true, both invisible from the screen itself:
 * a module gained here and never given a label there, so a row was drawn with
 * its raw slug; and four modules offered as checkboxes that nothing in the
 * app ever consulted, so ticking them granted nothing while looking exactly
 * like granting something.
 *
 * The second is the worse of the two. An unlabelled row looks like a bug and
 * gets reported. A checkbox that quietly does nothing looks like it worked,
 * and the person who ticked it finds out from the colleague who cannot work.
 */
class CrmModuleRightsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        $org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        Member::create([
            'organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => 'admin',
        ]);

        return $user;
    }

    public function test_every_module_the_screen_offers_arrives_with_its_name(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/v1/crm/masters')
            ->assertOk()
            ->json('data');

        $this->assertSame($data['modules'], array_keys($data['module_labels']));

        foreach ($data['module_labels'] as $slug => $label) {
            $this->assertNotSame('', trim((string) $label), "The '{$slug}' right has no name.");
            // A label that is just the slug is the failure this exists to
            // catch — the row read "assets" for as long as nobody looked.
            $this->assertNotSame($slug, $label);
        }
    }

    /**
     * Nothing is offered that the app does not actually ask about.
     *
     * A module right is consulted in exactly two ways: `crm.member:<slug>` on
     * a route, or `can('<slug>' …)` in a controller. Anything in the list that
     * appears in neither is a checkbox with nothing behind it — which is how
     * "Proforma invoices", "Contests", "Dashboard" and "Settings" came to be
     * offered for granting when proforma is gated by 'invoices', the settings
     * screen by 'masters', and the other two by nothing at all.
     */
    public function test_no_module_is_offered_that_nothing_consults(): void
    {
        $haystack = collect([
            base_path('routes/api.php'),
            ...$this->phpFilesIn(app_path('Http/Controllers/Api/V1/Crm')),
            ...$this->phpFilesIn(app_path('Http/Middleware')),
        ])->map(fn (string $file) => (string) file_get_contents($file))->implode("\n");

        foreach (Member::moduleSlugs() as $slug) {
            $consulted = str_contains($haystack, "crm.member:{$slug}")
                || str_contains($haystack, "can('{$slug}'");

            $this->assertTrue(
                $consulted,
                "The '{$slug}' right is offered on the rights screen but nothing in the app asks for it.",
            );
        }
    }

    /**
     * The four that granted nothing are still gone.
     *
     * 'proforma' is deliberately not among them any more. It was dead when
     * this test was written — offered on the rights screen and consulted
     * nowhere, with proformas actually gated by 'invoices'. It is now a real
     * right with a real check behind it, which is the opposite problem being
     * fixed rather than the same one returning.
     */
    public function test_the_dead_checkboxes_are_gone(): void
    {
        foreach (['dashboard', 'contests', 'settings'] as $slug) {
            $this->assertArrayNotHasKey($slug, Member::MODULES);
        }
    }

    /**
     * And one right, followed all the way through.
     *
     * The list agreeing with itself is not proof that a tick reaches
     * anything. Newsletters is the cheapest module to stand for the rest: the
     * grant is the only thing between the two outcomes.
     */
    public function test_a_ticked_module_is_the_difference_between_403_and_200(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        $member = Member::create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'crm_role' => 'employee', 'rights' => [],
        ]);

        $this->actingAs($user)->getJson('/api/v1/crm/newsletters')->assertStatus(403);

        $member->update(['rights' => ['newsletters' => ['view']]]);

        $this->actingAs($user)->getJson('/api/v1/crm/newsletters')->assertOk();
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        return array_values(array_filter(
            glob($directory . '/*.php') ?: [],
            'is_file',
        ));
    }
}
