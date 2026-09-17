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

    public function test_every_span_offered_is_a_span_the_server_accepts(): void
    {
        [$me, , $conversation] = $this->conversation();

        // The five on screen: 24 hours, 7, 30, 60 and 90 days.
        foreach ([24, 168, 720, 1440, 2160] as $hours) {
            $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
                'auto_delete_hours' => $hours,
            ])->assertOk();

            $this->assertSame($hours, $conversation->fresh()->auto_delete_hours);
        }

        // And nothing else, so a span nobody can read back cannot be set.
        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
            'auto_delete_hours' => 5000,
        ])->assertStatus(422);
    }

    public function test_a_ninety_day_span_takes_what_is_older_and_nothing_else(): void
    {
        [$me, $mate, $conversation] = $this->conversation();

        $ancient = Message::create([
            'conversation_id' => $conversation->id, 'user_id' => $mate->id,
            'type' => 'text', 'body' => 'Said in the spring',
        ]);
        $ancient->forceFill(['created_at' => Carbon::now()->subDays(95)])->saveQuietly();

        $lastMonth = Message::create([
            'conversation_id' => $conversation->id, 'user_id' => $mate->id,
            'type' => 'text', 'body' => 'Said last month',
        ]);
        $lastMonth->forceFill(['created_at' => Carbon::now()->subDays(40)])->saveQuietly();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
            'auto_delete_hours' => 2160,
        ])->assertOk();

        $this->artisan('chat:purge-expired')->assertSuccessful();

        // Gone from the table itself, not marked deleted: a promise that a
        // message is gone is not kept by a row with a timestamp on it.
        $this->assertDatabaseMissing('messages', ['id' => $ancient->id]);
        $this->assertDatabaseHas('messages', ['id' => $lastMonth->id]);
    }

    public function test_sixty_days_is_the_same_promise_one_month_further_out(): void
    {
        [$me, $mate, $conversation] = $this->conversation();

        $old = Message::create([
            'conversation_id' => $conversation->id, 'user_id' => $mate->id,
            'type' => 'text', 'body' => 'Said two months ago',
        ]);
        $old->forceFill(['created_at' => Carbon::now()->subDays(61)])->saveQuietly();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/retention", [
            'auto_delete_hours' => 1440,
        ])->assertOk();

        $this->artisan('chat:purge-expired')->assertSuccessful();
        $this->assertDatabaseMissing('messages', ['id' => $old->id]);
    }
}
