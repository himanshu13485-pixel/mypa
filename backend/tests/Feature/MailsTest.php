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

    public function test_an_admin_makes_a_mailbox_for_somebody_else_and_shares_it(): void
    {
        Queue::fake();
        $this->giveStaffMails();

        $created = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/mails/accounts', [
            'email' => 'sales@grapout.test', 'label' => 'Sales desk', 'tag' => 'Shared desk',
            'member' => $this->staff->uuid, 'shared_with' => [$this->admin->uuid],
            'smtp_host' => 'smtp.grapout.test', 'smtp_password' => 'secret', 'daily_cap' => 200,
        ])->assertCreated()->json('data');

        $this->assertTrue($created['is_shared']);
        $this->assertSame(200, $created['daily_cap']);

        $box = MailAccount::where('email', 'sales@grapout.test')->firstOrFail();
        $this->assertSame($this->staff->id, $box->member_id, 'it belongs to the person it was made for');
        $this->assertSame($this->admin->id, $box->created_by_member_id);

        // Both of them see it; it is on the staff member's list as their own.
        $mine = $this->actingAs($this->staffUser)->getJson('/api/v1/crm/mails/accounts')->assertOk()->json();
        $this->assertSame(['sales@grapout.test'], collect($mine['data'])->pluck('email')->all());
        $shared = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/accounts')->assertOk()->json('data');
        $this->assertContains('sales@grapout.test', collect($shared)->pluck('email')->all());
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

    public function test_a_shared_mailbox_is_read_by_everybody_on_it_but_settled_by_its_admin(): void
    {
        Queue::fake();
        $this->giveStaffMails();
        $box = $this->mailbox($this->admin);
        $box->sharedMembers()->sync([$this->staff->id]);
        $mail = $this->arrived($box, 'Order 44');

        // The colleague reads the mailbox's mail...
        $this->actingAs($this->staffUser)->getJson("/api/v1/crm/mails/messages/{$mail->uuid}")->assertOk();
        // ...but its settings are not theirs to change.
        $this->actingAs($this->staffUser)->putJson("/api/v1/crm/mails/accounts/{$box->uuid}", ['from_name' => 'No'])->assertForbidden();
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

        // A user cannot copy one, however it reached them.
        $this->giveStaffMails();
        $box->sharedMembers()->sync([$this->staff->id]);
        $this->actingAs($this->staffUser)->postJson("/api/v1/crm/mails/accounts/{$box->uuid}/replicate", [
            'email' => 'nope@grapout.test',
        ])->assertForbidden();
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
