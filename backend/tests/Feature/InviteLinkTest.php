<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inviting somebody who has never heard of Netvork.
 *
 * Every other way in assumed they already had an account, so a person whose
 * colleague was not here had nothing to send them.
 */
class InviteLinkTest extends TestCase
{
    use RefreshDatabase;

    private function person(array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    public function test_a_link_is_made_the_first_time_it_is_asked_for(): void
    {
        $me = $this->person();
        $this->assertNull($me->profile->invite_code);

        $first = $this->actingAs($me)->getJson('/api/v1/invite-link')->assertOk()->json('data');

        $this->assertNotEmpty($first['code']);
        $this->assertStringEndsWith('/i/' . $first['code'], $first['url']);

        // Asking again is the same link, not a new one: a link already sent
        // to somebody must keep working.
        $again = $this->actingAs($me)->getJson('/api/v1/invite-link')->assertOk()->json('data');
        $this->assertSame($first['code'], $again['code']);
    }

    public function test_the_public_page_names_the_inviter_and_nothing_else(): void
    {
        $me = $this->person(['name' => 'Ayan', 'username' => 'ayan', 'email' => 'ayan@netvork.test']);
        $code = $this->actingAs($me)->getJson('/api/v1/invite-link')->json('data.code');

        $body = $this->getJson('/api/v1/invite/' . $code)->assertOk()->json('data');

        $this->assertSame('Ayan', $body['name']);
        $this->assertSame('ayan', $body['username']);

        // Public page, so it says as little as it can get away with.
        $this->assertArrayNotHasKey('email', $body);
        $this->assertArrayNotHasKey('app_id', $body);
        $this->assertArrayNotHasKey('mobile', $body);
    }

    public function test_a_code_nobody_owns_is_a_404(): void
    {
        $this->getJson('/api/v1/invite/abcdefghijklmnop')->assertNotFound();
    }

    public function test_signing_up_through_a_link_asks_to_connect_with_whoever_sent_it(): void
    {
        $inviter = $this->person(['name' => 'Ayan', 'username' => 'ayan']);
        $code = $this->actingAs($inviter)->getJson('/api/v1/invite-link')->json('data.code');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Newcomer',
            'username' => 'newcomer',
            'email' => 'newcomer@netvork.test',
            'password' => 'passw0rd99',
            'password_confirmation' => 'passw0rd99',
            'invite_code' => $code,
        ])->assertCreated();

        $newcomer = User::where('username', 'newcomer')->firstOrFail();

        /*
         * Pending, and from the newcomer. The code travels in a URL anybody
         * can forward, so it is evidence somebody was invited, not proof of
         * who by — the inviter's tap is the consent.
         */
        $connection = Connection::where('requester_id', $newcomer->id)
            ->where('addressee_id', $inviter->id)->first();

        $this->assertNotNull($connection);
        $this->assertSame('pending', $connection->status);
    }

    public function test_a_mistyped_code_still_lets_somebody_sign_up(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Newcomer',
            'username' => 'newcomer',
            'email' => 'newcomer@netvork.test',
            'password' => 'passw0rd99',
            'password_confirmation' => 'passw0rd99',
            'invite_code' => 'notarealcode1234',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['username' => 'newcomer']);
        $this->assertSame(0, Connection::count());
    }

    public function test_registering_with_no_code_at_all_is_unchanged(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Newcomer',
            'username' => 'newcomer',
            'email' => 'newcomer@netvork.test',
            'password' => 'passw0rd99',
            'password_confirmation' => 'passw0rd99',
        ])->assertCreated();

        $this->assertSame(0, Connection::count());
    }

    /** Two people cannot share a code, however many are handed out. */
    public function test_codes_are_unique(): void
    {
        $codes = collect(range(1, 5))->map(function () {
            $user = $this->person();

            return UserProfile::inviteCodeFor($user);
        });

        $this->assertCount(5, $codes->unique());
    }
}
