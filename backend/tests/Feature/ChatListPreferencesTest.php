<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What a person decides about their own chat list: what stays on top, what
 * is put away, and what colour any of it is. None of it reaches the other
 * side of the conversation - that is the whole point of the tests below.
 */
class ChatListPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function people(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $appIds = app(\App\Services\AppIdService::class);

        $me = User::factory()->create(['name' => 'Asha']);
        $mate = User::factory()->create(['name' => 'Bala']);
        $other = User::factory()->create(['name' => 'Chandra']);

        foreach ([$me, $mate, $other] as $u) {
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            $appIds->generateFor($u);
        }

        return [$me, $mate, $other];
    }

    public function test_a_pinned_chat_leads_the_list_however_old_it_is(): void
    {
        [$me, $mate, $other] = $this->people();

        $quiet = Conversation::directBetween($me, $mate);
        $quiet->update(['last_message_at' => Carbon::now()->subMonth()]);

        $busy = Conversation::directBetween($me, $other);
        $busy->update(['last_message_at' => Carbon::now()]);

        // Before pinning, the busy one leads on recency alone.
        $this->actingAs($me)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.uuid', $busy->uuid);

        $this->actingAs($me)->postJson("/api/v1/conversations/{$quiet->uuid}/pin")
            ->assertOk()
            ->assertJsonPath('data.is_pinned', true);

        $this->actingAs($me)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.uuid', $quiet->uuid)
            ->assertJsonPath('data.0.is_pinned', true)
            ->assertJsonPath('data.1.uuid', $busy->uuid);

        // The pin is mine. Bala's list is not reordered by my decision.
        $this->actingAs($mate)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.is_pinned', false);

        // And it comes off the same way it went on.
        $this->actingAs($me)->postJson("/api/v1/conversations/{$quiet->uuid}/pin")
            ->assertJsonPath('data.is_pinned', false);

        $this->actingAs($me)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.uuid', $busy->uuid);
    }

    public function test_an_archived_chat_leaves_the_list_and_can_be_found_again(): void
    {
        [$me, $mate, $other] = $this->people();

        $kept = Conversation::directBetween($me, $mate);
        $filed = Conversation::directBetween($me, $other);

        $this->actingAs($me)->postJson("/api/v1/conversations/{$filed->uuid}/archive")->assertOk();

        $list = $this->actingAs($me)->getJson('/api/v1/conversations')->assertOk();
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.uuid', $kept->uuid);
        // The count is sent either way, so the list can offer the archive.
        $list->assertJsonPath('archived_count', 1);

        $archived = $this->actingAs($me)->getJson('/api/v1/conversations?archived=1')->assertOk();
        $archived->assertJsonCount(1, 'data');
        $archived->assertJsonPath('data.0.uuid', $filed->uuid);
        $archived->assertJsonPath('data.0.is_archived', true);

        // Chandra never archived anything, so nothing left their list.
        $this->actingAs($other)->getJson('/api/v1/conversations')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('archived_count', 0);
    }

    public function test_a_chat_colour_is_mine_alone(): void
    {
        [$me, $mate] = $this->people();

        $conversation = Conversation::directBetween($me, $mate);

        $this->actingAs($me)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/theme", ['theme' => 'forest'])
            ->assertOk()
            ->assertJsonPath('data.theme', 'forest');

        $this->actingAs($me)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.theme', 'forest');

        // Bala is reading the same conversation in the app's own colours.
        $this->actingAs($mate)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.theme', null);

        // A colour nobody defined is refused rather than stored.
        $this->actingAs($me)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/theme", ['theme' => 'chartreuse'])
            ->assertStatus(422);

        // Null is always an answer: back to the app's own colours.
        $this->actingAs($me)
            ->postJson("/api/v1/conversations/{$conversation->uuid}/theme", ['theme' => null])
            ->assertOk()
            ->assertJsonPath('data.theme', null);
    }

    public function test_one_colour_can_be_applied_to_every_chat_i_am_in(): void
    {
        [$me, $mate, $other] = $this->people();

        $first = Conversation::directBetween($me, $mate);
        Conversation::directBetween($me, $other);

        $this->actingAs($me)
            ->postJson("/api/v1/conversations/{$first->uuid}/theme", [
                'theme' => 'midnight',
                'apply_to_all' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.applied_to_all', true);

        $list = $this->actingAs($me)->getJson('/api/v1/conversations')->assertOk();
        foreach ($list->json('data') as $row) {
            $this->assertSame('midnight', $row['theme']);
        }

        // Still nobody else's business, even applied everywhere.
        $this->actingAs($mate)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.theme', null);
    }

    public function test_a_stranger_cannot_pin_or_colour_a_chat_they_are_not_in(): void
    {
        [$me, $mate, $other] = $this->people();

        $private = Conversation::directBetween($me, $mate);

        $this->actingAs($other)->postJson("/api/v1/conversations/{$private->uuid}/pin")->assertForbidden();
        $this->actingAs($other)
            ->postJson("/api/v1/conversations/{$private->uuid}/theme", ['theme' => 'rose'])
            ->assertForbidden();
    }
}
