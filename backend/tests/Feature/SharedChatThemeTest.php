<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A chat theme for everybody, said out loud in the chat - and the private
 * colour each person can still keep for themselves, which is not announced
 * because nobody else can see it.
 */
class SharedChatThemeTest extends TestCase
{
    use RefreshDatabase;

    private function pair(): array
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

        return [$me, $mate, Conversation::directBetween($me, $mate)];
    }

    public function test_a_theme_for_everyone_is_seen_by_everyone_and_announced(): void
    {
        [$me, $mate, $conversation] = $this->pair();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/theme", [
            'theme' => 'forest',
            'scope' => 'everyone',
        ])->assertOk()->assertJsonPath('data.scope', 'everyone');

        // Bala sees it too, without having chosen anything.
        $this->actingAs($mate)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.0.theme', 'forest')
            ->assertJsonPath('data.0.shared_theme', 'forest');

        // And the room was told who did it.
        $said = $this->actingAs($mate)->getJson("/api/v1/conversations/{$conversation->uuid}/messages")->assertOk();
        $this->assertStringContainsString('Asha changed the chat theme to Forest', $said->json('data.0.body'));
    }

    public function test_a_colour_only_for_me_is_not_announced_and_lies_over_the_shared_one(): void
    {
        [$me, $mate, $conversation] = $this->pair();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/theme", [
            'theme' => 'ocean', 'scope' => 'everyone',
        ])->assertOk();

        $this->actingAs($mate)->postJson("/api/v1/conversations/{$conversation->uuid}/theme", [
            'theme' => 'midnight', 'scope' => 'me',
        ])->assertOk();

        // Bala reads midnight; Asha still reads the shared ocean.
        $this->actingAs($mate)->getJson('/api/v1/conversations')->assertJsonPath('data.0.theme', 'midnight');
        $this->actingAs($me)->getJson('/api/v1/conversations')->assertJsonPath('data.0.theme', 'ocean');

        // One announcement - the shared change - and none for the private one.
        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_resetting_for_everyone_says_so(): void
    {
        [$me, , $conversation] = $this->pair();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/theme", [
            'theme' => 'rose', 'scope' => 'everyone',
        ])->assertOk();
        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/theme", [
            'theme' => null, 'scope' => 'everyone',
        ])->assertOk();

        $last = $conversation->messages()->latest('id')->first();
        $this->assertStringContainsString('reset the chat theme', $last->body);
        $this->assertNull($conversation->fresh()->theme);
    }
}
