<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AppIdService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A file somebody can actually save, inside the Android app.
 *
 * The app is a WebView, and a WebView has no downloading in it: the blob the
 * web app builds with its auth header is dropped with no error anywhere, so
 * tapping a file somebody had sent did nothing at all. The fix hands the URL
 * to Android's own download manager instead - another process, which carries
 * none of our headers - so the URL has to prove its own permission.
 *
 * Which makes these the questions worth asking: that the link only comes to
 * somebody who could already read the file, that the link alone is then
 * enough, and that it stops being enough shortly afterwards.
 */
class ChatAttachmentLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $appIds = app(AppIdService::class);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $appIds->generateFor($this->alice);
        $appIds->generateFor($this->bob);
        $this->alice->settings()->create([]);
        $this->bob->settings()->create([]);

        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $this->bob->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
    }

    /** @return array{0: Conversation, 1: int} */
    private function sendAFile(): array
    {
        $conversation = Conversation::directBetween($this->alice, $this->bob);

        $sent = $this->actingAs($this->alice)->post(
            "/api/v1/conversations/{$conversation->uuid}/messages",
            ['body' => 'The quote', 'attachments' => [UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf')]],
            ['Accept' => 'application/json'],
        )->assertCreated();

        return [$conversation, (int) $sent->json('data.attachments.0.id')];
    }

    private function linkFor(Conversation $conversation, int $attachmentId, User $who): string
    {
        return $this->actingAs($who)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/attachments/{$attachmentId}/link")
            ->assertOk()
            ->json('data.url');
    }

    public function test_the_link_downloads_the_file_without_signing_in(): void
    {
        [$conversation, $attachmentId] = $this->sendAFile();

        // Asked for as Bob, who was sent it.
        $url = $this->linkFor($conversation, $attachmentId, $this->bob);

        // Then followed by nobody at all - which is the download manager, a
        // separate process with no session and none of our headers.
        $response = $this->get($url)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('quote.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_link_is_refused_to_somebody_not_in_the_conversation(): void
    {
        [$conversation, $attachmentId] = $this->sendAFile();

        $stranger = User::factory()->create();
        $stranger->settings()->create([]);

        $this->actingAs($stranger)
            ->getJson("/api/v1/conversations/{$conversation->uuid}/attachments/{$attachmentId}/link")
            ->assertForbidden();
    }

    public function test_a_link_nobody_issued_is_refused(): void
    {
        [$conversation, $attachmentId] = $this->sendAFile();

        // The address without the signature, which is what anyone guessing at
        // the URL would have.
        $this->get("/api/v1/conversations/{$conversation->uuid}/attachments/{$attachmentId}/download")
            ->assertForbidden();
    }

    public function test_a_tampered_link_is_refused(): void
    {
        [$conversation, $attachmentId] = $this->sendAFile();
        $url = $this->linkFor($conversation, $attachmentId, $this->bob);

        // The signature covers the expiry, so moving it does not move it.
        $this->get(preg_replace('/expires=\d+/', 'expires=' . now()->addYear()->timestamp, $url))
            ->assertForbidden();
    }

    public function test_the_link_stops_working_shortly_afterwards(): void
    {
        [$conversation, $attachmentId] = $this->sendAFile();
        $url = $this->linkFor($conversation, $attachmentId, $this->bob);

        // An address that proves its own permission is only safe while it is
        // nearly expired. Two minutes outlives the tap that follows it and
        // nothing else.
        $this->travel(3)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_a_link_cannot_name_a_file_from_another_conversation(): void
    {
        [, $attachmentId] = $this->sendAFile();

        // Alice's own conversation with somebody else. She is a member of it,
        // so only the scoping stops the file travelling between threads.
        $carol = User::factory()->create();
        $carol->settings()->create([]);
        Connection::create([
            'requester_id' => $this->alice->id,
            'addressee_id' => $carol->id,
            'status' => 'accepted',
            'responded_at' => now(),
        ]);
        $other = Conversation::directBetween($this->alice, $carol);

        $this->actingAs($this->alice)
            ->getJson("/api/v1/conversations/{$other->uuid}/attachments/{$attachmentId}/link")
            ->assertNotFound();
    }
}
