<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding somebody to connect with. People remember a piece of a username,
 * not the whole of it, so a fragment has to work — without turning the box
 * into a way to read the platform's address book.
 */
class ConnectionSearchTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $name, string $username, string $email): User
    {
        $user = User::factory()->create(['name' => $name, 'username' => $username, 'email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_part_of_a_username_finds_the_person_who_has_it(): void
    {
        $me = $this->person('Seeker', 'seeker', 'seeker@netvork.test');
        $harsh = $this->person('Harsh Kumar', 'harshgrapout', 'harsh@grapmail.com');
        $this->person('Someone Else', 'unrelated', 'else@netvork.test');

        // The whole point: "grapout" finds harshgrapout.
        $found = $this->actingAs($me)->getJson('/api/v1/app-id/search?q=grapout')
            ->assertOk()->json('data');
        $this->assertCount(1, $found);
        $this->assertSame('harshgrapout', $found[0]['username']);
        $this->assertSame($harsh->appId->app_id, $found[0]['app_id']);

        // The whole username still works, and so does the App ID.
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=harshgrapout')
            ->assertOk()->assertJsonPath('data.0.username', 'harshgrapout');
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=' . $harsh->appId->app_id)
            ->assertOk()->assertJsonPath('data.0.username', 'harshgrapout');

        // Everyone sharing a fragment comes back, so the reader can choose.
        $this->person('Harsh Two', 'harshgrapout2', 'two@grapmail.com');
        $this->assertCount(2, $this->actingAs($me)->getJson('/api/v1/app-id/search?q=grapout')
            ->assertOk()->json('data'));
    }

    public function test_an_address_is_matched_whole_and_never_in_part(): void
    {
        $me = $this->person('Seeker', 'seeker', 'seeker@netvork.test');
        $this->person('Harsh Kumar', 'harshgrapout', 'harsh@grapmail.com');

        // The full address finds them, as it always did.
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=harsh@grapmail.com')
            ->assertOk()->assertJsonPath('data.0.username', 'harshgrapout');

        // A fragment of a domain finds nobody: this is not an address book.
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=grapmail.com')->assertNotFound();
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=@grapmail')->assertNotFound();
    }

    public function test_a_fragment_never_reaches_further_than_a_whole_handle_would(): void
    {
        $me = $this->person('Seeker', 'seeker', 'seeker@netvork.test');
        $hidden = $this->person('Hidden Person', 'hiddengrapout', 'hidden@netvork.test');
        $hidden->settings->update(['privacy' => ['who_can_find_me' => 'nobody']]);

        // Someone who cannot be found by their whole username cannot be
        // found by part of it either.
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=hiddengrapout')->assertNotFound();
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=grapout')->assertNotFound();

        // And two letters are not a search — they would be a table dump.
        $this->person('Ab Person', 'abperson', 'ab@netvork.test');
        $this->actingAs($me)->getJson('/api/v1/app-id/search?q=ab')->assertNotFound();
    }
}
