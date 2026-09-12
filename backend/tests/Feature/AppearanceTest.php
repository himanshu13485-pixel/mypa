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

    public function test_the_admin_sets_the_crm_look_for_everybody_and_it_is_logged(): void
    {
        [$org, $people] = $this->company();

        $this->actingAs($people['admin'])->putJson('/api/v1/crm/appearance', [
            'background' => 'mesh-northern',
            'sidebar' => 'plum',
        ])->assertOk();

        // Everybody reads it.
        $this->actingAs($people['employee'])->getJson('/api/v1/crm/appearance')
            ->assertOk()
            ->assertJsonPath('data.background', 'mesh-northern')
            ->assertJsonPath('data.sidebar', 'plum')
            ->assertJsonPath('data.can_edit', false);

        $log = ActivityLog::where('action', 'settings.appearance')->firstOrFail();
        $this->assertSame('Default', $log->changes['fields']['background']['from']);
        $this->assertSame('Northern lights', $log->changes['fields']['background']['to']);
        $this->assertSame('Plum', $log->changes['fields']['sidebar']['to']);
    }

    public function test_only_the_admin_may_change_it_and_only_to_a_known_look(): void
    {
        [, $people] = $this->company();

        foreach (['subadmin', 'employee'] as $role) {
            $this->actingAs($people[$role])->putJson('/api/v1/crm/appearance', ['background' => 'aurora'])
                ->assertForbidden();
        }

        $this->actingAs($people['admin'])->putJson('/api/v1/crm/appearance', ['background' => 'tartan'])
            ->assertStatus(422);
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
