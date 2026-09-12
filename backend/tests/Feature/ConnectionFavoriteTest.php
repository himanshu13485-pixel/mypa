<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Starred connections sit at the top of the starrer's list - and only theirs.
 */
class ConnectionFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function people(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $appIds = app(\App\Services\AppIdService::class);

        $users = collect(['Asha', 'Bala', 'Chandra', 'Dev'])->map(function ($name) use ($appIds) {
            $u = User::factory()->create(['name' => $name]);
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            $appIds->generateFor($u);

            return $u;
        });

        return $users->all();
    }

    private function connect(User $a, User $b, string $status = 'accepted'): Connection
    {
        return Connection::create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => $status]);
    }

    public function test_a_favourite_leads_the_list(): void
    {
        [$me, $bala, $chandra, $dev] = $this->people();

        // A second apart: rows made in the same second tie on created_at, and
        // "newest first" among ties is whatever order the database likes.
        \Illuminate\Support\Carbon::setTestNow('2026-09-10 10:00:00');
        $this->connect($me, $bala);
        \Illuminate\Support\Carbon::setTestNow('2026-09-10 10:00:01');
        $this->connect($me, $chandra);
        \Illuminate\Support\Carbon::setTestNow('2026-09-10 10:00:02');
        $withDev = $this->connect($dev, $me);
        \Illuminate\Support\Carbon::setTestNow();

        // Newest first before anything is starred: Dev.
        $this->actingAs($me)->getJson('/api/v1/connections?status=accepted')
            ->assertOk()->assertJsonPath('data.0.user.name', 'Dev');

        $bala_row = Connection::where('addressee_id', $bala->id)->first();
        $this->actingAs($me)->postJson("/api/v1/connections/{$bala_row->uuid}/favorite")
            ->assertOk()->assertJsonPath('data.is_favorite', true);

        $list = $this->actingAs($me)->getJson('/api/v1/connections?status=accepted')->assertOk();
        $list->assertJsonPath('data.0.user.name', 'Bala');
        $list->assertJsonPath('data.0.is_favorite', true);
        $this->assertFalse($list->json('data.1.is_favorite'));

        // One-sided: Bala's own list does not mark Asha.
        $this->actingAs($bala)->getJson('/api/v1/connections?status=accepted')
            ->assertOk()->assertJsonPath('data.0.is_favorite', false);

        // And it comes off the same way.
        $this->actingAs($me)->postJson("/api/v1/connections/{$bala_row->uuid}/favorite")
            ->assertOk()->assertJsonPath('data.is_favorite', false);
        $this->actingAs($me)->getJson('/api/v1/connections?status=accepted')
            ->assertOk()->assertJsonPath('data.0.user.name', 'Dev');

        $this->assertNotNull($withDev);
    }

    public function test_only_an_accepted_connection_can_be_a_favourite(): void
    {
        [$me, $bala, , $dev] = $this->people();

        $pending = $this->connect($me, $bala, 'pending');
        $this->actingAs($me)->postJson("/api/v1/connections/{$pending->uuid}/favorite")->assertStatus(422);

        // Nor can somebody star a connection that is not theirs.
        $theirs = $this->connect($bala, $dev);
        $this->actingAs($me)->postJson("/api/v1/connections/{$theirs->uuid}/favorite")->assertNotFound();
    }
}
