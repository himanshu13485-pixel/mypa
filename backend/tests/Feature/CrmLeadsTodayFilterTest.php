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
 * "Today's follow-ups": one button for what a salesperson owes today.
 *
 * Today's calls, and every lead marked urgent whatever its date - an
 * urgent lead is urgent now, which is the entire point of the flag, and a
 * list of today's work would be the strangest place to leave it out.
 *
 * Deliberately not the backlog: "Due today" beside it already answers
 * "everything owed up to tonight". This one answers "what is on today".
 */
class CrmLeadsTodayFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = User::factory()->create(['email' => 'boss@acme.test']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->lead('Due today', now()->setTime(15, 0));
        $this->lead('Overdue since Monday', now()->subDays(3));
        $this->lead('Next week', now()->addWeek());
        $this->lead('Urgent, called next month', now()->addMonth(), urgent: true);
        $this->lead('Urgent, no call booked', null, urgent: true);
    }

    private int $nextNo = 1;

    private function lead(string $company, $followUp, bool $urgent = false): Lead
    {
        return Lead::create([
            'organization_id' => $this->org->id,
            // Numbered by the controller in real life; here, just in order.
            'lead_no' => $this->nextNo++,
            'company_name' => $company,
            'contact_person' => 'Someone',
            'mobile' => '9876543210',
            'source' => 'WhatsApp',
            'lead_status' => $followUp ? 'follow_up' : 'new',
            'follow_up_at' => $followUp,
            'is_urgent' => $urgent,
        ]);
    }

    /** @return array<string> */
    private function listed(string $query): array
    {
        return collect($this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson('/api/v1/crm/leads?' . $query)
            ->assertOk()
            ->json('data'))
            ->pluck('company_name')->sort()->values()->all();
    }

    public function test_it_shows_todays_calls_and_every_urgent_lead(): void
    {
        $this->assertSame([
            'Due today',
            'Urgent, called next month',
            'Urgent, no call booked',
        ], $this->listed('today=1'));
    }

    public function test_it_leaves_the_backlog_to_the_button_beside_it(): void
    {
        // Overdue is "Due today"'s job, not this one's.
        $this->assertNotContains('Overdue since Monday', $this->listed('today=1'));
        $this->assertContains('Overdue since Monday', $this->listed('due=1'));
    }

    public function test_due_today_is_unchanged_by_any_of_this(): void
    {
        // Still the backlog: overdue and today, and urgency does not let a
        // lead in that is not owed a call yet.
        $this->assertSame(['Due today', 'Overdue since Monday'], $this->listed('due=1'));
    }

    public function test_a_quiet_lead_with_nothing_booked_stays_out_of_it(): void
    {
        $this->lead('Nobody has called them', null);

        $this->assertNotContains('Nobody has called them', $this->listed('today=1'));
    }

    public function test_the_headline_counts_what_the_button_is_showing(): void
    {
        $totals = $this->actingAs($this->adminUser)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson('/api/v1/crm/leads?today=1')
            ->assertOk()->json('totals');

        $this->assertSame(3, $totals['count']);
    }
}
