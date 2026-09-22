<?php

namespace Tests\Feature;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Services\Mail\MailArchive;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mail kept as files.
 *
 * The archive is the answer to "where is my mail, and what could I do with
 * it if this application vanished tomorrow" - so these check the files are
 * real, readable, in the ordinary format, and can be brought back.
 */
class MailBackupTest extends TestCase
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

    private function mailbox(Member $owner): MailAccount
    {
        return MailAccount::create([
            'organization_id' => $this->org->id, 'member_id' => $owner->id, 'email' => 'accounts@grapout.test',
            'from_name' => 'Grapout Accounts', 'provider' => 'custom',
            'smtp_host' => 'smtp.grapout.test', 'smtp_password' => 'secret',
        ]);
    }

    private function arrived(MailAccount $box, string $subject, array $extra = []): MailMessage
    {
        return MailMessage::create($extra + [
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id, 'folder' => 'inbox',
            'thread_key' => MailMessage::threadKeyFor(null, null, null, $subject),
            'from_name' => 'Priya', 'from_email' => 'priya@client.test',
            'to' => [['email' => 'accounts@grapout.test', 'name' => 'Accounts']],
            'message_id' => '<' . md5($subject) . '@client.test>',
            'subject' => $subject, 'body_html' => '<p>Hello about ' . $subject . '</p>', 'body_text' => 'Hello about ' . $subject,
            'date' => now()->subDay(),
        ]);
    }

    public function test_every_message_is_written_out_as_an_eml_file_with_an_index(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'Invoice 21');
        $this->arrived($box, 'Invoice 22');

        $archive = app(MailArchive::class);
        $result = $archive->backup($box);

        $this->assertSame(2, $result['messages']);
        $folder = $archive->folder($box);
        $this->assertStringContainsString('GRAP', $folder, 'the company names its own folder');
        $this->assertStringContainsString('accounts-at-grapout-test', $folder, 'and so does the mailbox');

        $files = collect(Storage::disk('local')->allFiles($folder))->filter(fn ($f) => str_ends_with($f, '.eml'));
        $this->assertCount(2, $files);

        $eml = Storage::disk('local')->get($files->first());
        $this->assertStringContainsString('Subject: Invoice 2', $eml);
        $this->assertStringContainsString('From: Priya <priya@client.test>', $eml);
        $this->assertStringContainsString('X-Netvork-Folder: inbox', $eml);

        $index = Storage::disk('local')->get($folder . '/index.jsonl');
        $rows = collect(preg_split('/\R/', trim($index)))->map(fn ($l) => json_decode($l, true));
        $this->assertCount(2, $rows);
        $this->assertSame(64, strlen($rows->first()['sha256']), 'each line carries a checksum');
    }

    public function test_a_second_run_only_writes_what_arrived_since(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'One');

        $archive = app(MailArchive::class);
        $this->assertSame(1, $archive->backup($box)['messages']);
        $this->assertSame(0, $archive->backup($box->fresh())['messages'], 'nothing new, nothing written');

        $this->arrived($box, 'Two');
        $this->assertSame(1, $archive->backup($box->fresh())['messages']);

        // A full run starts again from the beginning, for a rebuilt server.
        $this->assertSame(2, $archive->backup($box->fresh(), true)['messages']);
    }

    public function test_drafts_and_the_outbox_are_not_archived(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'Kept');
        $this->arrived($box, 'Half written', ['folder' => 'drafts']);
        $this->arrived($box, 'On its way', ['folder' => 'outbox']);

        $this->assertSame(1, app(MailArchive::class)->backup($box)['messages']);
    }

    public function test_mail_exported_as_eml_comes_back_in_again(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);
        $original = $this->arrived($box, 'Rates for March');

        $archive = app(MailArchive::class);
        $archive->backup($box);

        // The very file the archive wrote, handed back to the importer.
        $file = collect(Storage::disk('local')->allFiles($archive->folder($box)))->first(fn ($f) => str_ends_with($f, '.eml'));
        $raw = Storage::disk('local')->get($file);
        $path = tempnam(sys_get_temp_dir(), 'eml') . '.eml';
        file_put_contents($path, $raw);

        // Already here: the same Message-ID is not imported twice.
        $this->assertSame(['added' => 0, 'skipped' => 1], $archive->import($box->fresh(), $path));

        $original->delete();
        $result = $archive->import($box->fresh(), $path);
        $this->assertSame(1, $result['added']);

        $back = MailMessage::where('mail_account_id', $box->id)->firstOrFail();
        $this->assertSame('Rates for March', $back->subject);
        $this->assertSame('priya@client.test', $back->from_email);
        $this->assertSame('inbox', $back->folder, 'it goes back to the folder it came from');
        @unlink($path);
    }

    public function test_the_screen_shows_where_the_archive_is_and_backs_up_on_request(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'Something');

        $list = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/backups')->assertOk()->json();
        $mine = $list['data'][0];
        $this->assertSame('accounts@grapout.test', $mine['email']);
        $this->assertTrue($mine['destinations']['server'], 'the server copy is always on');
        $this->assertStringContainsString('mail-archive', $mine['archive']['folder']);
        $this->assertSame(0, $mine['archive']['files']);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/mails/backups/{$box->uuid}/run", ['now' => true])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.messages', 1);

        $after = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/backups')->assertOk()->json('data.0');
        $this->assertSame(1, $after['archive']['files']);
        $this->assertGreaterThan(0, $after['archive']['bytes']);
    }

    public function test_a_zip_of_the_archive_can_be_downloaded(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'Take me with you');

        $response = $this->actingAs($this->adminUser)->get("/api/v1/crm/mails/backups/{$box->uuid}/export");
        $response->assertOk();
        $this->assertStringContainsString('.zip', (string) $response->headers->get('content-disposition'));
    }

    public function test_an_uploaded_eml_is_imported_into_the_mailbox(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);

        $raw = implode("\r\n", [
            'From: Someone Else <someone@elsewhere.test>',
            'To: accounts@grapout.test',
            'Subject: From another system',
            'Message-ID: <abc-123@elsewhere.test>',
            'Date: Mon, 2 Mar 2026 10:00:00 +0530',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'Moved across from the old mail program.',
        ]);

        $this->actingAs($this->adminUser)->post("/api/v1/crm/mails/backups/{$box->uuid}/import", [
            'file' => UploadedFile::fake()->createWithContent('old-mail.eml', $raw),
            'folder' => 'archive',
        ])->assertOk()->assertJsonPath('data.added', 1);

        $imported = MailMessage::where('mail_account_id', $box->id)->firstOrFail();
        $this->assertSame('From another system', $imported->subject);
        $this->assertSame('someone@elsewhere.test', $imported->from_email);
        $this->assertSame('archive', $imported->folder);
    }

    public function test_backup_settings_keep_their_secrets_and_never_hand_them_back(): void
    {
        Storage::fake('local');
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/backups/{$box->uuid}", [
            'remote' => [
                'enabled' => true, 'driver' => 's3', 'bucket' => 'grapout-mail', 'region' => 'ap-south-1',
                'key' => 'AKIAEXAMPLE', 'secret' => 'super-secret', 'path' => 'backups',
            ],
            'local' => ['enabled' => true, 'hint' => 'D:\\Mail archive'],
        ])->assertOk();

        $saved = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/mails/backups')->assertOk()->json('data.0.destinations');
        $this->assertTrue($saved['remote']['enabled']);
        $this->assertSame('grapout-mail', $saved['remote']['bucket']);
        $this->assertTrue($saved['remote']['has_secret']);
        $this->assertArrayNotHasKey('secret', $saved['remote'], 'the secret never comes back out');
        $this->assertTrue($saved['local']['enabled']);

        // Saving again without the secret keeps the one on file.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/backups/{$box->uuid}", [
            'remote' => ['enabled' => true, 'driver' => 's3', 'bucket' => 'grapout-mail-2'],
        ])->assertOk();
        $this->assertSame('super-secret', $box->fresh()->backup['remote']['secret']);
    }

    public function test_the_second_copy_is_pushed_to_the_bucket_signed(): void
    {
        Storage::fake('local');
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 200)]);

        $box = $this->mailbox($this->admin);
        $this->arrived($box, 'Off site');
        $box->forceFill(['backup' => ['remote' => [
            'enabled' => true, 'driver' => 's3', 'bucket' => 'grapout-mail',
            'region' => 'ap-south-1', 'key' => 'AKIAEXAMPLE', 'secret' => 'shhh', 'path' => 'netvork',
        ]]])->save();

        $archive = app(MailArchive::class);
        $archive->backup($box->fresh());
        $result = app(\App\Services\Mail\MailVault::class)->push($box->fresh(), $archive);

        $this->assertFalse($result['skipped']);
        $this->assertSame(1, $result['sent']);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), 's3.ap-south-1.amazonaws.com/grapout-mail/netvork/mail-archive/accounts-at-grapout-test/')
                && str_starts_with((string) $request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIAEXAMPLE/')
                && strlen((string) $request->header('x-amz-content-sha256')[0]) === 64;
        });

        // What has gone up is remembered, so tomorrow only sends what is new.
        $this->assertSame(0, app(\App\Services\Mail\MailVault::class)->push($box->fresh(), $archive)['sent']);
    }

    public function test_somebody_elses_mailbox_cannot_be_archived_or_exported(): void
    {
        Storage::fake('local');
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/mails/settings/team/{$this->staff->uuid}", ['enabled' => true])->assertOk();
        $box = $this->mailbox($this->admin);

        $this->actingAs($this->staffUser)->postJson("/api/v1/crm/mails/backups/{$box->uuid}/run", ['now' => true])->assertNotFound();
        $this->actingAs($this->staffUser)->get("/api/v1/crm/mails/backups/{$box->uuid}/export")->assertNotFound();
    }
}
