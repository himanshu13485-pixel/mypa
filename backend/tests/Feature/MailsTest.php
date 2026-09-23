<?php

namespace Tests\Feature;

use App\Jobs\SendMailMessage;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailHtml;
use App\Services\Mail\MailSender;
use App\Services\Mail\MailSync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;

/** A mail server that never leaves the process: SMTP to the array mailer, no IMAP at all. */
class FakeMailConnector extends MailConnector
{
    public function smtp(MailAccount $account): Mailer
    {
        return Mail::mailer('array');
    }

    public function imap(MailAccount $account): Client
    {
        throw new RuntimeException('No IMAP in tests.');
    }
}

/**
 * Mails: a mail client inside the CRM, behind three doors - the platform,
 * the company's Admin, and the person's own mailboxes.
 */
class MailsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private Member $admin;
    private User $staffUser;
    private Member $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(MailConnector::class, new FakeMailConnector());

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP', 'status' => 'active', 'mails_enabled' => true, 'mails_mailbox_cap' => 3]);
        [$this->adminUser, $this->admin] = $this->person('boss@grapout.test', 'admin');
        [$this->staffUser, $this->staff] = $this->person('harsh@grapout.test', 'employee');
    }

    private function person(string $email, string $role): array
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        $member = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => 'active',
        ]);

        return [$user, $member];
    }

    private function mailbox(Member $owner, array $extra = []): MailAccount
    {
        return MailAccount::create($extra + [
            'organization_id' => $this->org->id, 'member_id' => $owner->id, 'email' => 'accounts@grapout.test',
            'from_name' => 'Grapout Accounts', 'provider' => 'custom',
            'smtp_host' => 'smtp.grapout.test', 'smtp_password' => 'secret',
            'imap_host' => 'imap.grapout.test', 'imap_password' => 'secret',
        ]);
    }

    private function sent(): \Illuminate\Support\Collection
    {
        return collect(Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    // ---- The three doors -------------------------------------------------------

    public function test_nobody_gets_in_until_the_platform_switches_mails_on(): void
    {
        $this->org->update(['mails_enabled' => false]);

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/dashboard')
            ->assertStatus(403)->assertJsonPath('message', 'Mails is not switched on for this company.');
    }

    public function test_the_admin_has_it_and_staff_only_once_given_it(): void
    {
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/dashboard')->assertOk();
        $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/dashboard')->assertStatus(403);

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", ['enabled' => true, 'limit' => 2])->assertOk();
        $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/dashboard')->assertOk()->assertJsonPath('data.limit', 2);

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", ['enabled' => false])->assertOk();
        $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/dashboard')->assertStatus(403);
    }

    public function test_only_the_company_admin_hands_mails_out(): void
    {
        $this->staff->update(['rights' => ['mails' => ['view']]]);

        $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/settings/team')->assertStatus(403);
    }

    public function test_the_sidebar_is_told_whether_to_show_mails(): void
    {
        $this->actingAs($this->staffUser)->getJson('/api/v1/crm/me')
            ->assertJsonPath('data.mails.org_enabled', true)
            ->assertJsonPath('data.mails.allowed', false);
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/me')->assertJsonPath('data.mails.allowed', true);
    }

    public function test_the_platform_switches_mails_and_sets_the_cap(): void
    {
        $super = User::factory()->create();
        $super->roles()->attach(Role::where('slug', 'super_admin')->first()->id);

        $this->actingAs($super)->putJson("/api/v1/admin/crm/organizations/{$this->org->uuid}", [
            'mails_enabled' => false, 'mails_mailbox_cap' => 5,
        ])->assertOk();

        $this->org->refresh();
        $this->assertFalse($this->org->mails_enabled);
        $this->assertSame(5, $this->org->mails_mailbox_cap);
    }

    // ---- How many mailboxes ------------------------------------------------------

    public function test_a_person_cannot_add_more_mailboxes_than_they_are_allowed(): void
    {
        Queue::fake();
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", ['enabled' => true, 'limit' => 1]);

        $add = fn (string $email) => $this->actingAs($this->staffUser)->postJson('/api/v1/crm/mails/accounts', [
            'email' => $email, 'provider' => 'custom', 'smtp_host' => 'smtp.x.test', 'smtp_password' => 'p',
        ]);

        $add('one@grapout.test')->assertCreated()->assertJsonPath('data.is_default', true);
        $add('two@grapout.test')->assertStatus(422);
    }

    public function test_the_admin_cannot_allow_more_than_the_platform_cap(): void
    {
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", ['enabled' => true, 'limit' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    public function test_passwords_never_come_back_out(): void
    {
        $this->mailbox($this->admin);

        $row = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/accounts')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('smtp_password', $row);
        $this->assertTrue($row['has_smtp_password']);
    }

    // ---- Sending ---------------------------------------------------------------------

    public function test_sent_mail_waits_in_the_outbox_and_can_be_pulled_back(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);

        $sent = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/compose', [
            'action' => 'send', 'account' => $box->uuid, 'to' => ['Priyanshu <priya@client.test>'],
            'subject' => 'Proposal', 'body_html' => '<p>Please find it attached.</p>',
        ])->assertOk()->json('data');

        $this->assertSame('outbox', $sent['folder']);
        $this->assertSame('queued', $sent['status']);
        Queue::assertPushed(SendMailMessage::class);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/messages/{$sent['uuid']}/cancel")
            ->assertOk()->assertJsonPath('data.folder', 'drafts');
    }

    public function test_once_the_window_passes_it_goes_through_the_mailbox_and_lands_in_sent(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/compose', [
            'action' => 'send', 'account' => $box->uuid, 'to' => ['priya@client.test'], 'cc' => ['ops@client.test'],
            'subject' => 'Proposal', 'body_html' => '<p>Hello</p>',
        ])->json('data.uuid');
        $message = MailMessage::where('uuid', $uuid)->first();

        $this->travel(15)->seconds();
        (new SendMailMessage($message->id))->handle(app(MailSender::class));

        $message->refresh();
        $this->assertSame('sent', $message->folder);
        $this->assertSame('sent', $message->status);
        $this->assertNotNull($message->message_id);

        $email = $this->sent()->last()->getOriginalMessage();
        $this->assertSame('Proposal', $email->getSubject());
        $this->assertSame('priya@client.test', $email->getTo()[0]->getAddress());
        $this->assertSame('ops@client.test', $email->getCc()[0]->getAddress());
        $this->assertSame('accounts@grapout.test', $email->getFrom()[0]->getAddress());
    }

    public function test_it_is_sent_once_even_if_the_job_and_the_sweep_both_run(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/compose', [
            'action' => 'send', 'account' => $box->uuid, 'to' => ['priya@client.test'], 'subject' => 'Once', 'body_html' => 'x',
        ])->json('data.uuid');
        $id = MailMessage::where('uuid', $uuid)->value('id');

        $this->travel(15)->seconds();
        (new SendMailMessage($id))->handle(app(MailSender::class));
        (new SendMailMessage($id))->handle(app(MailSender::class));

        $this->assertCount(1, $this->sent());
    }

    public function test_an_undo_window_of_nothing_sends_straight_away(): void
    {
        Queue::fake();
        $this->admin->update(['mail_prefs' => ['undo_seconds' => 0]]);
        $box = $this->mailbox($this->admin);
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/compose', [
            'action' => 'send', 'account' => $box->uuid, 'to' => ['priya@client.test'], 'subject' => 'Now', 'body_html' => 'x',
        ])->assertOk()->json('data.uuid');

        (new SendMailMessage(MailMessage::where('uuid', $uuid)->value('id')))->handle(app(MailSender::class));

        $this->assertSame('sent', MailMessage::where('uuid', $uuid)->value('folder'));
    }

    public function test_scheduled_mail_waits_for_its_time(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/compose', [
            'action' => 'schedule', 'account' => $box->uuid, 'to' => ['priya@client.test'], 'subject' => 'Monday',
            'body_html' => 'x', 'scheduled_for' => now()->addDay()->toIso8601String(),
        ])->assertOk()->json('data.uuid');
        $id = MailMessage::where('uuid', $uuid)->value('id');

        (new SendMailMessage($id))->handle(app(MailSender::class));
        $this->assertSame('scheduled', MailMessage::find($id)->folder);

        $this->travel(25)->hours();
        $this->artisan('mails:tick dispatch')->assertSuccessful();
        (new SendMailMessage($id))->handle(app(MailSender::class));
        $this->assertSame('sent', MailMessage::find($id)->folder);
    }

    public function test_a_reply_joins_the_conversation_it_answers(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $original = MailMessage::create([
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id, 'folder' => 'inbox',
            'message_id' => '<abc@client.test>', 'thread_key' => MailMessage::threadKeyFor(null, null, '<abc@client.test>', 'Rates'),
            'from_email' => 'priya@client.test', 'subject' => 'Rates', 'body_text' => 'What are your rates?', 'date' => now(),
        ]);

        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/compose', [
            'action' => 'send', 'account' => $box->uuid, 'to' => ['priya@client.test'], 'subject' => 'Re: Rates',
            'body_html' => 'Here they are.', 'reply_to_uuid' => $original->uuid,
        ])->json('data.uuid');
        $reply = MailMessage::where('uuid', $uuid)->first();

        $this->assertSame('<abc@client.test>', $reply->in_reply_to);
        $this->assertSame($original->thread_key, $reply->thread_key);

        $this->travel(15)->seconds();
        (new SendMailMessage($reply->id))->handle(app(MailSender::class));
        $headers = $this->sent()->last()->getOriginalMessage()->getHeaders();
        $this->assertStringContainsString('abc@client.test', $headers->get('In-Reply-To')->getBodyAsString());
    }

    public function test_a_recipient_that_is_not_an_address_is_refused(): void
    {
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/compose', [
            'action' => 'send', 'account' => $box->uuid, 'to' => ['priya at client'], 'subject' => 'x', 'body_html' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('to');
    }

    // ---- Company mailboxes, shared and looked after ---------------------------------

    private function giveStaffMails(): void
    {
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", ['enabled' => true])->assertOk();
    }

    public function test_a_user_may_edit_their_mailbox_but_never_delete_or_share_it(): void
    {
        Queue::fake();
        $this->giveStaffMails();
        $box = $this->mailbox($this->staff);

        $this->actingAs($this->staffUser)->putJson("/api/v1/crm/mails/accounts/{$box->uuid}", ['from_name' => 'Harsh'])->assertOk();
        $this->assertSame('Harsh', $box->fresh()->from_name);

        // Sharing and the daily cap are the Admin's alone - quietly ignored.
        $this->actingAs($this->staffUser)->putJson("/api/v1/crm/mails/accounts/{$box->uuid}", [
            'daily_cap' => 5, 'tag' => 'mine', 'shared_with' => [$this->admin->uuid],
        ])->assertOk();
        $this->assertNull($box->fresh()->daily_cap);
        $this->assertSame(0, $box->fresh()->sharedMembers()->count());

        $this->actingAs($this->staffUser)->deleteJson("/api/v1/crm/mails/accounts/{$box->uuid}")->assertForbidden();
        $this->assertNotNull($box->fresh());
    }

    public function test_disconnecting_a_mailbox_keeps_its_mail_and_a_new_account_takes_its_place(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $mail = $this->arrived($box, 'Last year');

        $this->actingAs($this->adminUser)->deleteJson("/api/v1/crm/mails/accounts/{$box->uuid}")->assertOk();

        $box->refresh();
        $this->assertNotNull($box->detached_at);
        $this->assertFalse($box->canSend());
        $this->assertNotNull($mail->fresh(), 'the mail stays');
        $this->actingAs($this->adminUser)->getJson("/api/v1/crm/mails/messages/{$mail->uuid}")->assertOk();

        // Give it credentials again: same mailbox, same mail, connected once more.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/accounts/{$box->uuid}", [
            'imap_password' => 'new-secret', 'smtp_password' => 'new-secret',
        ])->assertOk();
        $this->assertNull($box->fresh()->detached_at);
        $this->assertNotNull($mail->fresh());
    }

    public function test_only_an_admin_purges_a_mailbox_and_only_by_naming_it(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $mail = $this->arrived($box, 'Gone');

        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/crm/mails/accounts/{$box->uuid}", ['purge' => true, 'confirm' => 'wrong@x.test'])
            ->assertStatus(422);
        $this->assertNotNull($mail->fresh());

        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/crm/mails/accounts/{$box->uuid}", ['purge' => true, 'confirm' => $box->email])
            ->assertOk();
        $this->assertNull($mail->fresh());
    }

    public function test_a_daily_limit_holds_mail_back_until_tomorrow(): void
    {
        $box = $this->mailbox($this->admin, ['daily_cap' => 1]);

        $this->assertTrue($box->claimSend());
        $this->assertFalse($box->fresh()->claimSend(), 'the second one is over the limit');
        $this->assertSame(0, $box->fresh()->sendsLeft());

        $message = MailMessage::create([
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id, 'folder' => 'outbox',
            'status' => 'queued', 'to' => [['email' => 'them@client.test']], 'subject' => 'Over the line',
            'body_html' => '<p>hi</p>', 'thread_key' => 'x', 'send_after' => now()->subMinute(),
        ]);

        (new \App\Jobs\SendMailMessage($message->id))->handle(app(\App\Services\Mail\MailSender::class));

        $message->refresh();
        $this->assertSame('queued', $message->status);
        $this->assertSame('outbox', $message->folder);
        $this->assertTrue($message->send_after->isFuture(), 'it waits for tomorrow rather than failing');
        $this->assertStringContainsString('sends for today', (string) $message->error);
    }

    public function test_the_dns_check_scores_what_the_world_can_see(): void
    {
        $box = $this->mailbox($this->admin, ['email' => 'hello@grapout.test']);

        // A resolver that answers from a script, so no test asks the internet.
        $this->app->instance(\App\Services\Mail\MailDns::class, new class extends \App\Services\Mail\MailDns
        {
            public function txt(string $name): array
            {
                return match ($name) {
                    'grapout.test' => ['v=spf1 include:_spf.google.com ~all'],
                    'google._domainkey.grapout.test' => ['v=DKIM1; k=rsa; p=MIGfMA0G'],
                    '_dmarc.grapout.test' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@grapout.test'],
                    default => [],
                };
            }
        });

        $data = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/dns")
            ->assertOk()->json('data');

        $this->assertTrue($data['spf']['ok']);
        $this->assertTrue($data['dkim']['ok']);
        $this->assertSame('google', $data['dkim']['selector']);
        $this->assertSame('quarantine', $data['dmarc']['policy']);
        $this->assertSame(100, $data['score']);
        $this->assertSame('google', $box->fresh()->dkim_selector);
    }

    public function test_replicating_a_mailbox_copies_its_servers_under_a_new_address(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin, ['daily_cap' => 50]);

        $copy = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/replicate", [
            'email' => 'support@grapout.test', 'label' => 'Support',
        ])->assertCreated()->json('data');

        $this->assertSame('support@grapout.test', $copy['email']);
        $this->assertSame($box->smtp_host, $copy['smtp_host']);
        $this->assertSame(50, $copy['daily_cap']);
        $this->assertFalse($copy['is_default'], 'a copy never takes over as the default');

        // A user cannot copy a mailbox that is not theirs - they cannot even
        // see it.
        $this->giveStaffMails();
        $this->actingAs($this->staffUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/replicate", [
            'email' => 'nope@grapout.test',
        ])->assertNotFound();
    }

    public function test_a_server_error_reaches_the_screen_in_plain_words(): void
    {
        $raw = new \RuntimeException(
            "stream_socket_client(): [<a href='/phpmanual/function.stream-socket-client.html'>function.stream-socket-client.html</a>]: "
            . 'Unable to connect to ssl://zma.app:993 (A connection attempt failed because the connected party did not properly respond)'
        );

        $plain = \App\Services\Mail\MailConnector::plain($raw);
        $this->assertStringNotContainsString('<a href', $plain, 'no HTML reaches the screen');
        $this->assertStringNotContainsString('phpmanual', $plain);
        $this->assertStringNotContainsString('stream_socket_client', $plain);
        $this->assertStringContainsString('Unable to connect to ssl://zma.app:993', $plain);

        // Nothing there: say which host and port, and where people usually look.
        $timeout = \App\Services\Mail\MailConnector::explain($raw, 'zma.app', 993, 'incoming');
        $this->assertStringContainsString('Nothing answered on zma.app:993', $timeout);
        $this->assertStringContainsString('mail.yourdomain.com', $timeout);

        // A name that does not exist, and the SES spelling everybody gets wrong.
        $unknown = new \RuntimeException('php_network_getaddresses: getaddrinfo for smtp.us-east-1.amazonaws.com failed: No such host');
        $advice = \App\Services\Mail\MailConnector::explain($unknown, 'smtp.us-east-1.amazonaws.com', 465, 'outgoing');
        $this->assertStringContainsString('email-smtp.us-east-1.amazonaws.com', $advice);

        $refused = new \RuntimeException('535 5.7.8 Authentication credentials invalid');
        $this->assertStringContainsString('app password', \App\Services\Mail\MailConnector::explain($refused, 'smtp.gmail.com', 587, 'outgoing'));
    }

    public function test_a_mailbox_can_be_told_to_accept_its_hosts_certificate(): void
    {
        $box = $this->mailbox($this->admin);
        $this->assertTrue($box->fresh()->verify_cert, 'strict by default');

        $cert = new \RuntimeException("Peer certificate CN=`*.web-hosting.com' did not match expected CN=`mail.zma.app'");
        $advice = \App\Services\Mail\MailConnector::explain($cert, 'mail.zma.app', 993, 'incoming');
        $this->assertStringContainsString('it is for *.web-hosting.com', $advice);
        $this->assertStringContainsString('web-hosting.com, which cPanel shows', $advice, 'a wildcard is not offered as a host to dial');
        $this->assertStringContainsString("Accept this server's certificate", $advice);

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/accounts/{$box->uuid}", ['verify_cert' => false])
            ->assertOk()->assertJsonPath('data.verify_cert', false);
        $this->assertFalse($box->fresh()->verify_cert);
    }

    public function test_a_failed_check_answers_with_the_reason_not_a_status_code(): void
    {
        // The fake connector refuses IMAP, which is what a real one does when
        // the server is wrong - and the screen must get words, not a 422.
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/inbox-test")
            ->assertOk()
            ->assertJsonPath('data.ok', false);

        $said = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/inbox-test")->json('data.message');
        $this->assertNotEmpty($said);
        $this->assertStringContainsString('Could not read the mailbox', $said);
    }

    public function test_signing_in_clears_the_complaint_the_card_was_showing(): void
    {
        $box = $this->mailbox($this->admin);
        $box->forceFill(['last_error' => 'Peer certificate did not match', 'status' => 'error'])->save();

        // Changing what failed retires the old message on its own.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/accounts/{$box->uuid}", [
            'imap_host' => 'server123.web-hosting.test',
        ])->assertOk();
        $this->assertNull($box->fresh()->last_error);

        // And so does a check that succeeds. The fake connector refuses IMAP,
        // so this mailbox is send-only for the purpose of the test.
        $box->forceFill(['imap_host' => null, 'last_error' => 'Something older', 'status' => 'error'])->save();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/test")->assertOk();
        $this->assertNull($box->fresh()->last_error);
        $this->assertSame('active', $box->fresh()->status);
    }

    public function test_mail_left_mid_send_is_not_lost_in_the_outbox(): void
    {
        $box = $this->mailbox($this->admin);
        $make = fn () => MailMessage::create([
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id, 'folder' => 'outbox',
            'status' => 'sending', 'to' => [['email' => 'them@client.test']], 'subject' => 'Half sent',
            'body_html' => '<p>hi</p>', 'thread_key' => 'x', 'send_after' => now()->subMinute(),
        ]);

        // A job that dies outright hands the message back rather than leaving
        // it claimed for ever.
        $died = $make();
        (new \App\Jobs\SendMailMessage($died->id))->failed(new \RuntimeException('Worker restarted'));
        $this->assertSame('failed', $died->fresh()->status);
        $this->assertSame('outbox', $died->fresh()->folder);
        $this->assertStringContainsString('Worker restarted', (string) $died->fresh()->error);

        // And one claimed by a worker that simply vanished is swept up.
        $abandoned = $make();
        $abandoned->timestamps = false;
        $abandoned->forceFill(['updated_at' => now()->subMinutes(30)])->save();

        // The queue is held here so the sweep's own effect is what is seen -
        // in life the job it dispatches sends the mail moments later.
        Queue::fake();
        $this->artisan('mails:tick dispatch')->assertSuccessful();

        $this->assertSame('queued', $abandoned->fresh()->status, 'it is queued again, not stranded');
        Queue::assertPushed(\App\Jobs\SendMailMessage::class);
    }

    public function test_an_encoded_subject_is_read_as_words(): void
    {
        $box = $this->mailbox($this->admin);
        $mail = $this->arrived($box, '=?UTF-8?Q?Amazon_Web_Services_=E2=80=93_Email_Address_Verifica?= =?UTF-8?Q?tion_Request?=');

        $shown = $this->actingAs($this->adminUser)->getJson("/api/v1/crm/mails/messages/{$mail->uuid}")
            ->assertOk()->json('data.message.subject');

        $this->assertSame('Amazon Web Services – Email Address Verification Request', $shown);
        // Plain subjects are left exactly as they are.
        $this->assertSame('Rates', \App\Services\Mail\MailHtml::header('Rates'));
    }

    // ---- Forwarding, once the address says yes --------------------------------------

    public function test_nothing_is_forwarded_until_the_address_answers_its_code(): void
    {
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/forwards", [
            'address' => 'archive@partner.test',
        ])->assertOk();

        // The code went to the address itself, and nowhere else.
        $sent = $this->sent()->last()->getOriginalMessage();
        $this->assertSame('archive@partner.test', $sent->getTo()[0]->getAddress());
        preg_match('/(\d{6})/', $sent->getHtmlBody(), $m);
        $code = $m[1] ?? '';
        $this->assertNotEmpty($code);

        // Unverified addresses are not forwarded to.
        $this->assertSame([], \App\Models\Crm\MailAccount::verifiedForwards($box->fresh()));
        $shown = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/accounts')->json('data.0.forwards.0');
        $this->assertFalse($shown['verified']);
        $this->assertArrayNotHasKey('code', $shown, 'the code never comes back out');

        // A wrong code is refused; the right one turns it on.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/forwards/verify", [
            'address' => 'archive@partner.test', 'code' => '000000',
        ])->assertStatus(422);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/forwards/verify", [
            'address' => 'archive@partner.test', 'code' => $code,
        ])->assertOk();

        $this->assertSame(['archive@partner.test'], \App\Models\Crm\MailAccount::verifiedForwards($box->fresh()));

        // And it can be taken off again.
        $this->actingAs($this->adminUser)->deleteJson("/api/v1/crm/mails/accounts/{$box->uuid}/forwards", [
            'address' => 'archive@partner.test',
        ])->assertOk();
        $this->assertSame([], \App\Models\Crm\MailAccount::verifiedForwards($box->fresh()));
    }

    public function test_a_mailbox_does_not_forward_to_itself_or_to_a_crowd(): void
    {
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/forwards", [
            'address' => $box->email,
        ])->assertStatus(422);

        foreach (['a@x.test', 'b@x.test', 'c@x.test', 'd@x.test', 'e@x.test'] as $address) {
            $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/forwards", ['address' => $address])->assertOk();
        }
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/forwards", ['address' => 'f@x.test'])
            ->assertStatus(422);
    }

    public function test_a_signature_knows_where_it_belongs(): void
    {
        $box = $this->mailbox($this->admin);

        $saved = $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/accounts/{$box->uuid}", [
            'signature_html' => '<p>Priya</p>',
            'signature_reply_html' => '<p>P.</p>',
            'signature_on' => 'new',
            'signature_before_quote' => false,
        ])->assertOk()->json('data');

        $this->assertSame('new', $saved['signature_on']);
        $this->assertFalse($saved['signature_before_quote']);
        $this->assertSame('<p>P.</p>', $saved['signature_reply_html']);
    }

    // ---- Room, and what happens when it runs out ------------------------------------

    public function test_the_platform_sets_the_room_and_the_admin_shares_it_out(): void
    {
        // The platform gives this company 2 GB a person.
        $this->org->forceFill(['mails_storage_gb' => 2])->save();
        $this->assertSame(2048, \App\Services\Mail\MailAccess::storageCeiling($this->org->fresh()));

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", [
            'enabled' => true, 'storage_mb' => 500,
        ])->assertOk();
        $this->assertSame(500, $this->staff->fresh()->mail_storage_mb);

        // Never more than the platform gave.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", [
            'enabled' => true, 'storage_mb' => 5000,
        ])->assertStatus(422);

        /*
         * Nobody set anything for this person, so the smallest of what
         * applies decides - and that is their own plan, since a company
         * ceiling can only ever hold somebody back, never give them room
         * their plan does not have.
         */
        $this->adminUser->forceFill(['storage_override_bytes' => 8 * 1073741824])->save();
        $this->assertSame(2048, \App\Services\Mail\MailAccess::storageFor($this->admin->fresh()),
            'the company ceiling holds them to 2 GB');

        $this->adminUser->forceFill(['storage_override_bytes' => null])->save();
        $this->assertSame(1024, \App\Services\Mail\MailAccess::storageFor($this->admin->fresh()),
            'and without an override, the free plan holds them to 1 GB');
    }

    public function test_a_full_mailbox_stops_fetching_and_says_so(): void
    {
        $this->org->forceFill(['mails_storage_gb' => 0.001])->save();  // ~1 MB
        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'A big one', ['size' => 5 * 1048576]);

        $this->assertTrue(\App\Services\Mail\MailAccess::isFull($this->admin->fresh()));

        $result = app(\App\Services\Mail\MailSync::class)->sync($box->fresh());
        $this->assertSame(0, $result['fetched']);
        $this->assertSame('Mailbox full', $result['error']);
        $this->assertStringContainsString('full', (string) $box->fresh()->last_error);
    }

    public function test_the_list_holds_as_many_as_the_reader_asked_for(): void
    {
        $box = $this->mailbox($this->admin);
        foreach (range(1, 30) as $n) {
            $this->arrived($box, "Message {$n}");
        }

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/mails/settings/prefs', ['page_size' => 25, 'conversation' => false])->assertOk();
        $page = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/messages?folder=inbox')->assertOk()->json();
        $this->assertCount(25, $page['data']);

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/mails/settings/prefs', ['page_size' => 100])->assertOk();
        $page = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/messages?folder=inbox')->assertOk()->json();
        $this->assertCount(30, $page['data']);

        // Anything else is refused rather than quietly obeyed.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/mails/settings/prefs', ['page_size' => 7])->assertStatus(422);
    }

    public function test_an_admin_sets_a_mailbox_up_for_somebody_else(): void
    {
        Queue::fake();
        $this->giveStaffMails();

        $created = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/accounts', [
            'email' => 'sales@grapout.test', 'label' => 'Sales desk', 'tag' => 'Shared desk',
            'member' => $this->staff->uuid,
            'smtp_host' => 'smtp.grapout.test', 'smtp_password' => 'secret', 'daily_cap' => 200,
        ])->assertCreated()->json('data');

        $this->assertSame(200, $created['daily_cap']);

        $box = MailAccount::where('email', 'sales@grapout.test')->firstOrFail();
        $this->assertSame($this->staff->id, $box->member_id, 'it belongs to the person it was made for');
        $this->assertSame($this->admin->id, $box->created_by_member_id);

        // Theirs, and not on anybody else's screen.
        $theirs = $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/accounts')->assertOk()->json();
        $this->assertSame(['sales@grapout.test'], collect($theirs['data'])->pluck('email')->all());
        $this->assertTrue($theirs['data'][0]['can_manage'], 'they can correct their own mailbox');

        $mine = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/accounts')->assertOk()->json('data');
        $this->assertNotContains('sales@grapout.test', collect($mine)->pluck('email')->all());
    }

    /**
     * Giving somebody a mailbox is not lending them yours.
     *
     * They get one of their own, pointing at the same address with the same
     * sign-in - so it counts against their allowance, they may correct it,
     * and their mail is theirs rather than a view of somebody else's.
     */
    public function test_a_mailbox_given_to_somebody_becomes_a_mailbox_of_their_own(): void
    {
        Queue::fake();
        $this->giveStaffMails();
        $box = $this->mailbox($this->admin, ['signature_html' => '<p>Company Main</p>']);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/give", [
            'member' => $this->staff->uuid,
        ])->assertOk();

        $copy = MailAccount::ownedBy($this->staff)->firstOrFail();
        $this->assertSame($box->email, $copy->email, 'the same address');
        $this->assertSame($box->imap_host, $copy->imap_host, 'and the same servers');
        $this->assertSame((string) $box->imap_password, (string) $copy->imap_password, 'and the same sign-in');
        $this->assertNull($copy->signature_html, 'but not their Admin\'s signature');
        $this->assertTrue($copy->is_default, 'their first mailbox is the one they write from');

        // Each side sees one mailbox: their own.
        $theirs = $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/accounts')->assertOk()->json();
        $this->assertSame(1, $theirs['used']);
        $this->assertSame([$this->admin->user->name], $theirs['data'][0]['also_held_by']);
        $this->assertTrue($theirs['data'][0]['can_manage']);

        // And they may correct it, which a borrower never could.
        $this->actingAs($this->staffUser)->putJson("/api/v1/crm/mails/accounts/{$copy->uuid}", ['from_name' => 'Harsh'])->assertOk();
        $this->assertSame('Harsh', $copy->fresh()->from_name);

        // Their mail is theirs: the Admin's copy is not a window into it.
        $arrived = $this->arrived($copy, 'Order 44');
        $this->actingAs($this->adminUser)->getJson("/api/v1/crm/mails/messages/{$arrived->uuid}")->assertNotFound();
        $this->actingAs($this->staffUser)->getJson("/api/v1/crm/mails/messages/{$arrived->uuid}")->assertOk();
    }

    public function test_a_copy_can_be_taken_back_and_only_by_an_admin(): void
    {
        Queue::fake();
        $this->giveStaffMails();
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/give", [
            'member' => $this->staff->uuid,
        ])->assertOk();
        $copy = MailAccount::ownedBy($this->staff)->firstOrFail();
        $this->arrived($copy, 'Something of theirs');

        // Nobody but the Admin hands mailboxes out or takes them back.
        $this->actingAs($this->staffUser)->postJson("/api/v1/crm/mails/accounts/{$copy->uuid}/give", [
            'member' => $this->admin->uuid,
        ])->assertForbidden();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/give", [
            'member' => $this->staff->uuid, 'revoke' => true,
        ])->assertOk();

        $this->assertNull($copy->fresh(), 'their copy goes, with the mail in it');
        $this->assertNotNull($box->fresh(), 'the mailbox it was copied from stays');
    }

    public function test_a_mailbox_given_out_counts_against_their_allowance(): void
    {
        Queue::fake();
        $this->giveStaffMails();
        $this->staff->update(['mail_mailbox_limit' => 1]);
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/give", [
            'member' => $this->staff->uuid,
        ])->assertOk();

        // That was their one place, so they cannot add another themselves.
        $this->actingAs($this->staffUser)->postJson('/api/v1/crm/mails/accounts', [
            'email' => 'harsh@grapout.test', 'smtp_host' => 'smtp.grapout.test', 'smtp_password' => 'x',
        ])->assertStatus(422);
    }

    public function test_trash_and_spam_empty_themselves_after_a_month(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);

        $old = $this->arrived($box, 'Deleted long ago', ['folder' => 'trash']);
        $recent = $this->arrived($box, 'Deleted yesterday', ['folder' => 'trash']);
        $junk = $this->arrived($box, 'Old spam', ['folder' => 'spam']);
        $kept = $this->arrived($box, 'Archived on purpose', ['folder' => 'archive']);

        foreach ([$old, $junk, $kept] as $message) {
            $message->timestamps = false;
            $message->forceFill(['updated_at' => now()->subDays(40)])->save();
        }

        $this->artisan('mails:tick backup')->assertSuccessful();

        $this->assertNull($old->fresh(), 'a month in Trash is long enough');
        $this->assertNull($junk->fresh(), 'and in Spam');
        $this->assertNotNull($recent->fresh(), 'what was deleted yesterday can still be got back');
        $this->assertNotNull($kept->fresh(), 'archived mail is never swept');
    }

    /**
     * A time picked in the browser is the time it goes.
     *
     * The browser sends the moment as UTC and this application keeps its
     * clock in Asia/Kolkata, so a mail scheduled for midnight was written
     * down as half past six the previous evening: already past, so it went
     * out at once, and the screen showed two different times for the one
     * message.
     */
    public function test_a_scheduled_mail_waits_for_the_time_that_was_picked(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);

        // Two hours from now, said the way a browser says it.
        $when = now()->addHours(2);
        $sent = $this->actingAs($this->adminUser)->post('/api/v1/crm/mails/compose', [
            'action' => 'schedule', 'account' => $box->uuid, 'to' => ['them@client.test'],
            'subject' => 'Monday morning', 'body_html' => '<p>hello</p>',
            'scheduled_for' => $when->copy()->utc()->toIso8601String(),
        ])->assertOk()->json('data');

        $message = MailMessage::where('uuid', $sent['uuid'])->firstOrFail();

        $this->assertSame('scheduled', $message->folder);
        $this->assertSame(
            $when->format('Y-m-d H:i'),
            $message->scheduled_for->format('Y-m-d H:i'),
            'the moment stored is the moment picked',
        );
        $this->assertTrue($message->send_after->isFuture(), 'and it is still in the future');

        /*
         * Scheduling queues one delayed job of its own; what matters is that
         * the every-minute sweep adds nothing until the time comes.
         */
        $queued = fn () => Queue::pushed(\App\Jobs\SendMailMessage::class)->count();
        $atSchedule = $queued();

        $this->artisan('mails:tick dispatch')->assertSuccessful();
        $this->assertSame($atSchedule, $queued(), 'the sweep leaves it alone until its time');

        $this->travelTo($when->copy()->addMinute());
        $this->artisan('mails:tick dispatch')->assertSuccessful();
        $this->assertSame($atSchedule + 1, $queued(), 'and picks it up once the time has come');
        $this->travelBack();
    }

    /**
     * The copy the server keeps of what we sent is the same mail.
     *
     * We write our own Message-ID as <id@domain> and a server hands the same
     * one back bare, so the two did not match and everything sent from here
     * appeared twice in Sent the moment its server copy was read.
     */
    public function test_the_servers_copy_of_a_sent_mail_is_not_a_second_mail(): void
    {
        $box = $this->mailbox($this->admin);

        $ours = MailMessage::create([
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id, 'folder' => 'sent',
            'thread_key' => 'x', 'subject' => 'Test mail-3', 'message_id' => '<abc-123@grapout.test>',
            'from_email' => $box->email, 'to' => [['email' => 'harsh@grapmail.test']], 'date' => now(),
        ]);

        // Read back from the server's Sent folder, where it is bare.
        $forms = \App\Services\Mail\MailSync::messageIdForms('abc-123@grapout.test');
        $this->assertContains('<abc-123@grapout.test>', $forms);

        $found = MailMessage::where('mail_account_id', $box->id)
            ->whereIn('message_id', $forms)
            ->where('folder', 'sent')
            ->first();

        $this->assertNotNull($found, 'the row we already have is recognised');
        $this->assertSame($ours->id, $found->id);
    }

    // ---- The address book ------------------------------------------------------------

    public function test_writing_to_somebody_remembers_them(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->post('/api/v1/crm/mails/compose', [
            'action' => 'send', 'account' => $box->uuid,
            'to' => ['Kunal Chaudhari <kunal@bcg.test>'],
            'cc' => ['hema@bcg.test'],
            'subject' => 'Invoice', 'body_html' => '<p>attached</p>',
        ])->assertOk();

        $book = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/contacts')->assertOk()->json('data'));

        $kunal = $book->firstWhere('email', 'kunal@bcg.test');
        $this->assertSame('Kunal Chaudhari', $kunal['name'], 'the name beside the address is kept');
        $this->assertSame('Kunal Chaudhari <kunal@bcg.test>', $kunal['label']);
        $this->assertSame(1, $kunal['sent_count']);

        // Somebody only copied is remembered too, name or no name.
        $this->assertNotNull($book->firstWhere('email', 'hema@bcg.test'));
    }

    public function test_a_name_somebody_typed_is_not_overruled_by_the_next_mail(): void
    {
        $contact = \App\Models\Crm\MailContact::remember($this->admin, 'ravi@steel.test', 'Accounts Dept', false);
        $this->assertSame('Accounts Dept', $contact->name);

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/contacts/{$contact->uuid}", [
            'name' => 'Ravi at Bharat Steel', 'note' => 'Pays on the 7th',
        ])->assertOk();

        // A later mail signs itself differently; the correction stands.
        \App\Models\Crm\MailContact::remember($this->admin, 'ravi@steel.test', 'BHARAT STEEL BILLING', false);

        $fresh = $contact->fresh();
        $this->assertSame('Ravi at Bharat Steel', $fresh->name);
        $this->assertSame('Pays on the 7th', $fresh->note);
    }

    public function test_suggestions_favour_whoever_is_written_to_most(): void
    {
        foreach (range(1, 3) as $ignored) {
            \App\Models\Crm\MailContact::remember($this->admin, 'often@client.test', 'Often Written To', true);
        }
        \App\Models\Crm\MailContact::remember($this->admin, 'once@client.test', 'Heard From Once', false);
        $blocked = \App\Models\Crm\MailContact::remember($this->admin, 'noreply@robot.test', null, false);
        $blocked->forceFill(['is_blocked' => true])->save();

        $suggested = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/mails/contacts?suggest=1&q=client')
            ->assertOk()->json('data'));

        $this->assertSame('often@client.test', $suggested->first()['email']);
        $this->assertCount(2, $suggested);

        $all = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/contacts?suggest=1')->json('data'));
        $this->assertNotContains('noreply@robot.test', $all->pluck('email')->all(), 'a struck-off address is not suggested');
    }

    public function test_an_address_book_is_personal(): void
    {
        $this->giveStaffMails();
        \App\Models\Crm\MailContact::remember($this->admin, 'private@client.test', 'Mine', true);

        $theirs = $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/contacts')->assertOk()->json('data');
        $this->assertSame([], $theirs, "a colleague's addresses are not the company's to read");
    }

    // ---- Reading ----------------------------------------------------------------------

    private function arrived(MailAccount $box, string $subject, array $extra = []): MailMessage
    {
        return MailMessage::create($extra + [
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id, 'folder' => 'inbox',
            'thread_key' => MailMessage::threadKeyFor(null, null, null, $subject),
            'from_email' => 'priya@client.test', 'subject' => $subject, 'body_text' => 'hello', 'date' => now(),
        ]);
    }

    public function test_the_inbox_groups_a_conversation_and_opening_it_reads_it(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'Rates');
        $this->arrived($box, 'Re: Rates');
        $this->arrived($box, 'Invoice');

        $list = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/messages?folder=inbox')->assertOk()->json();
        $this->assertTrue($list['threaded']);
        $this->assertCount(2, $list['data']);
        $rates = collect($list['data'])->firstWhere('thread_count', 2);
        $this->assertSame(2, $rates['thread_unread']);

        $this->actingAs($this->adminUser)->getJson("/api/v1/crm/mails/messages/{$rates['uuid']}")->assertOk()->assertJsonCount(2, 'data.thread');
        $this->assertSame(0, MailMessage::where('thread_key', $rates['thread_key'])->where('is_read', false)->count());
    }

    public function test_somebody_elses_mail_is_simply_not_there(): void
    {
        $box = $this->mailbox($this->admin);
        $mail = $this->arrived($box, 'Salary');
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", ['enabled' => true]);

        $this->actingAs($this->staffUser)->getJson("/api/v1/crm/mails/messages/{$mail->uuid}")->assertNotFound();
    }

    public function test_trash_restores_to_where_it_came_from_and_deletes_only_from_there(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $mail = $this->arrived($box, 'Old');

        $bulk = fn (string $action) => $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/messages/bulk', ['uuids' => [$mail->uuid], 'action' => $action])->assertOk();

        $bulk('delete');   // from the inbox, delete means Trash
        $this->assertSame('trash', $mail->fresh()->folder);
        $bulk('restore');
        $this->assertSame('inbox', $mail->fresh()->folder);
        $bulk('trash');
        $bulk('delete');   // from Trash, delete means gone
        $this->assertNull($mail->fresh());
    }

    public function test_acting_on_a_conversation_row_takes_the_whole_thread_with_it(): void
    {
        Queue::fake();
        $box = $this->mailbox($this->admin);
        $first = $this->arrived($box, 'Rates');
        $latest = $this->arrived($box, 'Re: Rates');
        $other = $this->arrived($box, 'Invoice');

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/messages/bulk', [
            'uuids' => [$latest->uuid], 'action' => 'archive', 'thread' => true,
        ])->assertOk();

        $this->assertSame('archive', $first->fresh()->folder);
        $this->assertSame('archive', $latest->fresh()->folder);
        $this->assertSame('inbox', $other->fresh()->folder);
    }

    public function test_labels_are_made_and_worn(): void
    {
        $box = $this->mailbox($this->admin);
        $mail = $this->arrived($box, 'Contract');

        $label = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/labels', ['name' => 'Clients', 'color' => '#10b981'])
            ->assertCreated()->json('data.uuid');
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/labels', ['name' => 'Clients'])->assertStatus(422);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/messages/bulk', ['uuids' => [$mail->uuid], 'action' => 'label', 'label' => $label])->assertOk();

        $this->actingAs($this->adminUser)->getJson("/api/v1/crm/mails/messages?label={$label}")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/counts')->assertOk()->assertJsonPath('data.labels.0.count', 1);
    }

    // ---- The pieces underneath ----------------------------------------------------------

    public function test_mail_html_is_stripped_of_everything_that_runs(): void
    {
        $clean = MailHtml::sanitize('<p onclick="steal()">Hi<script>alert(1)</script> <a href="javascript:bad()">x</a> <a href="https://ok.test">ok</a></p><iframe src="x"></iframe>');

        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('iframe', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $clean);
    }

    public function test_server_folders_are_recognised_by_their_names(): void
    {
        $this->assertSame(
            ['inbox' => 'INBOX', 'sent' => '[Gmail]/Sent Mail', 'spam' => '[Gmail]/Spam', 'trash' => '[Gmail]/Trash', 'drafts' => '[Gmail]/Drafts'],
            MailSync::mapFolders(['INBOX', '[Gmail]/Sent Mail', '[Gmail]/Spam', '[Gmail]/Trash', '[Gmail]/Drafts', '[Gmail]/All Mail']),
        );
        $this->assertSame(
            ['inbox' => 'INBOX', 'sent' => 'INBOX.Sent', 'trash' => 'INBOX.Trash', 'spam' => 'INBOX.Junk'],
            MailSync::mapFolders(['INBOX', 'INBOX.Sent', 'INBOX.Trash', 'INBOX.Junk']),
        );
        $this->assertSame('Deleted Items', MailSync::mapFolders(['INBOX', 'Deleted Items', 'Sent Items'])['trash']);
    }

    public function test_a_conversation_is_keyed_by_its_first_message_or_its_bare_subject(): void
    {
        $this->assertSame(
            MailMessage::threadKeyFor(null, null, null, 'Rates'),
            MailMessage::threadKeyFor(null, null, null, 'Re: Fwd: RE: Rates'),
        );
        $this->assertSame(
            MailMessage::threadKeyFor('<first@x> <second@x>', '<second@x>', '<third@x>', 'anything'),
            MailMessage::threadKeyFor('<first@x>', null, '<other@x>', 'else'),
        );
    }

    public function test_an_away_message_says_it_is_automatic(): void
    {
        $box = $this->mailbox($this->admin);
        $incoming = $this->arrived($box, 'Order', ['message_id' => '<order@client.test>']);

        app(MailSender::class)->autoReply($box, $incoming, ['enabled' => true, 'body' => 'Away until Monday.']);

        $email = $this->sent()->last()->getOriginalMessage();
        $this->assertSame('auto-replied', $email->getHeaders()->get('Auto-Submitted')->getBodyAsString());
        $this->assertSame('Re: Order', $email->getSubject());
    }

    // ---- Writing help -----------------------------------------------------------------------

    public function test_ai_says_so_when_nobody_has_set_it_up(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/ai', ['mode' => 'compose', 'instruction' => 'Thank a client'])
            ->assertStatus(422);
    }

    public function test_ai_drafts_through_the_companys_chosen_provider(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['subject' => 'Thank you', 'body' => 'Thank you for the order.'])]]],
        ])]);

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/mails/settings/ai', [
            'enabled' => true, 'provider' => 'openai', 'model' => 'company-chosen-model', 'api_key' => 'sk-test',
        ])->assertOk();

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/ai', ['mode' => 'compose', 'instruction' => 'Thank a client'])
            ->assertOk()->assertJsonPath('data.subject', 'Thank you')->assertJsonPath('data.body', 'Thank you for the order.');

        Http::assertSent(fn ($request) => $request['model'] === 'company-chosen-model');
    }

    public function test_openai_needs_its_model_named(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/mails/settings/ai', [
            'enabled' => true, 'provider' => 'openai', 'api_key' => 'sk-test',
        ])->assertStatus(422)->assertJsonValidationErrors('model');
    }
}
