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
 * Drawing a photograph in a bubble without sending the photograph.
 *
 * A picture off a phone is several megabytes and thousands of pixels wide;
 * the bubble is a couple of hundred pixels across. The thread was fetching
 * the whole file to draw a stamp - once per picture, per person, on whatever
 * connection they had - and sat on "Loading…" while it did.
 */
class ChatImageThumbnailTest extends TestCase
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
    private function send(UploadedFile $file): array
    {
        $conversation = Conversation::directBetween($this->alice, $this->bob);

        $sent = $this->actingAs($this->alice)->post(
            "/api/v1/conversations/{$conversation->uuid}/messages",
            ['body' => 'Look', 'attachments' => [$file]],
            ['Accept' => 'application/json'],
        )->assertCreated();

        return [$conversation, (int) $sent->json('data.attachments.0.id')];
    }

    private function fetch(Conversation $conversation, int $id, bool $thumb): string
    {
        return $this->actingAs($this->bob)
            ->get("/api/v1/conversations/{$conversation->uuid}/attachments/{$id}" . ($thumb ? '?thumb=1' : ''))
            ->assertOk()
            ->streamedContent();
    }

    public function test_the_thread_is_sent_a_smaller_picture_than_the_one_uploaded(): void
    {
        // The shape of a phone screenshot, which is what this is all about.
        [$conversation, $id] = $this->send(UploadedFile::fake()->image('screenshot.jpg', 2400, 1080));

        $full = $this->fetch($conversation, $id, false);
        $thumb = $this->fetch($conversation, $id, true);

        $this->assertLessThan(strlen($full), strlen($thumb));

        [$width] = getimagesizefromstring($thumb);
        $this->assertSame(640, $width, 'the long edge should be capped');
    }

    public function test_the_full_picture_is_still_the_full_picture(): void
    {
        [$conversation, $id] = $this->send(UploadedFile::fake()->image('screenshot.jpg', 2400, 1080));

        [$width, $height] = getimagesizefromstring($this->fetch($conversation, $id, false));

        // Nothing about asking for a thumbnail may change what a download is.
        $this->assertSame(2400, $width);
        $this->assertSame(1080, $height);
    }

    public function test_a_picture_already_small_is_sent_as_it_is(): void
    {
        // Shrinking this would save nobody anything, and re-encoding it would
        // only make it worse.
        [$conversation, $id] = $this->send(UploadedFile::fake()->image('avatar.png', 200, 200));

        [$width, $height] = getimagesizefromstring($this->fetch($conversation, $id, true));

        $this->assertSame(200, $width);
        $this->assertSame(200, $height);
    }

    public function test_something_that_is_not_a_picture_is_untouched(): void
    {
        [$conversation, $id] = $this->send(UploadedFile::fake()->create('quote.pdf', 40, 'application/pdf'));

        $response = $this->actingAs($this->bob)
            ->get("/api/v1/conversations/{$conversation->uuid}/attachments/{$id}?thumb=1")
            ->assertOk();

        // Asked for a thumbnail of a PDF; given the PDF, not an error.
        $this->assertStringContainsString('pdf', (string) $response->headers->get('content-type'));
    }

    public function test_the_thumbnail_is_made_once_and_kept(): void
    {
        [$conversation, $id] = $this->send(UploadedFile::fake()->image('screenshot.jpg', 2400, 1080));

        $first = $this->fetch($conversation, $id, true);
        $files = Storage::disk('local')->files('chat-thumbs');
        $this->assertCount(1, $files);

        // The second look costs nothing: same bytes, same single file.
        $this->assertSame($first, $this->fetch($conversation, $id, true));
        $this->assertSame($files, Storage::disk('local')->files('chat-thumbs'));
    }

    public function test_deleting_the_message_takes_the_thumbnail_with_it(): void
    {
        [$conversation, $id] = $this->send(UploadedFile::fake()->image('screenshot.jpg', 2400, 1080));
        $this->fetch($conversation, $id, true);
        $this->assertCount(1, Storage::disk('local')->files('chat-thumbs'));

        $message = \App\Models\MessageAttachment::find($id)->message;

        $this->actingAs($this->alice)
            ->deleteJson("/api/v1/conversations/{$conversation->uuid}/messages/{$message->uuid}?for=everyone")
            ->assertOk()
            ->assertJsonPath('message', 'Message deleted for everyone.');

        // A thumbnail is still the picture, only smaller. A deletion that
        // left one behind would not be a deletion.
        $this->assertSame([], Storage::disk('local')->files('chat-thumbs'));
    }

    public function test_a_stranger_is_refused_a_thumbnail_too(): void
    {
        [$conversation, $id] = $this->send(UploadedFile::fake()->image('screenshot.jpg', 2400, 1080));

        $stranger = User::factory()->create();
        $stranger->settings()->create([]);

        $this->actingAs($stranger)
            ->get("/api/v1/conversations/{$conversation->uuid}/attachments/{$id}?thumb=1")
            ->assertForbidden();
    }
}
