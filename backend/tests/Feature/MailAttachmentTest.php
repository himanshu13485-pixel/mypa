<?php

namespace Tests\Feature;

use App\Jobs\SendMailMessage;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailSender;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Webklex\PHPIMAP\Client;

/** Sends through the array mailer; never opens an IMAP connection. */
class AttachmentTestConnector extends MailConnector
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
 * A file attached to a mail actually goes with it.
 *
 * This was wrong in production and nothing noticed: the files are written
 * through Storage's "local" disk, whose root is storage/app/private, while
 * the sender looked for them under storage/app. It found nothing, and the
 * check around it quietly sent the mail without its attachment - so the
 * sender watched it go and the recipient never knew a file had been meant
 * for them.
 *
 * The tests below therefore never name a directory. They ask the disk where
 * it put the file, which is the whole point: hand-built paths are what broke
 * this, and a test that built its own would have agreed with the bug.
 */
class MailAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Member $member;
    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->app->instance(MailConnector::class, new AttachmentTestConnector());

        $org = Organization::create([
            'name' => 'Acme', 'code' => 'ACME', 'status' => 'active', 'mails_enabled' => true, 'mails_mailbox_cap' => 3,
        ]);
        $this->user = User::factory()->create(['email' => 'boss@acme.test']);
        $this->user->settings()->create([]);
        $this->user->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->member = Member::create([
            'organization_id' => $org->id, 'user_id' => $this->user->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->account = MailAccount::create([
            'organization_id' => $org->id, 'member_id' => $this->member->id,
            'email' => 'accounts@acme.test', 'from_name' => 'Acme Accounts', 'provider' => 'custom',
            'smtp_host' => 'smtp.acme.test', 'smtp_password' => 'secret',
            'imap_host' => 'imap.acme.test', 'imap_password' => 'secret',
            'status' => 'active',
        ]);
    }

    private function compose(array $extra = []): array
    {
        return $this->actingAs($this->user)->postJson('/api/v1/crm/mails/compose', $extra + [
            'action' => 'send',
            'account' => $this->account->uuid,
            'to' => ['client@elsewhere.test'],
            'subject' => 'The quotation you asked for',
            'body_html' => '<p>Attached.</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('quotation.pdf', 'PDF BYTES HERE')],
        ])->assertOk()->json();
    }

    private function sent(): \Illuminate\Support\Collection
    {
        return collect(Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_an_attached_file_is_written_where_the_disk_says_it_is(): void
    {
        $this->compose();

        $attachment = MailMessage::latest('id')->first()->attachments()->firstOrFail();

        // Asked of the disk, never assembled here.
        $this->assertTrue(Storage::disk('local')->exists($attachment->path));
        $this->assertSame('quotation.pdf', $attachment->filename);
    }

    public function test_and_actually_leaves_with_the_mail(): void
    {
        $this->compose();

        $message = MailMessage::latest('id')->first();
        $message->update(['status' => 'queued', 'send_after' => now()->subMinute()]);
        app(SendMailMessage::class, ['messageId' => $message->id])->handle(app(MailSender::class));

        $this->assertSame('sent', $message->fresh()->status);

        $parts = collect($this->sent()->first()->getOriginalMessage()->getAttachments());
        $this->assertCount(1, $parts, 'the mail went out carrying nothing');
        $this->assertStringContainsString('quotation.pdf', $parts->first()->getPreparedHeaders()->toString());
        $this->assertStringContainsString('PDF BYTES HERE', $parts->first()->getBody());
    }

    public function test_a_file_that_has_gone_missing_stops_the_send_instead_of_going_quietly(): void
    {
        $this->compose();

        $message = MailMessage::latest('id')->first();
        $attachment = $message->attachments()->firstOrFail();

        // Whatever the reason - a cleanup, a restore, a half-finished move.
        Storage::disk('local')->delete($attachment->path);

        $message->update(['status' => 'queued', 'send_after' => now()->subMinute()]);
        app(SendMailMessage::class, ['messageId' => $message->id])->handle(app(MailSender::class));

        /*
         * Failed, and said why.
         *
         * Sending a mail that was meant to carry a file, without the file,
         * is worse than not sending it: the sender believes it went, and
         * the person waiting for the quotation never learns there was one.
         */
        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame('outbox', $message->folder);
        $this->assertStringContainsString('quotation.pdf', (string) $message->error);
        $this->assertCount(0, $this->sent());
    }

    public function test_the_file_can_be_downloaded_back_out_of_the_mail(): void
    {
        $this->compose();

        $message = MailMessage::latest('id')->first();
        $attachment = $message->attachments()->firstOrFail();

        $response = $this->actingAs($this->user)
            ->get("/api/v1/crm/mails/messages/{$message->uuid}/attachments/{$attachment->id}")
            ->assertOk();

        $this->assertSame('PDF BYTES HERE', $response->streamedContent());
    }
}
