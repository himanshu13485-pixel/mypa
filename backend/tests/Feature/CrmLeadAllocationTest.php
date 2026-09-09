<?php

namespace Tests\Feature;

use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who allocated a lead, and finding leads by their two dates.
 *
 * The list said whose desk a lead sits on and nothing about how it got
 * there, so "who gave me this?" — the first question asked about a lead
 * that should never have been handed over — could only be answered by
 * opening the activity log.
 *
 * And the two dates a lead has are two different questions: when it came
 * in, and when it is next owed a call. The server could already narrow by
 * either; nothing on the screen asked.
 */
class CrmLeadAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $adminUser;
    private Member $admin;
    private Member $priya;
    private Member $rahul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);

        $this->adminUser = $this->makeUser('admin@acme.test');
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->priya = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('priya@acme.test', 'Priya Nair')->id,
            'crm_role' => 'employee', 'status' => 'active', 'is_salesperson' => true,
            'rights' => ['leads' => ['view', 'create', 'edit']],
        ]);
        $this->rahul = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->makeUser('rahul@acme.test', 'Rahul Verma')->id,
            'crm_role' => 'employee', 'status' => 'active', 'is_salesperson' => true,
        ]);
    }

    private function makeUser(string $email, string $name = 'Acme Admin'): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function as(?User $user = null)
    {
        return $this->actingAs($user ?? $this->adminUser)->withHeader('X-Crm-Org', $this->org->uuid);
    }

    /** The row for one lead as the list serves it. */
    private function row(string $company): array
    {
        return collect($this->as()->getJson('/api/v1/crm/leads')->assertOk()->json('data'))
            ->firstWhere('company_name', $company);
    }

    public function test_a_lead_is_allocated_by_whoever_entered_it(): void
    {
        $this->as()->postJson('/api/v1/crm/leads', [
            'company_name' => 'Meridian Motors',
            'assigned_member_uuid' => $this->priya->uuid,
        ])->assertCreated();

        $row = $this->row('Meridian Motors');
        $this->assertSame('Priya Nair', $row['assigned_member']['name']);
        $this->assertSame('Acme Admin', $row['allocated_by']);
    }

    public function test_a_transfer_makes_the_person_who_moved_it_the_allocator(): void
    {
        // Entered by Priya, so it starts on her desk and in her name.
        $uuid = $this->as(User::where('email', 'priya@acme.test')->first())
            ->postJson('/api/v1/crm/leads', ['company_name' => 'Meridian Motors'])
            ->assertCreated()->json('data.uuid');

        $this->assertSame('Priya Nair', $this->row('Meridian Motors')['allocated_by']);

        // The admin moves it on, and is now the one who allocated it.
        $this->as()->postJson("/api/v1/crm/leads/{$uuid}/transfer", [
            'to_member_uuid' => $this->rahul->uuid,
            'note' => 'Rahul knows this account.',
        ])->assertOk();

        $row = $this->row('Meridian Motors');
        $this->assertSame('Rahul Verma', $row['assigned_member']['name']);
        $this->assertSame('Acme Admin', $row['allocated_by']);
    }

    public function test_the_two_dates_are_asked_separately(): void
    {
        // One lead that came in in July and is called back in October, and
        // one the other way about, so neither filter can pass by accident.
        $july = $this->as()->postJson('/api/v1/crm/leads', [
            'company_name' => 'July Intake',
            'lead_status' => 'follow_up',
            'follow_up_at' => '2026-10-15 11:00',
        ])->assertCreated()->json('data.uuid');
        Lead::where('uuid', $july)->update(['created_at' => '2026-07-10 09:00']);

        $september = $this->as()->postJson('/api/v1/crm/leads', [
            'company_name' => 'September Intake',
            'lead_status' => 'follow_up',
            'follow_up_at' => '2026-09-20 11:00',
        ])->assertCreated()->json('data.uuid');
        Lead::where('uuid', $september)->update(['created_at' => '2026-09-05 09:00']);

        // By when they arrived.
        $this->assertSame(
            ['July Intake'],
            $this->found('date_from=2026-07-01&date_to=2026-07-31'),
        );

        // By when they are next owed a call — a different answer over the
        // same two leads, which is the whole point of asking twice.
        $this->assertSame(
            ['July Intake'],
            $this->found('follow_up_from=2026-10-01&follow_up_to=2026-10-31'),
        );
        $this->assertSame(
            ['September Intake'],
            $this->found('follow_up_from=2026-09-01&follow_up_to=2026-09-30'),
        );

        // And together, which is how a list of "this month's intake, due
        // this week" is arrived at.
        $this->assertSame([], $this->found('date_from=2026-09-01&follow_up_to=2026-09-19'));
    }

    /** The companies a query string returns, oldest lead number first. */
    private function found(string $query): array
    {
        return collect($this->as()->getJson('/api/v1/crm/leads?' . $query)->assertOk()->json('data'))
            ->pluck('company_name')->sort()->values()->all();
    }

    public function test_the_export_carries_the_same_dates_and_the_allocator(): void
    {
        $uuid = $this->as()->postJson('/api/v1/crm/leads', [
            'company_name' => 'Meridian Motors',
            'assigned_member_uuid' => $this->priya->uuid,
        ])->assertCreated()->json('data.uuid');
        Lead::where('uuid', $uuid)->update(['created_at' => '2026-07-10 09:00']);

        $this->as()->postJson('/api/v1/crm/leads', ['company_name' => 'Kalyani Engineering'])->assertCreated();

        $csv = $this->as()->get('/api/v1/crm/exports/leads?date_from=2026-07-01&date_to=2026-07-31')
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('Allocated by', $csv);
        $this->assertStringContainsString('Meridian Motors', $csv);
        $this->assertStringNotContainsString('Kalyani Engineering', $csv, 'the export narrows as the screen does');
    }
}
