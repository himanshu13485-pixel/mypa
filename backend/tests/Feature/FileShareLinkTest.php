<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sharing a file by link, to somebody who has no Netvork account. */
class FileShareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);
        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    protected function upload(): File
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/files/upload', [
                'files' => [UploadedFile::fake()->create('quote.pdf', 64, 'application/pdf')],
            ])
            ->assertCreated();

        return File::where('user_id', $this->owner->id)->latest('id')->firstOrFail();
    }

    public function test_owner_can_mint_a_link_and_anyone_can_download_it(): void
    {
        $file = $this->upload();

        $url = $this->actingAs($this->owner)
            ->postJson("/api/v1/files/{$file->uuid}/share-link")
            ->assertOk()
            ->json('data.url');

        $this->assertStringContainsString('/api/v1/f/', $url);

        // No account, no session — the token is the whole check.
        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertDownload('quote.pdf');
    }

    public function test_the_token_is_never_returned_in_an_ordinary_listing(): void
    {
        $file = $this->upload();
        $this->actingAs($this->owner)->postJson("/api/v1/files/{$file->uuid}/share-link")->assertOk();

        // Anyone holding the token can download the file, so it must not leak
        // into responses that merely describe a file.
        $body = $this->actingAs($this->owner)->getJson('/api/v1/files/browse')->assertOk()->getContent();
        $this->assertStringNotContainsString($file->fresh()->share_token, $body);
    }

    public function test_downloads_are_counted(): void
    {
        $file = $this->upload();
        $path = parse_url(
            $this->actingAs($this->owner)->postJson("/api/v1/files/{$file->uuid}/share-link")->json('data.url'),
            PHP_URL_PATH,
        );

        $this->get($path)->assertOk();
        $this->get($path)->assertOk();

        $this->assertEquals(2, $file->fresh()->share_downloads);
    }

    public function test_revoking_kills_a_link_that_is_already_out_there(): void
    {
        $file = $this->upload();
        $path = parse_url(
            $this->actingAs($this->owner)->postJson("/api/v1/files/{$file->uuid}/share-link")->json('data.url'),
            PHP_URL_PATH,
        );
        $this->get($path)->assertOk();

        $this->actingAs($this->owner)->deleteJson("/api/v1/files/{$file->uuid}/share-link")->assertOk();

        $this->get($path)->assertNotFound();
    }

    public function test_reissuing_rotates_the_token_so_the_old_link_dies(): void
    {
        $file = $this->upload();
        $first = parse_url(
            $this->actingAs($this->owner)->postJson("/api/v1/files/{$file->uuid}/share-link")->json('data.url'),
            PHP_URL_PATH,
        );
        $second = parse_url(
            $this->actingAs($this->owner)->postJson("/api/v1/files/{$file->uuid}/share-link")->json('data.url'),
            PHP_URL_PATH,
        );

        $this->assertNotEquals($first, $second);
        $this->get($first)->assertNotFound();
        $this->get($second)->assertOk();
    }

    public function test_an_expired_link_stops_working(): void
    {
        $file = $this->upload();
        $path = parse_url(
            $this->actingAs($this->owner)
                ->postJson("/api/v1/files/{$file->uuid}/share-link", ['expires_in_days' => 7])
                ->json('data.url'),
            PHP_URL_PATH,
        );

        $this->get($path)->assertOk();

        $this->travel(8)->days();
        $this->get($path)->assertNotFound();
    }

    public function test_a_stranger_cannot_share_or_revoke_someone_elses_file(): void
    {
        $file = $this->upload();

        $this->actingAs($this->other)->postJson("/api/v1/files/{$file->uuid}/share-link")->assertForbidden();
        $this->actingAs($this->other)->deleteJson("/api/v1/files/{$file->uuid}/share-link")->assertForbidden();

        $this->assertNull($file->fresh()->share_token);
    }

    public function test_a_made_up_token_is_not_found(): void
    {
        $this->get('/api/v1/f/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_fifty_megabytes_is_accepted_and_more_is_not(): void
    {
        // The ask was 50 MB; the ceiling used to be 25.
        $this->actingAs($this->owner)
            ->postJson('/api/v1/files/upload', [
                'files' => [UploadedFile::fake()->create('big.zip', 50 * 1024)],
            ])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson('/api/v1/files/upload', [
                'files' => [UploadedFile::fake()->create('toobig.zip', 51 * 1024)],
            ])
            ->assertStatus(422);
    }
}
