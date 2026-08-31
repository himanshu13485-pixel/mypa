<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\Connection;
use App\Models\Group;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\SocialNotification;
use App\Services\AppIdService;
use App\Support\Alerts;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Everything that happens to you now says so.
 *
 * The push transports were built long before there was much to send through
 * them: web push and FCM both worked, both read the same toPush() payload, and
 * between them they carried calls, shares and invitations. Everything else in
 * the app — a colleague editing a task you are assigned to, an expense added
 * to a ledger you co-own, a missed call, a payment, an administrator
 * suspending your account — changed the database and told nobody.
 *
 * These tests cover the two halves of fixing that. First, that each of those
 * actions reaches the person affected and nobody else, least of all whoever
 * performed it. Second, that what arrives is routed to the right category,
 * because "everything notifies" is only an improvement if a bill and a chat
 * message can still be told apart without looking.
 */
class EverythingNotifiesTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        foreach ([$this->alice, $this->bob] as $user) {
            $appIds->generateFor($user);
            $user->settings()->create([]);
            $user->profile()->create(['timezone' => 'UTC']);
        }
    }

    /** A device to push to, so wantsPush() has something to find. */
    private function giveBobAPhone(): void
    {
        PushSubscription::create([
            'user_id' => $this->bob->id,
            'endpoint' => 'https://push.example/bob',
            'endpoint_hash' => hash('sha256', 'https://push.example/bob'),
            'public_key' => 'key',
            'auth_token' => 'auth',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    // ---- Expenses ----------------------------------------------------------

    public function test_an_expense_added_to_a_shared_ledger_reaches_the_other_members(): void
    {
        Notification::fake();

        $project = Project::create([
            'user_id' => $this->alice->id,
            'name' => 'Site A',
            'purpose' => 'construction',
            'base_currency' => 'INR',
        ]);
        $project->sharedWith()->attach($this->bob->id, ['permission' => 'edit']);

        // Bob spends money that is not only his.
        $this->actingAs($this->bob)
            ->postJson("/api/v1/projects/{$project->uuid}/entries", [
                'entry_date' => now()->toDateString(),
                'description' => 'Cement 50 bags',
                'direction' => 'debit',
                'amount' => 25000,
                'currency' => 'INR',
                'mode' => 'cash',
            ])->assertCreated();

        Notification::assertSentTo($this->alice, SocialNotification::class,
            function (SocialNotification $note) {
                $this->assertSame('expense_added', $note->kind);
                $this->assertStringContainsString('Bob', $note->message);
                $this->assertStringContainsString('Cement 50 bags', $note->message);

                return true;
            });

        // Never the person who did it.
        Notification::assertNotSentTo($this->bob, SocialNotification::class);
    }

    public function test_a_ledger_nobody_shares_notifies_nobody(): void
    {
        Notification::fake();

        $project = Project::create([
            'user_id' => $this->alice->id,
            'name' => 'Private book',
            'purpose' => 'personal',
            'base_currency' => 'INR',
        ]);

        $this->actingAs($this->alice)
            ->postJson("/api/v1/projects/{$project->uuid}/entries", [
                'entry_date' => now()->toDateString(),
                'description' => 'Groceries',
                'direction' => 'debit',
                'amount' => 900,
            ])->assertCreated();

        Notification::assertNothingSent();
    }

    // ---- Tasks -------------------------------------------------------------

    public function test_finishing_a_shared_task_tells_the_person_who_owns_it(): void
    {
        Notification::fake();

        $task = Task::create([
            'user_id' => $this->alice->id,
            'title' => 'Pour the slab',
            'status' => 'in_progress',
        ]);
        $task->assignees()->attach($this->bob->id, ['assigned_by' => $this->alice->id]);

        $this->actingAs($this->bob)
            ->postJson("/api/v1/tasks/{$task->uuid}/status", ['status' => 'completed'])
            ->assertOk();

        Notification::assertSentTo($this->alice, SocialNotification::class,
            function (SocialNotification $note) {
                $this->assertSame('task_completed', $note->kind);
                $this->assertStringContainsString('Bob completed', $note->message);

                return true;
            });
        Notification::assertNotSentTo($this->bob, SocialNotification::class);
    }

    public function test_a_comment_carries_its_own_tag_so_a_thread_does_not_collapse(): void
    {
        Notification::fake();

        $task = Task::create(['user_id' => $this->alice->id, 'title' => 'Pour the slab']);
        $task->assignees()->attach($this->bob->id, ['assigned_by' => $this->alice->id]);

        $this->actingAs($this->bob)
            ->postJson("/api/v1/tasks/{$task->uuid}/comments", ['body' => 'Waiting on the mixer'])
            ->assertCreated();

        Notification::assertSentTo($this->alice, SocialNotification::class,
            function (SocialNotification $note) {
                $this->assertSame('task_comment', $note->kind);
                $this->assertStringContainsString('Waiting on the mixer', $note->message);
                // Per comment, not per task: a reply must not overwrite the
                // remark it is replying to.
                $this->assertStringStartsWith('task-comment-', (string) $note->pushTag);

                return true;
            });
    }

    public function test_a_task_nobody_else_is_on_stays_quiet(): void
    {
        Notification::fake();

        $task = Task::create(['user_id' => $this->alice->id, 'title' => 'Buy milk']);

        $this->actingAs($this->alice)
            ->postJson("/api/v1/tasks/{$task->uuid}/status", ['status' => 'completed'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    // ---- Groups ------------------------------------------------------------

    public function test_being_removed_from_a_group_is_worth_hearing(): void
    {
        Notification::fake();

        $group = $this->aGroup();

        $this->actingAs($this->alice)
            ->deleteJson("/api/v1/groups/{$group->uuid}/members/{$this->bob->uuid}")
            ->assertOk();

        Notification::assertSentTo($this->bob, SocialNotification::class,
            fn (SocialNotification $note) => $note->kind === 'group_removed');
    }

    public function test_leaving_a_group_does_not_notify_the_person_who_left(): void
    {
        Notification::fake();

        $group = $this->aGroup();

        $this->actingAs($this->bob)
            ->deleteJson("/api/v1/groups/{$group->uuid}/members/{$this->bob->uuid}")
            ->assertOk();

        Notification::assertNothingSent();
    }

    private function aGroup(): Group
    {
        $group = Group::create([
            'owner_id' => $this->alice->id,
            'name' => 'Site crew',
            'type' => 'team',
        ]);
        $group->members()->attach($this->alice->id, ['role' => 'admin', 'added_by' => $this->alice->id]);
        $group->members()->attach($this->bob->id, ['role' => 'member', 'added_by' => $this->alice->id]);

        return $group;
    }

    // ---- Routing -----------------------------------------------------------

    public function test_each_category_lands_on_its_own_android_channel(): void
    {
        $cases = [
            'message' => 'messages_v1',
            'missed_call' => 'messages_v1',
            'task_reminder' => 'reminders_v1',
            'event_reminder' => 'reminders_v1',
            'expense_added' => 'money_v1',
            'bill_due' => 'money_v1',
            'payment_failed' => 'money_v1',
            'task_assigned' => 'social_v1',
            'account_suspended' => 'system_v1',
        ];

        foreach ($cases as $kind => $channel) {
            $payload = (new SocialNotification($kind, 'Something happened'))->toPush($this->bob);
            $this->assertSame($channel, $payload['channel'], "{$kind} should ring on {$channel}");
            $this->assertSame($kind, $payload['kind']);
        }
    }

    public function test_a_kind_nobody_has_categorised_still_notifies(): void
    {
        // The fallback matters more than the map: a new kind added by somebody
        // who never reads Alerts must still reach the phone, just quietly.
        $payload = (new SocialNotification('something_brand_new', 'Hello'))->toPush($this->bob);

        $this->assertSame('social_v1', $payload['channel']);
        $this->assertSame('social', Alerts::categoryOf('something_brand_new'));
    }

    public function test_time_bound_alerts_are_urgent_and_short_lived(): void
    {
        // Normal urgency is held by Android until the next maintenance
        // window, which is how a 9am reminder becomes an 11am one.
        $this->assertSame('high', Alerts::optionsOf('task_reminder')['urgency']);
        $this->assertSame(3600, Alerts::optionsOf('task_reminder')['TTL']);

        // An administrative decision is not urgent, but it is worth keeping
        // for a phone that has been off all weekend.
        $this->assertSame('normal', Alerts::optionsOf('account_suspended')['urgency']);
        $this->assertGreaterThan(86400, Alerts::optionsOf('account_suspended')['TTL']);
    }

    public function test_a_title_says_something_before_the_body_is_read(): void
    {
        // Every push used to carry the same title, so the bold half of every
        // lock-screen alert said nothing at all.
        $this->assertSame('New message', (new SocialNotification('message', 'x'))->toPush($this->bob)['title']);
        $this->assertSame('Reminder', (new SocialNotification('task_reminder', 'x'))->toPush($this->bob)['title']);
        $this->assertSame('Money', (new SocialNotification('bill_due', 'x'))->toPush($this->bob)['title']);
    }

    // ---- Preferences -------------------------------------------------------

    public function test_turning_push_off_leaves_the_bell_working(): void
    {
        $this->giveBobAPhone();
        $this->bob->settings->update(['notification_preferences' => ['push' => false]]);

        $via = (new SocialNotification('expense_added', 'x'))->via($this->bob->fresh());

        $this->assertContains('database', $via);
        $this->assertNotContains(WebPushChannel::class, $via);
        $this->assertNotContains(FcmChannel::class, $via);
    }

    public function test_a_device_that_exists_is_pushed_to(): void
    {
        $this->giveBobAPhone();

        $via = (new SocialNotification('expense_added', 'x'))->via($this->bob->fresh());

        $this->assertContains(WebPushChannel::class, $via);
        $this->assertContains(FcmChannel::class, $via);
    }

    public function test_the_noisy_kinds_never_become_email(): void
    {
        // A shared ledger having a busy afternoon must not arrive as forty
        // emails. These still reach the bell and the phone.
        $this->bob->forceFill(['email_verified_at' => now()])->save();

        foreach (['message', 'expense_added', 'task_comment', 'missed_call'] as $kind) {
            $this->assertNotContains('mail', (new SocialNotification($kind, 'x'))->via($this->bob->fresh()),
                "{$kind} should never be emailed");
        }

        // Something rare still is.
        $this->assertContains('mail', (new SocialNotification('project_shared', 'x'))->via($this->bob->fresh()));
    }
    // ---- Missed calls ------------------------------------------------------

    public function test_a_call_nobody_answered_leaves_word(): void
    {
        Notification::fake();

        // A direct conversation needs the two of them connected: the
        // default privacy setting is that only connections may message.
        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        $conversation = $this->actingAs($this->alice)
            ->postJson('/api/v1/conversations', ['app_id' => $this->bob->appId->app_id])
            ->assertCreated()->json('data.uuid');

        $uuid = $this->actingAs($this->alice)
            ->postJson("/api/v1/conversations/{$conversation}/calls", ['type' => 'audio'])
            ->assertCreated()->json('data.uuid');

        // Alice gives up before Bob picks up.
        $this->actingAs($this->alice)
            ->postJson("/api/v1/calls/{$uuid}/end")
            ->assertOk();

        $this->assertSame('missed', Call::where('uuid', $uuid)->firstOrFail()->status);

        /*
         * The ring itself carries a 45-second TTL, so a phone that was off is
         * never told about it. This is the part that survives: somebody tried
         * to reach you, and here is who.
         */
        Notification::assertSentTo($this->bob, SocialNotification::class,
            function (SocialNotification $note) {
                $this->assertSame('missed_call', $note->kind);
                $this->assertStringContainsString('Missed call from Alice', $note->message);

                return true;
            });

        // The caller knows perfectly well what happened.
        Notification::assertNotSentTo($this->alice, SocialNotification::class);
    }
    public function test_an_edit_names_what_changed_in_english(): void
    {
        Notification::fake();

        $task = Task::create(['user_id' => $this->alice->id, 'title' => 'Pour the slab']);
        $task->assignees()->attach($this->bob->id, ['assigned_by' => $this->alice->id]);

        $this->actingAs($this->alice)
            ->putJson("/api/v1/tasks/{$task->uuid}", [
                'title' => 'Pour the slab',
                'due_at' => now()->addDay()->toIso8601String(),
                'priority' => 'high',
            ])->assertOk();

        Notification::assertSentTo($this->bob, SocialNotification::class,
            function (SocialNotification $note) {
                // Column names do not survive being shown to people: this
                // read "changed the due on" before the field names were
                // spelled out.
                $this->assertStringContainsString('due date', $note->message);
                $this->assertStringNotContainsString('due_at', $note->message);

                return true;
            });
    }
}
