<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closing an account for good.
 *
 * There was no way to do this at all — required by app stores, and a right
 * under India's DPDP Act. The behaviour worth pinning down is that it is
 * genuinely destructive rather than a hidden flag, that it cannot be done by
 * accident, and that it does not take other people's data with it.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $this->user = User::factory()->create(['password' => bcrypt('correct-horse')]);
        app(AppIdService::class)->generateFor($this->user);
        $this->user->settings()->create([]);
        $this->user->profile()->create(['timezone' => 'UTC']);
    }

    public function test_an_account_and_its_things_are_actually_gone(): void
    {
        Task::create([
            'user_id' => $this->user->id,
            'title' => 'Something private',
            'priority' => 'normal',
            'status' => 'not_started',
        ]);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me', ['confirm' => 'DELETE', 'password' => 'correct-horse'])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseMissing('tasks', ['title' => 'Something private']);
        $this->assertSame(0, Task::withoutGlobalScopes()->where('user_id', $this->user->id)->count());
    }

    public function test_the_word_and_the_password_are_both_required(): void
    {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me', ['confirm' => 'delete', 'password' => 'correct-horse'])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me', ['confirm' => 'DELETE', 'password' => 'wrong'])
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_a_meeting_you_are_hosting_has_to_end_first(): void
    {
        Meeting::create([
            'code' => 'del-etem-tng',
            'host_id' => $this->user->id,
            'type' => 'video',
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me', ['confirm' => 'DELETE', 'password' => 'correct-horse'])
            ->assertStatus(409);

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_the_session_stops_working_immediately(): void
    {
        $token = $this->user->createToken('phone')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson('/api/v1/me', ['confirm' => 'DELETE', 'password' => 'correct-horse'])
            ->assertOk();

        /*
         * Both requests run through one application here, and the guard keeps
         * whoever it resolved the first time — so the second never looked at
         * the token, and a revoked session read as one that outlived its
         * account. Ask for the guard afresh, as a second request to a real
         * server does.
         */
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/tasks')
            ->assertUnauthorized();
    }

    public function test_what_was_said_to_other_people_stays_with_them(): void
    {
        $friend = User::factory()->create(['name' => 'Friend']);
        app(AppIdService::class)->generateFor($friend);
        $friend->settings()->create([]);
        \App\Models\Connection::create([
            'requester_id' => $this->user->id,
            'addressee_id' => $friend->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $conversation = $this->actingAs($this->user)
            ->postJson('/api/v1/conversations', ['app_id' => $friend->appId->app_id])
            ->json('data.uuid');
        $this->actingAs($this->user)
            ->postJson("/api/v1/conversations/{$conversation}/messages", ['body' => 'Said out loud'])
            ->assertCreated();

        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me', ['confirm' => 'DELETE', 'password' => 'correct-horse'])
            ->assertOk();

        /*
         * The account is gone and the message is not. It used to be one or the
         * other: the foreign key cascaded, so deleting the account for real
         * would have emptied half of somebody else's conversation, and the
         * soft delete that avoided it deleted nothing whatsoever.
         */
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);

        $this->app['auth']->forgetGuards();
        $messages = $this->actingAs($friend->fresh())
            ->getJson("/api/v1/conversations/{$conversation}/messages")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $messages);
        $this->assertSame('Said out loud', $messages[0]['body']);
        $this->assertNull($messages[0]['sender']);
    }

    public function test_the_deletion_is_recorded_without_recording_the_data(): void
    {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/me', ['confirm' => 'DELETE', 'password' => 'correct-horse'])
            ->assertOk();

        $log = AuditLog::where('action', 'account.deleted')->firstOrFail();
        $this->assertNull($log->actor_id, 'the actor no longer exists');
        $this->assertSame($this->user->uuid, $log->details['user_uuid']);
    }

    public function test_a_stranger_cannot_delete_somebody_else(): void
    {
        $this->deleteJson('/api/v1/me', ['confirm' => 'DELETE', 'password' => 'correct-horse'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }
}
