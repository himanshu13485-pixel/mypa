<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    /** A fake Windows executable: correct MZ magic bytes, whatever the name claims. */
    protected function fakeExecutable(string $claimedName): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        // MZ header + PE stub — finfo identifies this as application/x-dosexec.
        file_put_contents($tmp, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00" . str_repeat("\x00", 64) . 'PE\x00\x00');

        return new UploadedFile($tmp, $claimedName, 'application/pdf', null, true);
    }

    public function test_executables_and_scripts_rejected_everywhere(): void
    {
        // Plain .exe rejected in Files.
        $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('virus.exe', 10)],
        ])->assertStatus(422);

        // Double extension rejected.
        $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('invoice.pdf.exe', 10)],
        ])->assertStatus(422);
        $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('invoice.exe.pdf', 10)],
        ])->assertStatus(422);

        // Script + markup types rejected.
        foreach (['run.ps1', 'page.html', 'logo.svg', 'tool.jar', 'app.apk'] as $name) {
            $this->actingAs($this->user)->post('/api/v1/files/upload', [
                'files' => [UploadedFile::fake()->create($name, 5)],
            ])->assertStatus(422);
        }

        // DISGUISED executable: real EXE bytes renamed to .pdf — caught by
        // content sniffing.
        $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [$this->fakeExecutable('report.pdf')],
        ])->assertStatus(422);

        // A legitimate document still uploads fine.
        $this->actingAs($this->user)->post('/api/v1/files/upload', [
            'files' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ])->assertCreated();
    }

    public function test_chat_and_meeting_uploads_are_guarded_too(): void
    {
        $other = User::factory()->create();
        $conversation = \App\Models\Conversation::directBetween($this->user, $other);

        // Message attachment: .exe rejected, pdf accepted.
        $this->actingAs($this->user)->post("/api/v1/conversations/{$conversation->uuid}/messages", [
            'attachments' => [UploadedFile::fake()->create('bad.scr', 5)],
        ])->assertStatus(422);
        $this->actingAs($this->user)->post("/api/v1/conversations/{$conversation->uuid}/messages", [
            'attachments' => [UploadedFile::fake()->create('ok.pdf', 5, 'application/pdf')],
        ])->assertCreated();

        // Meeting chat file: disguised executable rejected.
        $meeting = $this->actingAs($this->user)->postJson('/api/v1/meetings', ['requires_approval' => false])->json('data');
        $this->actingAs($this->user)->postJson("/api/v1/meetings/{$meeting['code']}/join")->assertOk();
        $this->actingAs($this->user)->post("/api/v1/meetings/{$meeting['code']}/chat-file", [
            'file' => $this->fakeExecutable('minutes.pdf'),
        ])->assertStatus(422);
        $this->actingAs($this->user)->post("/api/v1/meetings/{$meeting['code']}/chat-file", [
            'file' => UploadedFile::fake()->create('minutes.pdf', 20, 'application/pdf'),
        ])->assertOk();
    }
}
