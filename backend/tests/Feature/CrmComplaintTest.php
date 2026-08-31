<?php

namespace Tests\Feature;

use App\Models\Crm\Complaint;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The Complaint Management System.
 *
 * What matters: a complaint is filed under the company's own words and not
 * invented ones, it carries two conversations that never mix, the clock on
 * it is read rather than typed, and it cannot be closed without saying what
 * was done and whose mistake it was.
 */
class CrmComplaintTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $careUser;
    protected User $strangerUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $care;
    protected Member $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->careUser = $this->makeUser('care@acme.test');
        $this->strangerUser = $this->makeUser('sales@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = ['complaints' => ['view', 'create', 'edit'], 'clients' => ['view']];
        $this->care = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->careUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        $this->stranger = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->strangerUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function raise(User $who, array $overrides = []): string
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/complaints', $overrides + [
            'complained_on' => now()->toDateString(),
            'company_name' => 'Bhavya Steel',
            'contact_person' => 'ramesh patel',
            'mobile' => '9876543210',
            'source' => 'Client',
            'subject' => 'Data Is Incomplete',
            'complaint_type' => 'Data',
            'mode' => 'Call',
            'details' => 'Half the shipment rows are missing for July.',
        ])->assertCreated()->json('data.uuid');
    }

    // ---- Filing it ----------------------------------------------------------

    public function test_a_complaint_is_numbered_dated_and_starts_unattended(): void
    {
        $body = $this->actingAs($this->careUser)->postJson('/api/v1/crm/complaints', [
            'complained_on' => now()->toDateString(),
            'company_name' => 'bhavya steel',
            'subject' => 'Data Is Incomplete',
            'details' => 'Rows missing.',
        ])->assertCreated()->json('data');

        $this->assertSame('CMS-1', $body['cms_no']);
        $this->assertSame('unattended', $body['status']);
        $this->assertSame('Bhavya Steel', $body['company_name']);
        $this->assertSame($this->careUser->name, $body['raised_by']);
        // The promise: the company's standing 48 hours unless told otherwise.
        $this->assertSame(now()->addHours(48)->format('Y-m-d H'), substr((string) $body['due_at'], 0, 13));
        $this->assertFalse($body['overdue']);

        // The run is the company's own.
        $this->raise($this->careUser);
        $this->assertSame('CMS-2', Complaint::orderByDesc('id')->first()->cms_no);
    }

    public function test_the_subject_must_come_from_the_companys_own_list(): void
    {
        $this->actingAs($this->careUser)->postJson('/api/v1/crm/complaints', [
            'complained_on' => now()->toDateString(),
            'company_name' => 'Bhavya Steel',
            'subject' => 'Whatever I feel like calling it',
            'details' => 'Something.',
        ])->assertStatus(422);

        // A complaint with no subject at all is not a complaint anyone can act on.
        $this->actingAs($this->careUser)->postJson('/api/v1/crm/complaints', [
            'complained_on' => now()->toDateString(),
            'company_name' => 'Bhavya Steel',
            'details' => 'Something.',
        ])->assertStatus(422);

        $this->assertSame(0, Complaint::count());

        // The Admin adds the word, and it is then a legitimate filing.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/complaint-options', [
            'complaint_sources' => ['Client'],
            'complaint_subjects' => ['Data Is Incomplete', 'Whatever I feel like calling it'],
            'complaint_types' => ['Data'],
            'complaint_modes' => ['Call'],
            'resolve_hours' => 24,
        ])->assertOk();

        $this->actingAs($this->careUser)->postJson('/api/v1/crm/complaints', [
            'complained_on' => now()->toDateString(),
            'company_name' => 'Bhavya Steel',
            'subject' => 'Whatever I feel like calling it',
        ])->assertCreated()
            // And the new promise applies to complaints filed after it.
            ->assertJsonPath('data.status', 'unattended');
        $this->assertSame(
            now()->addHours(24)->format('Y-m-d H'),
            substr((string) Complaint::firstOrFail()->due_at, 0, 13),
        );

        // An employee cannot rewrite the company's list.
        $this->actingAs($this->careUser)->putJson('/api/v1/crm/masters/complaint-options', [
            'complaint_sources' => ['X'], 'complaint_subjects' => ['X'],
            'complaint_types' => ['X'], 'complaint_modes' => ['X'],
        ])->assertForbidden();
    }

    // ---- The two conversations ----------------------------------------------

    public function test_the_client_thread_and_the_office_thread_stay_apart(): void
    {
        $uuid = $this->raise($this->careUser);

        $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/replies", [
            'audience' => 'internal',
            'body' => 'Backend says the July import failed silently. Do not tell them that.',
        ])->assertCreated();

        $body = $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/replies", [
            'audience' => 'client',
            'body' => 'We are re-running your July data and will confirm by tomorrow.',
        ])->assertCreated()->json('data');

        $threads = collect($body['replies'])->groupBy('audience');
        $this->assertCount(1, $threads['internal']);
        $this->assertCount(1, $threads['client']);
        $this->assertSame($this->careUser->name, $threads['client'][0]['author']);

        // Answering the client is what stops the "has anyone replied?" clock,
        // and the first word of any kind drags it into progress.
        $this->assertSame('in_progress', $body['status']);
        $this->assertNotNull($body['first_response_at']);
        $this->assertNotNull($body['in_progress_at']);
    }

    public function test_an_internal_note_does_not_count_as_answering_the_client(): void
    {
        $uuid = $this->raise($this->careUser);

        $body = $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/replies", [
            'audience' => 'internal', 'body' => 'Checking with backend.',
        ])->assertCreated()->json('data');

        $this->assertSame('in_progress', $body['status']);
        $this->assertNull($body['first_response_at']);
    }

    public function test_a_word_in_the_wrong_thread_can_be_taken_back(): void
    {
        $uuid = $this->raise($this->careUser);
        $reply = $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/replies", [
            'audience' => 'client', 'body' => 'Backend broke the import again.',
        ])->assertCreated()->json('reply_uuid');

        // A colleague who can see the complaint but did not write the line
        // cannot unwrite it — only the author, or a manager.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/complaints/{$uuid}/allocate", [
            'allocated_to' => $this->stranger->uuid,
        ])->assertOk();
        $this->actingAs($this->strangerUser)
            ->deleteJson("/api/v1/crm/complaints/{$uuid}/replies/{$reply}")->assertForbidden();

        $this->actingAs($this->careUser)
            ->deleteJson("/api/v1/crm/complaints/{$uuid}/replies/{$reply}")
            ->assertOk()->assertJsonCount(0, 'data.replies');
    }

    // ---- Whose desk, and whose mistake --------------------------------------

    public function test_a_manager_puts_the_complaint_on_somebody_and_they_are_told(): void
    {
        Notification::fake();
        $uuid = $this->raise($this->adminUser);

        $body = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/complaints/{$uuid}/allocate", [
            'allocated_to' => $this->care->uuid,
            'key_responsible' => $this->stranger->uuid,
            'priority' => 'high',
        ])->assertOk()->json('data');

        // Holding a complaint is not the same as being able to hand it on.
        $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/allocate", [
            'allocated_to' => $this->stranger->uuid,
        ])->assertForbidden();

        $this->assertSame($this->careUser->name, $body['allocated_to']);
        $this->assertSame($this->adminUser->name, $body['allocated_by']);
        $this->assertSame($this->strangerUser->name, $body['key_responsible']);
        $this->assertSame('high', $body['priority']);

        Notification::assertSentTo($this->careUser, CrmNotification::class);
    }

    public function test_closing_demands_a_resolution_and_an_owner_for_the_mistake(): void
    {
        $uuid = $this->raise($this->careUser);

        // Nothing said about what was done.
        $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/status", [
            'status' => 'closed_satisfied',
        ])->assertStatus(422);

        // Said, but nobody owns it.
        $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/status", [
            'status' => 'closed_satisfied', 'resolution' => 'Re-ran the import.',
        ])->assertStatus(422);

        // An executive error is a person, not a category.
        $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/status", [
            'status' => 'closed_satisfied', 'resolution' => 'Re-ran the import.',
            'final_error_type' => 'executive',
        ])->assertStatus(422);

        $body = $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/status", [
            'status' => 'closed_satisfied',
            'resolution' => 'Re-ran the July import; client confirmed the rows are there.',
            'final_error_type' => 'backend',
            'final_error_note' => 'Import job failed without raising anything.',
        ])->assertOk()->json('data');

        $this->assertSame('closed_satisfied', $body['status']);
        $this->assertSame('Closed With Satisfaction', $body['status_label']);
        $this->assertSame('Backend Error', $body['final_error_label']);
        $this->assertNotNull($body['closed_at']);
        $this->assertSame($this->careUser->name, $body['closed_by']);

        // Reopening puts the clock back on and clears the closing stamp.
        $reopened = $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$uuid}/status", [
            'status' => 'in_progress',
        ])->assertOk()->json('data');
        $this->assertNull($reopened['closed_at']);
        $this->assertSame('in_progress', $reopened['status']);
        // What was learned is not thrown away by reopening.
        $this->assertSame('Backend Error', $reopened['final_error_label']);
    }

    public function test_an_executive_error_names_the_executive_and_tells_them(): void
    {
        Notification::fake();
        $uuid = $this->raise($this->careUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/complaints/{$uuid}/status", [
            'status' => 'closed_dissatisfied',
            'resolution' => 'Client refused the correction and left.',
            'final_error_type' => 'executive',
            'final_error_member' => $this->stranger->uuid,
        ])->assertOk()
            ->assertJsonPath('data.final_error_label', 'Executive Error')
            ->assertJsonPath('data.final_error_member', $this->strangerUser->name);

        Notification::assertSentTo($this->strangerUser, CrmNotification::class);
    }

    // ---- Reading the wall ----------------------------------------------------

    public function test_the_summary_counts_the_clock_and_the_blame(): void
    {
        $open = $this->raise($this->careUser);
        $late = $this->raise($this->careUser);
        Complaint::where('uuid', $late)->update(['due_at' => now()->subDay()]);

        $done = $this->raise($this->careUser);
        $this->actingAs($this->careUser)->postJson("/api/v1/crm/complaints/{$done}/status", [
            'status' => 'closed_satisfied',
            'resolution' => 'Fixed.',
            'final_error_type' => 'client',
        ])->assertOk();

        $summary = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints')
            ->assertOk()->json('summary');

        $this->assertSame(3, $summary['count']);
        $this->assertSame(2, $summary['unattended']);
        $this->assertSame(1, $summary['closed_satisfied']);
        $this->assertSame(1, $summary['overdue']);
        $this->assertSame(1, collect($summary['by_error_type'])->firstWhere('key', 'client')['count']);

        // Overdue is a reading of the clock, so it can be asked for by name.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?status=overdue')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $late);

        // And the open ones, whatever their status.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?status=open')
            ->assertOk()->assertJsonCount(2, 'data');

        unset($open);
    }

    public function test_the_log_holds_every_settled_complaint_and_who_owned_the_error(): void
    {
        $this->raise($this->careUser);                       // left open
        $happy = $this->raise($this->careUser);
        $unhappy = $this->raise($this->careUser);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/complaints/{$happy}/status", [
            'status' => 'closed_satisfied',
            'resolution' => 'Re-ran the import.',
            'final_error_type' => 'backend',
        ])->assertOk();
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/complaints/{$unhappy}/status", [
            'status' => 'closed_dissatisfied',
            'resolution' => 'Client left.',
            'final_error_type' => 'executive',
            'final_error_member' => $this->stranger->uuid,
        ])->assertOk();

        // The log is everything settled, however it ended — never the open one.
        $log = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?status=closed')->assertOk();
        $log->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            [$happy, $unhappy],
            collect($log->json('data'))->pluck('uuid')->all(),
        );

        $summary = $log->json('summary');
        $this->assertSame(1, $summary['closed_satisfied']);
        $this->assertSame(1, $summary['closed_dissatisfied']);
        // Mistakes traced to a person, which is the whole point of the log.
        $this->assertSame(
            [['name' => $this->strangerUser->name, 'count' => 1]],
            $summary['by_error_member'],
        );

        // Narrowed by how it ended, by the error, and by who owned it.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?status=closed_dissatisfied')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $unhappy);
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?status=closed&final_error_type=backend')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $happy);
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/complaints?status=closed&error_member=' . $this->stranger->uuid)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $unhappy);

        // And by the day it was closed.
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/complaints?status=closed&closed_from=' . now()->addDay()->toDateString())
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/complaints?status=closed&closed_from=' . now()->toDateString())
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_search_finds_a_complaint_by_every_thread_of_it(): void
    {
        $this->raise($this->careUser);
        $this->raise($this->careUser, ['company_name' => 'Surat Textiles', 'subject' => 'Service Delay', 'mobile' => '9000000001']);

        foreach ([
            'search=Surat', 'company=Surat', 'subject=Service Delay',
            'mobile=9000000001', 'cms_no=CMS-2',
        ] as $q) {
            $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?' . $q)
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.company_name', 'Surat Textiles');
        }

        // By the person who logged it — the old "Search By User" box.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?user=' . $this->care->uuid)
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints?user=' . $this->stranger->uuid)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_employee_sees_only_the_complaints_that_are_theirs(): void
    {
        $mine = $this->raise($this->careUser);
        $theirs = $this->raise($this->strangerUser);

        $this->actingAs($this->careUser)->getJson('/api/v1/crm/complaints')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', $mine);

        $this->actingAs($this->careUser)->getJson('/api/v1/crm/complaints/' . $theirs)->assertNotFound();

        // Allocated it, they see it.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/complaints/{$theirs}/allocate", [
            'allocated_to' => $this->care->uuid,
        ])->assertOk();
        $this->actingAs($this->careUser)->getJson('/api/v1/crm/complaints/' . $theirs)->assertOk();

        // The Admin sees the whole floor either way.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_options_payload_carries_the_lists_and_the_head_count(): void
    {
        $this->raise($this->careUser);

        $data = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/complaints/options')
            ->assertOk()->json('data');

        $this->assertContains('Data Is Incomplete', $data['subjects']);
        $this->assertSame('Executive Error', $data['error_types']['executive']);
        $this->assertSame('Closed With Dissatisfaction', $data['statuses']['closed_dissatisfied']);
        $this->assertTrue($data['can_allocate']);

        $care = collect($data['members'])->firstWhere('uuid', $this->care->uuid);
        $this->assertSame(1, $care['raised']);

        // An employee is told they cannot hand work out.
        $this->actingAs($this->careUser)->getJson('/api/v1/crm/complaints/options')
            ->assertOk()->assertJsonPath('data.can_allocate', false);
    }
}
