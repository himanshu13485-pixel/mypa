<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Putting the people who can answer at the top of the address book.
 *
 * The question this list is open to answer is nearly always "who can I reach
 * right now" — you are there to press call. Past a page of connections that
 * became a scroll through everybody who had gone home, and the answer might
 * not even be on the page: it is paginated at twenty, so a sort applied in
 * the browser could only ever raise the twenty already on screen.
 *
 * The interesting part is not the ordering. It is that ordering is itself a
 * way of saying something, and somebody who has turned their online status
 * off has said they do not want it said.
 */
class ConnectionsOnlineFirstTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    /** How many rows have been written, so each gets its own second. */
    private int $written = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->me = $this->person('watcher');
    }

    private function person(string $username): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username . '@netvork.test',
            'email_verified_at' => now(),
        ]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        app(AppIdService::class)->generateFor($user);

        return $user;
    }

    /**
     * Somebody connected to me, reporting the state they are in.
     *
     * Each row is stamped a second after the one before it. The list is
     * ordered newest first, and rows written inside the same second have no
     * order at all — so without this the test would be asserting against
     * whatever the database felt like returning, and would pass or fail on
     * how quickly it ran.
     */
    private function connection(string $username, ?string $state): User
    {
        $user = $this->person($username);

        $connection = Connection::create([
            'requester_id' => $this->me->id,
            'addressee_id' => $user->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $connection->forceFill(['created_at' => now()->addSeconds($this->written++)])->save();

        if ($state) {
            $this->actingAs($user)->postJson('/api/v1/presence', ['state' => $state])->assertOk();
        }

        return $user;
    }

    /** @return list<string> names, in the order the list gives them */
    private function order(bool $onlineFirst): array
    {
        $rows = $this->actingAs($this->me)
            ->getJson('/api/v1/connections' . ($onlineFirst ? '?online_first=1' : ''))
            ->assertOk()
            ->json('data');

        return array_map(fn ($row) => $row['user']['name'], $rows);
    }

    public function test_ticking_it_raises_the_reachable_and_leaves_the_rest_in_order(): void
    {
        // Created oldest first, so the untouched list runs the other way.
        $this->connection('gone', 'offline');
        $this->connection('here', 'online');
        $this->connection('idle', 'away');
        $this->connection('alsogone', 'offline');

        $this->assertSame(['Alsogone', 'Idle', 'Here', 'Gone'], $this->order(false));

        // Online, then away, then everybody who cannot be reached — and
        // inside each group, the order they already had. The toggle changes
        // which group you are in, never where you sit within it.
        $this->assertSame(['Here', 'Idle', 'Alsogone', 'Gone'], $this->order(true));
    }

    public function test_hiding_your_status_keeps_you_out_of_the_top_group(): void
    {
        /*
         * The one that matters. A dot can be withheld and the ordering can
         * give the same answer anyway: float somebody to the top of a list
         * headed "online first" and you have said they are online as plainly
         * as a green dot would have. The setting has to survive the sort, not
         * merely the drawing.
         */
        $hidden = $this->connection('hidden', 'online');
        $hidden->settings->update(['privacy' => ['online_status_visibility' => 'nobody']]);

        $this->connection('here', 'online');
        $this->connection('gone', 'offline');

        $ranked = $this->order(true);

        $this->assertSame('Here', $ranked[0]);
        $this->assertNotSame('Hidden', $ranked[0]);
        // With the unreachable, which is what they are as far as this viewer
        // is entitled to know — and indistinguishable from them, so the
        // position gives nothing away either.
        $this->assertContains('Hidden', array_slice($ranked, 1));
    }

    public function test_the_count_is_of_everybody_and_not_of_the_page(): void
    {
        $this->connection('here', 'online');
        $this->connection('alsohere', 'online');
        $this->connection('idle', 'away');
        $this->connection('gone', 'offline');

        $count = $this->actingAs($this->me)
            ->getJson('/api/v1/connections')
            ->assertOk()
            ->json('online_count');

        // Away is not online. The toggle says how many people can answer now,
        // not how many have the app open somewhere.
        $this->assertSame(2, $count);

        $hidden = $this->connection('hidden', 'online');
        $hidden->settings->update(['privacy' => ['online_status_visibility' => 'nobody']]);

        $this->assertSame(
            2,
            $this->actingAs($this->me)->getJson('/api/v1/connections')->json('online_count'),
        );
    }

    public function test_a_request_waiting_for_an_answer_is_not_sorted_by_presence(): void
    {
        $this->connection('here', 'online');

        $asking = $this->person('asking');
        $request = Connection::create([
            'requester_id' => $asking->id,
            'addressee_id' => $this->me->id,
            'status' => 'pending',
        ]);
        // Deliberately the OLDEST row, so that leading the list is the
        // ranking's doing and not the natural newest-first order's.
        $request->forceFill(['created_at' => now()->subDay()])->save();

        $rows = $this->actingAs($this->me)
            ->getJson('/api/v1/connections?online_first=1')
            ->assertOk()
            ->json('data');

        // It leads, whatever state its sender is in. A pending request is a
        // question in a card of its own, not a person you are choosing
        // between — and sorted to the back it could fall off the first page
        // of a card that has no second one.
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('Asking', $rows[0]['user']['name']);
    }

    public function test_the_ordering_survives_a_search(): void
    {
        $this->connection('gonesmith', 'offline');
        $this->connection('heresmith', 'online');
        $this->connection('somebodyelse', 'online');

        $names = $this->actingAs($this->me)
            ->getJson('/api/v1/connections?online_first=1&q=smith')
            ->assertOk()
            ->json('data.*.user.name');

        $this->assertSame(['Heresmith', 'Gonesmith'], $names);
    }

    public function test_untouched_the_list_is_exactly_what_it_always_was(): void
    {
        $this->connection('gone', 'offline');
        $this->connection('here', 'online');

        // Newest first, presence ignored: the toggle has to be a thing you
        // ask for, not a thing that happens to you.
        $this->assertSame(['Here', 'Gone'], $this->order(false));
    }
}
