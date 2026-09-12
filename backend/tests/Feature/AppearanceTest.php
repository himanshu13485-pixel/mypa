<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The company's look, set by its Admin and written to the activity log; and a
 * chat's background, announced in the chat when it is everybody's.
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private function company(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $people = [];
        foreach (['admin', 'subadmin', 'employee'] as $role) {
            $u = User::factory()->create(['name' => ucfirst($role)]);
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            Member::create(['organization_id' => $org->id, 'user_id' => $u->id, 'crm_role' => $role, 'status' => 'active']);
            $people[$role] = $u;
        }

        return [$org, $people];
    }

    public function test_everybody_picks_their_own_look_over_the_company_default_and_both_are_logged(): void
    {
        [, $people] = $this->company();

        $this->actingAs($people['admin'])->putJson('/api/v1/crm/appearance', [
            'scope' => 'company',
            'background' => 'mesh-northern',
            'sidebar' => 'plum',
        ])->assertOk();

        // An employee needs no rights to dress their own screens - and a
        // field they leave alone still comes from the company.
        $this->actingAs($people['employee'])->putJson('/api/v1/crm/appearance', [
            'scope' => 'me',
            'background' => 'honeycomb',
        ])->assertOk()
            ->assertJsonPath('data.background', 'honeycomb')
            ->assertJsonPath('data.sidebar', 'plum')
            ->assertJsonPath('data.mine.background', 'honeycomb');

        // Somebody who has not chosen wears the company's.
        $this->actingAs($people['subadmin'])->getJson('/api/v1/crm/appearance')
            ->assertOk()
            ->assertJsonPath('data.background', 'mesh-northern')
            ->assertJsonPath('data.can_edit_company', false);

        // And a member's own theme is theirs on the personal screens too.
        $this->actingAs($people['employee'])->getJson('/api/v1/me/theme')
            ->assertOk()
            ->assertJsonPath('data.background', 'honeycomb')
            ->assertJsonPath('data.sidebar', 'plum')
            ->assertJsonPath('data.company.name', 'Acme Pvt Ltd');

        $logs = ActivityLog::where('action', 'settings.appearance')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('company', $logs[0]->changes['scope']);
        $this->assertSame('Northern lights', $logs[0]->changes['fields']['background']['to']);
        $this->assertSame('Plum', $logs[0]->changes['fields']['sidebar']['to']);
        $this->assertSame('me', $logs[1]->changes['scope']);
        $this->assertSame('Default', $logs[1]->changes['fields']['background']['from']);
        $this->assertSame('Honeycomb', $logs[1]->changes['fields']['background']['to']);
    }

    public function test_only_the_admin_sets_the_company_default_and_only_to_a_known_look(): void
    {
        [, $people] = $this->company();

        foreach (['subadmin', 'employee'] as $role) {
            $this->actingAs($people[$role])->putJson('/api/v1/crm/appearance', ['scope' => 'company', 'background' => 'aurora'])
                ->assertForbidden();
        }

        $this->actingAs($people['employee'])->putJson('/api/v1/crm/appearance', ['scope' => 'me', 'background' => 'tartan'])
            ->assertStatus(422);
    }

    public function test_each_person_words_their_own_birthday_wish(): void
    {
        [, $people] = $this->company();

        $this->actingAs($people['admin'])->putJson('/api/v1/crm/appearance', [
            'scope' => 'company',
            'default_wish' => 'The whole company wishes you well, {name}!',
        ])->assertOk();
        $this->actingAs($people['employee'])->putJson('/api/v1/crm/appearance', [
            'scope' => 'me',
            'default_wish' => 'HBD {name} 🎂 from me',
        ])->assertOk();

        $this->actingAs($people['employee'])->getJson('/api/v1/crm/birthdays/today')
            ->assertOk()
            ->assertJsonPath('data.default_wish', 'HBD {name} 🎂 from me');
        $this->actingAs($people['subadmin'])->getJson('/api/v1/crm/birthdays/today')
            ->assertOk()
            ->assertJsonPath('data.default_wish', 'The whole company wishes you well, {name}!');

        // Clearing your own goes back to the company's, not to nothing.
        $this->actingAs($people['employee'])->putJson('/api/v1/crm/appearance', ['scope' => 'me', 'default_wish' => ''])
            ->assertOk()
            ->assertJsonPath('data.birthday.wish', 'The whole company wishes you well, {name}!');
    }

    public function test_netvork_sets_the_default_for_people_without_a_company_and_they_can_still_choose(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $super = User::factory()->create();
        $super->settings()->create([]);
        $super->roles()->attach(\App\Models\Role::where('slug', 'super_admin')->first()->id);

        $solo = User::factory()->create();
        $solo->settings()->create([]);

        $this->actingAs($super)->putJson('/api/v1/admin/settings', [
            'theme_background' => 'sunset',
            'theme_sidebar' => 'ember',
        ])->assertOk();

        $this->actingAs($solo)->getJson('/api/v1/me/theme')
            ->assertOk()
            ->assertJsonPath('data.background', 'sunset')
            ->assertJsonPath('data.sidebar', 'ember')
            ->assertJsonPath('data.company', null);

        // Plain on purpose is a choice, not a gap for the default to fill.
        $this->actingAs($solo)->putJson('/api/v1/me/theme', ['background' => 'plain'])
            ->assertOk()
            ->assertJsonPath('data.background', 'plain')
            ->assertJsonPath('data.sidebar', 'ember');

        $this->assertDatabaseHas('audit_logs', ['action' => 'theme.updated']);

        $this->actingAs($super)->putJson('/api/v1/admin/settings', ['theme_background' => 'tartan'])->assertStatus(422);
    }

    public function test_a_chat_background_for_everyone_is_announced_and_one_for_me_is_not(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $appIds = app(\App\Services\AppIdService::class);

        $asha = User::factory()->create(['name' => 'Asha']);
        $bala = User::factory()->create(['name' => 'Bala']);
        foreach ([$asha, $bala] as $u) {
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            $appIds->generateFor($u);
        }
        $chat = Conversation::directBetween($asha, $bala);

        $this->actingAs($asha)->postJson("/api/v1/conversations/{$chat->uuid}/background", [
            'background' => 'aurora', 'scope' => 'everyone',
        ])->assertOk();

        $this->actingAs($bala)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.background', 'aurora');
        $this->assertStringContainsString(
            'Asha changed the chat background to Aurora',
            $chat->messages()->latest('id')->first()->body,
        );

        // Bala's own background lies over it, silently.
        $this->actingAs($bala)->postJson("/api/v1/conversations/{$chat->uuid}/background", [
            'background' => 'confetti', 'scope' => 'me',
        ])->assertOk();
        $this->actingAs($bala)->getJson('/api/v1/conversations')->assertJsonPath('data.0.background', 'confetti');
        $this->actingAs($asha)->getJson('/api/v1/conversations')->assertJsonPath('data.0.background', 'aurora');
        $this->assertSame(1, $chat->messages()->count());
    }
}
