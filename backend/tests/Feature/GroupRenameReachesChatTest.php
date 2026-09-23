<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A group renamed is renamed everywhere.
 *
 * The conversation keeps its own copy of the name from the day it was made,
 * and the chat header reads that copy - so a group renamed in Family & Teams
 * went on being called by its old name in Messages, with no way for anybody
 * to put it right.
 */
class GroupRenameReachesChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_renaming_a_group_renames_its_chat(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $owner = User::factory()->create(['name' => 'Company Main', 'username' => 'main', 'email' => 'main@netvork.test']);
        $owner->settings()->create([]);
        $owner->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($owner);

        $group = Group::create(['owner_id' => $owner->id, 'name' => 'CRM Issues', 'type' => 'family']);
        $group->members()->attach($owner->id, ['role' => 'owner']);

        // The chat is made with the name of the day.
        $conversation = Conversation::create([
            'type' => 'group', 'group_id' => $group->id, 'name' => 'CRM Issues', 'created_by' => $owner->id,
        ]);

        $conversation->members()->attach($owner->id);

        $this->actingAs($owner)->putJson("/api/v1/groups/{$group->uuid}", ['name' => 'GrapOut Test'])->assertOk();

        $this->assertSame('GrapOut Test', $conversation->fresh()->name, 'the chat carries the new name');

        $chat = collect($this->actingAs($owner)->getJson('/api/v1/conversations')->assertOk()->json('data'))
            ->firstWhere('uuid', $conversation->uuid);
        $this->assertSame('GrapOut Test', $chat['name'], 'and the chat list says so');
    }
}
