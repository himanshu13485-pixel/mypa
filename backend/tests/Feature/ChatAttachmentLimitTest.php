<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a chat attachment may weigh.
 *
 * Lower than Drive's, and deliberately: a chat attachment counts against the
 * sender's quota and is copied again into every thread it is forwarded to, so
 * the ceiling is not only about the one upload.
 */
class ChatAttachmentLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');

        $this->me = $this->person();
        $them = $this->person();

        $this->chat = Conversation::create(['type' => 'direct']);
        $this->chat->members()->attach([$this->me->id, $them->id]);
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->profile()->create(['timezone' => 'UTC']);
        $user->settings()->create([]);

        return $user;
    }

    private function send(int $kilobytes)
    {
        return $this->actingAs($this->me)->post("/api/v1/conversations/{$this->chat->uuid}/messages", [
            'type' => 'file',
            'attachments' => [UploadedFile::fake()->create('big.pdf', $kilobytes, 'application/pdf')],
        ]);
    }

    public function test_a_file_inside_the_limit_is_accepted(): void
    {
        $this->send(24 * 1024)->assertCreated();
        $this->assertSame(1, $this->chat->messages()->count());
    }

    public function test_a_file_over_the_limit_is_refused(): void
    {
        $this->send(26 * 1024)
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachments.0');

        $this->assertSame(0, $this->chat->messages()->count());
    }

    /** Told in megabytes, because "max:25600" is not what a person needs. */
    public function test_the_refusal_says_the_limit_in_megabytes(): void
    {
        $message = $this->send(26 * 1024)->json('errors.attachments\.0.0')
            ?? $this->send(26 * 1024)->json('message');

        $this->assertStringContainsString('25 MB', (string) $message);
    }

    /**
     * Drive is a different act and keeps its own, larger ceiling.
     *
     * The two limits shared one config key at first, so lowering the chat one
     * silently halved what somebody could file in Drive.
     */
    public function test_drive_keeps_its_own_larger_limit(): void
    {
        $this->assertSame(50 * 1024, (int) config('mypa.files.max_upload_kb'));
        $this->assertSame(25 * 1024, (int) config('mypa.files.max_chat_upload_kb'));
        $this->assertGreaterThan(
            (int) config('mypa.files.max_chat_upload_kb'),
            (int) config('mypa.files.max_upload_kb'),
        );
    }
}
