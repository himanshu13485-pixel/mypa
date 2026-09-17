<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalaryDocument;
use App\Models\Crm\SalaryStructure;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The bank's paperwork, kept with the payroll month it belongs to.
 *
 * A transfer advice names every employee's account on one page, so these are
 * private-disk files behind the salary door - never a public link, never an
 * employee's to read, and never a renamed executable dressed as a statement.
 */
class CrmSalaryDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $adminUser;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $this->adminUser = $this->makeUser('boss@grapout.test', 'Himanshu Sachdeva');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->employeeUser = $this->makeUser('sanjeev@grapout.test', 'Sanjeev');
        $employee = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->employeeUser->id,
            'crm_role' => 'employee', 'status' => 'active', 'joined_at' => '2024-01-01',
        ]);
        SalaryStructure::create([
            'member_id' => $employee->id, 'effective_from' => '2026-01-01',
            'basic' => 12000, 'hra' => 2000, 'components' => [],
            'has_pf' => false, 'has_edli' => false, 'has_esi' => false, 'has_welfare' => false,
        ]);

        $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/salary/generate', ['year' => 2026, 'month' => 8])
            ->assertOk();
    }

    private function makeUser(string $email, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function attach(array $extra = [], ?UploadedFile $file = null)
    {
        return $this->actingAs($this->adminUser)->post('/api/v1/crm/salary/documents', $extra + [
            'year' => 2026, 'month' => 8,
            'file' => $file ?? UploadedFile::fake()->create('neft-advice.pdf', 40, 'application/pdf'),
        ]);
    }

    private function register(?User $who = null): array
    {
        return $this->actingAs($who ?? $this->adminUser)
            ->getJson('/api/v1/crm/salary?year=2026&month=8')
            ->assertOk()->json();
    }

    public function test_a_bank_document_is_kept_with_its_month_and_says_what_it_is(): void
    {
        $this->attach(['note' => 'HDFC transfer advice, batch 2.'])->assertCreated();

        $documents = $this->register()['documents'];
        $this->assertCount(1, $documents);
        $this->assertSame('neft-advice.pdf', $documents[0]['name']);
        $this->assertSame('HDFC transfer advice, batch 2.', $documents[0]['note']);
        $this->assertSame('Himanshu Sachdeva', $documents[0]['by']);

        // On the private disk, under this company's own folder.
        $stored = SalaryDocument::firstOrFail();
        Storage::disk('local')->assertExists($stored->path);
        $this->assertStringStartsWith('crm-documents/' . $this->org->id . '/salary/2026-8', $stored->path);
    }

    public function test_it_belongs_to_the_month_it_was_filed_against(): void
    {
        $this->attach()->assertCreated();

        $september = $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/salary?year=2026&month=9')
            ->assertOk()->json();

        $this->assertSame([], $september['documents']);
    }

    public function test_what_it_is_can_be_written_down_later(): void
    {
        $uuid = $this->attach()->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)
            ->putJson('/api/v1/crm/salary/documents/' . $uuid, ['note' => 'Second batch — the four who were paid late.'])
            ->assertOk();

        $this->assertSame('Second batch — the four who were paid late.', $this->register()['documents'][0]['note']);
    }

    public function test_it_comes_back_as_a_file_that_cannot_run(): void
    {
        $uuid = $this->attach()->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)
            ->get('/api/v1/crm/salary/documents/' . $uuid)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('neft-advice.pdf');
    }

    public function test_an_employee_neither_sees_the_paperwork_nor_may_fetch_it(): void
    {
        $uuid = $this->attach(['note' => 'Everybody’s account numbers are on this.'])->assertCreated()->json('data.uuid');

        // Not in their register…
        $this->assertSame([], $this->register($this->employeeUser)['documents']);
        // …and not by knowing the link either.
        $this->actingAs($this->employeeUser)->get('/api/v1/crm/salary/documents/' . $uuid)->assertForbidden();
        $this->actingAs($this->employeeUser)->post('/api/v1/crm/salary/documents', [
            'year' => 2026, 'month' => 8, 'file' => UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_another_company_cannot_reach_it(): void
    {
        $uuid = $this->attach()->assertCreated()->json('data.uuid');

        $other = Organization::create(['name' => 'Rival', 'code' => 'RIVL']);
        $theirBoss = $this->makeUser('boss@rival.test', 'Someone Else');
        Member::create([
            'organization_id' => $other->id, 'user_id' => $theirBoss->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->actingAs($theirBoss)->get('/api/v1/crm/salary/documents/' . $uuid)->assertNotFound();
        $this->actingAs($theirBoss)->deleteJson('/api/v1/crm/salary/documents/' . $uuid)->assertNotFound();
    }

    public function test_something_that_could_run_is_not_a_bank_document(): void
    {
        // The whole point of keeping these here rather than in a share.
        $this->attach([], UploadedFile::fake()->create('statement.pdf.exe', 20))->assertStatus(422);
        $this->assertSame(0, SalaryDocument::count());
    }

    public function test_removing_one_takes_the_file_with_it(): void
    {
        $uuid = $this->attach()->assertCreated()->json('data.uuid');
        $path = SalaryDocument::firstOrFail()->path;

        $this->actingAs($this->adminUser)->deleteJson('/api/v1/crm/salary/documents/' . $uuid)->assertOk();

        Storage::disk('local')->assertMissing($path);
        $this->assertSame([], $this->register()['documents']);
    }
}
