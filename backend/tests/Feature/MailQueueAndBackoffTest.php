<?php

namespace Tests\Feature;

use App\Jobs\BackupMailAccount;
use App\Jobs\MailRemoteChange;
use App\Jobs\SendMailMessage;
use App\Jobs\SyncMailAccount;
use App\Models\Crm\MailAccount;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailSync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;

/** A mail server that is never there: every sync fails, which is the point. */
class NeverAnsweringMailConnector extends MailConnector
{
    public function imap(MailAccount $account): Client
    {
        throw new RuntimeException('Connection refused.');
    }
}

/**
 * Mail waits its own turn, and a mailbox that cannot be reached is asked
 * less often.
 *
 * Both are about one thing: a queue worker does one job at a time. Talking
 * to somebody else's IMAP server takes seconds at best and can take
 * minutes, so on the queue everything else uses, ten mailboxes syncing put
 * themselves in front of outgoing mail, push notifications and chat - and
 * one unreachable mailbox held the lot until the worker was killed.
 */
class MailQueueAndBackoffTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(MailConnector::class, new NeverAnsweringMailConnector());

        $this->org = Organization::create([
            'name' => 'Grapout', 'code' => 'GRAP', 'status' => 'active', 'mails_enabled' => true, 'mails_mailbox_cap' => 3,
        ]);
        $this->user = User::factory()->create(['email' => 'boss@grapout.test']);
        $this->user->settings()->create([]);
        $this->user->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->member = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->user->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);
    }

    private function mailbox(array $extra = []): MailAccount
    {
        return MailAccount::create($extra + [
            'organization_id' => $this->org->id, 'member_id' => $this->member->id, 'email' => 'accounts@grapout.test',
            'from_name' => 'Grapout Accounts', 'provider' => 'custom', 'status' => 'active',
            'smtp_host' => 'smtp.grapout.test', 'smtp_password' => 'secret',
            'imap_host' => 'imap.grapout.test', 'imap_password' => 'secret',
        ]);
    }

    // ---- The mail queue --------------------------------------------------------

    public function test_the_slow_imap_work_queues_away_from_everything_else(): void
    {
        Queue::fake();
        $account = $this->mailbox();

        SyncMailAccount::dispatch($account->id);
        BackupMailAccount::dispatch($account->id);
        MailRemoteChange::dispatch($account->id, 'INBOX', 7, 'seen');

        foreach ([SyncMailAccount::class, BackupMailAccount::class, MailRemoteChange::class] as $job) {
            Queue::assertPushed($job, fn ($queued) => $queued->queue === 'mail');
        }
    }

    public function test_outgoing_mail_is_not_stuck_behind_it(): void
    {
        Queue::fake();

        SendMailMessage::dispatch(1);

        // Anything but the mail queue: a send must never wait on IMAP.
        Queue::assertPushed(SendMailMessage::class, fn ($queued) => $queued->queue !== 'mail');
    }

    // ---- Being left alone ------------------------------------------------------

    public function test_a_mailbox_is_forgiven_twice_then_rested_for_longer_and_longer(): void
    {
        $account = $this->mailbox();

        // A mail server having a bad minute is not a broken mailbox.
        $account->noteSyncFailure();
        $account->noteSyncFailure();
        $this->assertFalse($account->fresh()->syncIsPaused());

        $account->noteSyncFailure();
        $this->assertTrue($account->fresh()->syncIsPaused());
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($account->fresh()->sync_paused_until), 1);

        $account->noteSyncFailure();
        $account->noteSyncFailure();
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($account->fresh()->sync_paused_until), 1);
    }

    public function test_one_good_sync_wipes_the_slate(): void
    {
        $account = $this->mailbox(['sync_failures' => 4, 'sync_paused_until' => now()->addHour()]);

        $account->noteSyncSuccess();

        $this->assertSame(0, $account->fresh()->sync_failures);
        $this->assertNull($account->fresh()->sync_paused_until);
        $this->assertFalse($account->fresh()->syncIsPaused());
    }

    public function test_a_failing_sync_counts_itself_without_crying_wolf(): void
    {
        // The connector above refuses every connection, so the sync throws.
        // A refused connection is weather, so it is counted and not
        // announced - the mailbox is not called broken over one bad minute.
        $account = $this->mailbox();

        app(MailSync::class)->sync($account);

        $this->assertSame(1, $account->fresh()->sync_failures);
        $this->assertNull($account->fresh()->last_error);
        $this->assertFalse($account->fresh()->syncIsPaused());
    }

    public function test_the_pass_that_finally_says_problem_is_the_pass_that_rests_it(): void
    {
        $account = $this->mailbox();

        // Three passes running is no longer a blip: it is said out loud,
        // and the mailbox stops being asked every five minutes.
        for ($pass = 0; $pass < MailSync::COMPLAIN_AFTER; $pass++) {
            app(MailSync::class)->sync($account->fresh());
        }

        $account = $account->fresh();
        $this->assertSame(MailSync::COMPLAIN_AFTER, $account->sync_failures);
        $this->assertNotNull($account->last_error);
        $this->assertTrue($account->syncIsPaused());
    }

    public function test_the_five_minute_tick_passes_a_rested_mailbox_by(): void
    {
        Queue::fake();
        $this->mailbox(['sync_failures' => 3, 'sync_paused_until' => now()->addMinutes(10)]);

        Artisan::call('mails:tick sync');

        Queue::assertNotPushed(SyncMailAccount::class);
    }

    public function test_and_picks_it_up_again_once_the_rest_is_over(): void
    {
        Queue::fake();
        $this->mailbox(['sync_failures' => 3, 'sync_paused_until' => now()->subMinute()]);

        Artisan::call('mails:tick sync');

        Queue::assertPushed(SyncMailAccount::class);
    }

    public function test_pressing_refresh_says_something_changed_and_wakes_it_now(): void
    {
        Queue::fake();
        $account = $this->mailbox(['sync_failures' => 6, 'sync_paused_until' => now()->addHours(6)]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/crm/mails/accounts/{$account->uuid}/sync")
            ->assertOk();

        $this->assertSame(0, $account->fresh()->sync_failures);
        $this->assertNull($account->fresh()->sync_paused_until);
        Queue::assertPushed(SyncMailAccount::class);
    }
}
