<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The thing tasks are actually used for: accounts telling a salesperson that
 * something on one of their invoices is waiting on them, the two of them
 * going back and forth about it, and the reminder following whoever owes the
 * next word until it is finished.
 */
class CrmTaskPendencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $accountsUser;
    protected User $salesUser;
    protected Organization $org;
    protected Member $accounts;
    protected Member $sales;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->accountsUser = $this->makeUser('accounts@acme.test', 'Meera');
        $this->salesUser = $this->makeUser('sales@acme.test', 'Vishal');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->accounts = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->accountsUser->id, 'crm_role' => 'admin',
        ]);
        $this->sales = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->salesUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->accounts->id,
            'rights' => ['clients' => ['view', 'create'], 'invoices' => ['view', 'create'], 'tasks' => ['view']],
        ]);

        $this->issuingCompanyId = $this->actingAs($this->accountsUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');
    }

    private function makeUser(string $email, string $name): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        return $user;
    }

    private function invoice(): array
    {
        $client = $this->actingAs($this->salesUser)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Labquartz Silica Pvt Ltd',
            'contact_person' => 'Priyanshu',
            'email' => 'priyanshu@labquartz.test',
            'mobile' => '9000000042',
        ])->assertCreated()->json('data.uuid');

        return $this->actingAs($this->salesUser)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $client,
            'invoice_date' => '2026-08-31',
            'due_date' => '2026-12-31',
            'client_category' => 'new',
            'pricing_tier' => 'regular',
            'terms_of_payment' => '100% advance',
            'subscription_type' => 'online',
            'dispatch_status' => 'pending',
            'items' => [[
                'membership' => 'Standard', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31',
                'plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 41760,
            ]],
        ])->assertCreated()->json('data');
    }

    public function test_the_document_search_finds_an_invoice_by_anything_in_front_of_you(): void
    {
        $invoice = $this->invoice();

        foreach ([$invoice['number'], 'Labquartz', 'Priyanshu', 'priyanshu@labquartz', '9000000042'] as $term) {
            $found = $this->actingAs($this->accountsUser)
                ->getJson('/api/v1/crm/task-documents?search=' . urlencode($term))
                ->assertOk()->json('data');

            $this->assertSame($invoice['number'], $found[0]['number'], "searching for {$term}");
            $this->assertSame('Labquartz Silica Pvt Ltd', $found[0]['client']);
        }

        // Two characters is the floor; one would match the whole ledger.
        $this->actingAs($this->accountsUser)->getJson('/api/v1/crm/task-documents?search=L')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_pendency_travels_from_accounts_to_sales_and_back(): void
    {
        $invoice = $this->invoice();

        $task = $this->actingAs($this->accountsUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'PO copy missing',
            'description' => 'The client will not release payment without the PO on file.',
            'assigned_member_uuid' => $this->sales->uuid,
            'kind' => 'pendency',
            'invoice_uuid' => $invoice['uuid'],
            'priority' => 'high',
            'start_at' => '2026-09-11 10:00:00',
            'end_at' => '2026-09-15 18:00:00',
            'remind_every_days' => 2,
        ])->assertCreated();

        $task->assertJsonPath('message', 'Pendency raised.');
        $task->assertJsonPath('data.kind', 'pendency');
        $task->assertJsonPath('data.invoice.number', $invoice['number']);
        $task->assertJsonPath('data.awaiting', 'assignee');
        $uuid = $task->json('data.uuid');

        // It is asking the salesperson, and nobody else.
        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.my_turn', 'do')
            ->assertJsonPath('data.0.other_party', 'Meera');
        $this->actingAs($this->accountsUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(0, 'data');

        // The salesperson answers. Now it is accounts who are asked.
        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/tasks/{$uuid}/comments", [
            'body' => 'Asked the client this morning, they are sending it today.',
        ])->assertCreated();

        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->accountsUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.my_turn', 'review')
            ->assertJsonPath('data.0.other_party', 'Vishal');

        // Both sides read the same thread.
        $shown = $this->actingAs($this->accountsUser)->getJson("/api/v1/crm/tasks/{$uuid}")->assertOk();
        $shown->assertJsonPath('data.comments.0.by', 'Vishal');
        $shown->assertJsonPath('data.awaiting', 'assigner');
        $this->assertSame(1, $shown->json('data.comments_count'));

        $this->assertSame(1, ActivityLog::where('action', 'task.assigned')->count());
        $this->assertSame(1, ActivityLog::where('action', 'task.comment')->count());
    }

    public function test_putting_a_reminder_off_is_one_persons_decision(): void
    {
        $uuid = $this->actingAs($this->accountsUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'Bank details to confirm',
            'assigned_member_uuid' => $this->sales->uuid,
            'kind' => 'pendency',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(1, 'data');

        // "Not today."
        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/tasks/{$uuid}/snooze", ['hours' => 24])
            ->assertOk();

        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(0, 'data');

        // And it comes back when the day is up.
        Carbon::setTestNow(now()->addHours(25));
        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(1, 'data');
        Carbon::setTestNow();

        // Somebody on neither side of it cannot silence it.
        $strangerUser = $this->makeUser('stranger@acme.test', 'Nobody');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $strangerUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->accounts->id, 'rights' => ['tasks' => ['view']],
        ]);
        $this->actingAs($strangerUser)->postJson("/api/v1/crm/tasks/{$uuid}/snooze")->assertForbidden();
    }

    public function test_work_scheduled_for_later_does_not_start_asking_today(): void
    {
        $uuid = $this->actingAs($this->accountsUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'Renewal paperwork for the March quarter',
            'assigned_member_uuid' => $this->sales->uuid,
            'remind_at' => now()->addMonths(2)->toDateTimeString(),
            'remind_every_days' => 30,
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(0, 'data');

        Carbon::setTestNow(now()->addMonths(2)->addDay());
        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(1, 'data');

        /*
         * Dismissed without saying for how long, it waits the rhythm it was
         * given - a monthly job asks monthly, not again tomorrow.
         */
        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/tasks/{$uuid}/snooze")->assertOk();
        Carbon::setTestNow(now()->addDays(2));
        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(0, 'data');

        Carbon::setTestNow(now()->addDays(29));
        $this->actingAs($this->salesUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(1, 'data');
        Carbon::setTestNow();
    }

    public function test_a_task_changed_after_it_was_handed_over_says_so(): void
    {
        $uuid = $this->actingAs($this->accountsUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'Collect the signed copy',
            'assigned_member_uuid' => $this->sales->uuid,
            'due_at' => '2026-09-20 17:00:00',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->accountsUser)->getJson("/api/v1/crm/tasks/{$uuid}")
            ->assertOk()->assertJsonPath('data.edited_at', null);

        $this->actingAs($this->accountsUser)->putJson("/api/v1/crm/tasks/{$uuid}", [
            'title' => 'Collect the signed copy and the PO',
            'assigned_member_uuid' => $this->sales->uuid,
            'due_at' => '2026-09-18 17:00:00',
        ])->assertOk()->assertJsonPath('data.edited_by', 'Meera');

        $shown = $this->actingAs($this->salesUser)->getJson("/api/v1/crm/tasks/{$uuid}")->assertOk();
        $this->assertNotNull($shown->json('data.edited_at'));
        $this->assertSame('Meera', $shown->json('data.edited_by'));

        $logged = ActivityLog::where('action', 'task.updated')->firstOrFail();
        $this->assertContains('title', $logged->changes['changed']);
        $this->assertContains('due_at', $logged->changes['changed']);
    }

    public function test_finishing_a_task_stops_it_asking(): void
    {
        $uuid = $this->actingAs($this->accountsUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'Send the reconciliation',
            'assigned_member_uuid' => $this->sales->uuid,
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/tasks/{$uuid}/progress", [
            'status' => 'submitted', 'note' => 'Sent on mail.',
        ])->assertOk();

        // Submitted means accounts are asked to look at it.
        $this->actingAs($this->accountsUser)->getJson('/api/v1/crm/task-reminders')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->accountsUser)->postJson("/api/v1/crm/tasks/{$uuid}/review", [
            'verdict' => 'approve',
        ])->assertOk();

        foreach ([$this->accountsUser, $this->salesUser] as $who) {
            $this->actingAs($who)->getJson('/api/v1/crm/task-reminders')
                ->assertOk()->assertJsonCount(0, 'data');
        }

        $this->assertNull(Task::where('uuid', $uuid)->firstOrFail()->remind_at);
        $this->assertSame(1, ActivityLog::where('action', 'task.reviewed')->count());
    }

    public function test_only_the_two_people_on_a_task_may_talk_on_it(): void
    {
        $uuid = $this->actingAs($this->accountsUser)->postJson('/api/v1/crm/tasks', [
            'title' => 'Private matter',
            'assigned_member_uuid' => $this->sales->uuid,
        ])->assertCreated()->json('data.uuid');

        $strangerUser = $this->makeUser('other@acme.test', 'Someone Else');
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $strangerUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->accounts->id, 'rights' => ['tasks' => ['view']],
        ]);

        $this->actingAs($strangerUser)->postJson("/api/v1/crm/tasks/{$uuid}/comments", ['body' => 'Hello'])
            ->assertForbidden();
    }
}
