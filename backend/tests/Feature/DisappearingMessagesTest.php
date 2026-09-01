<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Disappearing messages: off unless somebody in the room says otherwise,
 * and when it is on, the old messages really do go.
 */
class DisappearingMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $appIds = app(\App\Services\AppIdService::class);

        $me = User::factory()->create(['name' => 'Asha']);
        $mate = User::factory()->create(['name' => 'Bala']);
        foreach ([$me, $mate] as $u) {
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            $appIds->generateFor($u);
        }

        $conversation = Conversation::directBetween($me, $mate);

        return [$me, $mate, $conversation];
    }

    public function test_a_conversation_keeps_everything_until_somebody_says_otherwise(): void
    {
        [$me, , $conversation] = $this->conversation();

        // The default, and the answer for every conversation that already
        // existed when this arrived.
        $this->assertNull($conversation->auto_delete_hours);

        $this->actingAs($me)->getJson('/api/v1/conversations')
            ->assertOk()->assertJsonPath('data.0.auto_delete_hours', null);
    }

    public function test_a_member_sets_the_span_and_the_room_is_told(): void
    {
        [$me, , $conversation] = $this->conversation();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
            'auto_delete_hours' => 168,
        ])->assertOk()->assertJsonPath('data.auto_delete_hours', 168);

        $this->assertSame(168, $conversation->fresh()->auto_delete_hours);

        // Said in the thread, so nobody's words are on a timer secretly.
        $this->assertStringContainsString('7 days', (string) Message::where('conversation_id', $conversation->id)
            ->latest('id')->value('body'));

        // Only the spans on offer, and only from inside the room.
        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
            'auto_delete_hours' => 3,
        ])->assertStatus(422);

        $stranger = User::factory()->create();
        $stranger->settings()->create([]);
        $this->actingAs($stranger)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
            'auto_delete_hours' => 24,
        ])->assertForbidden();
    }

    public function test_the_sweep_takes_the_old_messages_and_leaves_the_rest(): void
    {
        [$me, $mate, $conversation] = $this->conversation();

        $old = Message::create([
            'conversation_id' => $conversation->id, 'user_id' => $mate->id,
            'type' => 'text', 'body' => 'Said last week',
        ]);
        $old->forceFill(['created_at' => Carbon::now()->subDays(9)])->saveQuietly();

        $recent = Message::create([
            'conversation_id' => $conversation->id, 'user_id' => $mate->id,
            'type' => 'text', 'body' => 'Said today',
        ]);

        // A conversation with no span set is never touched, however old.
        $this->artisan('chat:purge-expired')->assertSuccessful();
        $this->assertDatabaseHas('messages', ['id' => $old->id]);

        // With a week's span, last week's message goes and today's stays.
        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
            'auto_delete_hours' => 168,
        ])->assertOk();

        $this->artisan('chat:purge-expired')->assertSuccessful();
        $this->assertDatabaseMissing('messages', ['id' => $old->id]);
        $this->assertDatabaseHas('messages', ['id' => $recent->id]);
    }
}
