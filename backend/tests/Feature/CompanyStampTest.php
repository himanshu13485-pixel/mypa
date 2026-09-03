<?php

namespace Tests\Feature;

use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The rubber stamp, beside the signatory.
 *
 * Its own image rather than a second use of the logo: the logo is branding in
 * the header, the stamp is the mark of authority at the foot, and companies
 * commonly have both.
 */
class CompanyStampTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private IssuingCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Storage::fake('public');

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = User::factory()->create();
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        $this->adminUser->settings()->create([]);

        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->adminUser->id,
            'crm_role' => 'admin',
            'status' => 'active',
        ]);

        $this->company = IssuingCompany::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Exports',
        ]);
    }

    private function upload(string $name = 'stamp.png')
    {
        return $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->post("/api/v1/crm/masters/issuing-companies/{$this->company->id}/stamp", [
                'file' => UploadedFile::fake()->image($name, 300, 300),
            ]);
    }

    public function test_a_stamp_can_be_uploaded(): void
    {
        $this->upload()->assertOk();

        $path = $this->company->fresh()->stamp_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    /** The stamp is its own image; uploading one must not disturb the logo. */
    public function test_the_stamp_and_the_logo_are_kept_apart(): void
    {
        $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->post("/api/v1/crm/masters/issuing-companies/{$this->company->id}/logo", [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])->assertOk();

        $logo = $this->company->fresh()->logo_path;

        $this->upload()->assertOk();

        $fresh = $this->company->fresh();

        $this->assertSame($logo, $fresh->logo_path);
        $this->assertNotSame($logo, $fresh->stamp_path);
        Storage::disk('public')->assertExists($logo);
    }

    /** Replacing it does not leave the old one on disk forever. */
    public function test_replacing_the_stamp_removes_the_old_file(): void
    {
        $this->upload('first.png')->assertOk();
        $first = $this->company->fresh()->stamp_path;

        $this->upload('second.png')->assertOk();
        $second = $this->company->fresh()->stamp_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_stamp_can_be_taken_off_again(): void
    {
        $this->upload()->assertOk();
        $path = $this->company->fresh()->stamp_path;

        $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->deleteJson("/api/v1/crm/masters/issuing-companies/{$this->company->id}/stamp")
            ->assertOk();

        $this->assertNull($this->company->fresh()->stamp_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_something_that_is_not_an_image_is_refused(): void
    {
        $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->post("/api/v1/crm/masters/issuing-companies/{$this->company->id}/stamp", [
                'file' => UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf'),
            ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    /** Another company's stamp is not this organization's to change. */
    public function test_a_company_in_another_organization_is_out_of_reach(): void
    {
        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVAL', 'status' => 'active']);
        $theirs = IssuingCompany::create(['organization_id' => $other->id, 'name' => 'Rival Ltd']);

        $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->post("/api/v1/crm/masters/issuing-companies/{$theirs->id}/stamp", [
                'file' => UploadedFile::fake()->image('stamp.png'),
            ])->assertNotFound();
    }
}
