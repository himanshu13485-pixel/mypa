<?php

namespace Tests\Feature;

use App\Models\Crm\Lead;
use App\Models\Crm\LeadAccessRequest;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lead Duplication: the person behind a lead has one mobile, one phone, one
 * e-mail — any of the three matching means it IS the same lead. A duplicate
 * is never a second row: the Admin shares the original, transfers it, or
 * rejects the ask.
 */
class CrmLeadDuplicationTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $e1User;
    protected User $e2User;
    protected Organization $org;
    protected Member $admin;
    protected Member $e1;
    protected Member $e2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->e1User = $this->makeUser('e1@acme.test');
        $this->e2User = $this->makeUser('e2@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = ['leads' => ['view', 'create', 'edit']];
        $this->e1 = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->e1User->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        $this->e2 = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->e2User->id, 'crm_role' => 'employee',
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

    private function lead(User $who, array $payload = [])
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/leads', $payload + [
            'company_name' => 'Bhavya Steel',
            'mobile' => '98250 12345',
            'email' => 'jaimin@bhavya.test',
            'assigned_member_uuid' => $who === $this->adminUser ? $this->e1->uuid : null,
        ]);
    }

    // ---- Recognising the same person ----------------------------------------

    public function test_any_one_matching_contact_is_lead_duplication(): void
    {
        $this->lead($this->adminUser)->assertCreated();

        // Same mobile, sloppier typing, different everything else.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Some Other Name', 'mobile' => '+91-9825012345',
        ])->assertStatus(422)->assertJsonPath('can_decide', true);

        // Same e-mail alone is enough too.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Third Name', 'email' => 'JAIMIN@bhavya.test',
        ])->assertStatus(422);

        // The mobile matching another lead's PHONE is still the same person.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Fourth Name', 'phone' => '9825012345',
        ])->assertStatus(422);

        // Different contacts are a different person.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Fresh Lead', 'mobile' => '9000000001',
        ])->assertCreated();

        $this->assertSame(2, Lead::count());
    }

    public function test_the_admin_is_shown_the_existing_lead_to_act_on(): void
    {
        $this->lead($this->adminUser)->assertCreated();

        $response = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Duplicate Try', 'mobile' => '9825012345',
            'assigned_member_uuid' => $this->e2->uuid,
        ])->assertStatus(422)->json();

        $this->assertStringContainsString('Lead Duplication', $response['message']);
        $this->assertSame(1, $response['duplicate']['lead_no']);
        $this->assertSame($this->e1User->name, $response['duplicate']['owner']);
        // No request queue for the Admin — they decide on the spot.
        $this->assertSame(0, LeadAccessRequest::count());
    }

    public function test_an_employees_duplicate_becomes_a_request_for_the_admin(): void
    {
        Notification::fake();
        $this->lead($this->adminUser)->assertCreated();   // E-1's lead

        $this->actingAs($this->e2User)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Bhavya Steel', 'mobile' => '9825012345',
        ])->assertStatus(422)->assertJsonPath('request_pending', true);

        $this->assertSame(1, Lead::count());
        $this->assertSame('pending', LeadAccessRequest::firstOrFail()->status);
        Notification::assertSentTo($this->adminUser, CrmNotification::class);

        // Asking twice does not stack requests.
        $this->actingAs($this->e2User)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Bhavya Steel', 'email' => 'jaimin@bhavya.test',
        ])->assertStatus(422);
        $this->assertSame(1, LeadAccessRequest::count());
    }

    // ---- The Admin's three ways ---------------------------------------------

    public function test_share_puts_the_lead_in_both_lists(): void
    {
        $this->lead($this->adminUser)->assertCreated();
        $this->actingAs($this->e2User)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Bhavya Steel', 'mobile' => '9825012345',
        ])->assertStatus(422);

        $uuid = LeadAccessRequest::firstOrFail()->uuid;
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/lead-requests/{$uuid}/decide", [
            'action' => 'share',
        ])->assertOk();

        $this->assertSame('shared', LeadAccessRequest::firstOrFail()->status);
        // One lead, now in E-2's window too, marked with the share.
        $this->actingAs($this->e2User)->getJson('/api/v1/crm/leads')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shared_with.0.name', $this->e2User->name);
        // E-1 still holds it.
        $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads')
            ->assertOk()->assertJsonPath('data.0.assigned_member.name', $this->e1User->name);
    }

    public function test_transfer_moves_the_lead_to_the_requester(): void
    {
        $this->lead($this->adminUser)->assertCreated();
        $this->actingAs($this->e2User)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Bhavya Steel', 'mobile' => '9825012345',
        ])->assertStatus(422);

        $uuid = LeadAccessRequest::firstOrFail()->uuid;
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/lead-requests/{$uuid}/decide", [
            'action' => 'transfer', 'note' => 'E-2 already knows the buyer.',
        ])->assertOk();

        $this->assertSame($this->e2->id, Lead::firstOrFail()->assigned_member_id);
        // E-1 no longer sees it; E-2 does.
        $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->e2User)->getJson('/api/v1/crm/leads')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reject_leaves_the_lead_where_it_was(): void
    {
        $this->lead($this->adminUser)->assertCreated();
        $this->actingAs($this->e2User)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Bhavya Steel', 'mobile' => '9825012345',
        ])->assertStatus(422);

        $uuid = LeadAccessRequest::firstOrFail()->uuid;
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/lead-requests/{$uuid}/decide", [
            'action' => 'reject', 'note' => 'E-1 is mid-negotiation.',
        ])->assertOk();

        $this->assertSame('rejected', LeadAccessRequest::firstOrFail()->status);
        $this->assertSame($this->e1->id, Lead::firstOrFail()->assigned_member_id);
        $this->actingAs($this->e2User)->getJson('/api/v1/crm/leads')->assertOk()->assertJsonCount(0, 'data');

        // Deciding twice is refused, and deciding is not the requester's.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/lead-requests/{$uuid}/decide", [
            'action' => 'share',
        ])->assertStatus(422);
        $this->actingAs($this->e2User)->postJson("/api/v1/crm/lead-requests/{$uuid}/decide", [
            'action' => 'share',
        ])->assertForbidden();
    }

    // ---- Direct transfer / share, and the guard rails -----------------------

    public function test_the_admin_can_transfer_or_share_a_lead_directly(): void
    {
        $this->lead($this->adminUser)->assertCreated();
        $lead = Lead::firstOrFail();

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leads/{$lead->uuid}/share", [
            'member_uuids' => [$this->e2->uuid],
        ])->assertOk()->assertJsonPath('data.shared_with.0.name', $this->e2User->name);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leads/{$lead->uuid}/transfer", [
            'to_member_uuid' => $this->e2->uuid,
        ])->assertOk()->assertJsonPath('data.assigned_member.name', $this->e2User->name);

        // An employee may do neither, whatever rights they hold.
        $this->actingAs($this->e2User)->postJson("/api/v1/crm/leads/{$lead->uuid}/transfer", [
            'to_member_uuid' => $this->e1->uuid,
        ])->assertForbidden();
        $this->actingAs($this->e2User)->postJson("/api/v1/crm/leads/{$lead->uuid}/share", [
            'member_uuids' => [$this->e1->uuid],
        ])->assertForbidden();
    }

    public function test_editing_contacts_onto_another_lead_is_refused(): void
    {
        $this->lead($this->adminUser)->assertCreated();
        $other = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Other Person', 'mobile' => '9000000001',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/leads/{$other}", [
            'company_name' => 'Other Person', 'mobile' => '9825012345',
        ])->assertStatus(422);

        // Its own contacts still save fine.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/leads/{$other}", [
            'company_name' => 'Other Person', 'mobile' => '9000000001', 'amount' => 5000,
        ])->assertOk();
    }

    // ---- The follow-up nag --------------------------------------------------

    public function test_the_due_list_holds_only_arrived_follow_ups_in_the_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-08-29 11:00:00');

        // Due an hour ago, allocated to E-1.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Due Lead', 'mobile' => '9000000001',
            'lead_status' => 'follow_up', 'follow_up_at' => '2026-08-29 10:00',
            'assigned_member_uuid' => $this->e1->uuid,
        ])->assertCreated();
        // Booked for tomorrow: not yet.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Later Lead', 'mobile' => '9000000002',
            'lead_status' => 'follow_up', 'follow_up_at' => '2026-08-30 10:00',
            'assigned_member_uuid' => $this->e1->uuid,
        ])->assertCreated();

        $due = $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads-due')->assertOk()->json();
        $this->assertCount(1, $due['data']);
        $this->assertSame('Due Lead', $due['data'][0]['company_name']);
        $this->assertSame(60, $due['data'][0]['overdue_minutes']);
        $this->assertSame(15, $due['alert_minutes']);

        // E-2 holds neither lead, so nothing nags them.
        $this->actingAs($this->e2User)->getJson('/api/v1/crm/leads-due')
            ->assertOk()->assertJsonCount(0, 'data');

        // Attending it — a follow-up with a future date — clears the nag.
        $uuid = Lead::where('company_name', 'Due Lead')->firstOrFail()->uuid;
        $this->actingAs($this->e1User)->postJson("/api/v1/crm/leads/{$uuid}/followup", [
            'note' => 'Spoke; call again Monday.', 'follow_up_at' => '2026-09-01 10:00',
        ])->assertCreated();
        $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads-due')
            ->assertOk()->assertJsonCount(0, 'data');

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_the_admin_turns_the_alert_knob(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/lead-settings', [
            'alert_minutes' => 30,
        ])->assertOk();

        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads-due')
            ->assertOk()->assertJsonPath('alert_minutes', 30);

        // Out of range is refused; an employee cannot turn it at all.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/lead-settings', [
            'alert_minutes' => 2,
        ])->assertStatus(422);
        $this->actingAs($this->e1User)->putJson('/api/v1/crm/masters/lead-settings', [
            'alert_minutes' => 60,
        ])->assertForbidden();
    }

    // ---- The new-lead nag ---------------------------------------------------

    public function test_a_new_lead_nags_its_assignee_until_attended(): void
    {
        // A fresh lead lands on E-1's desk, unattended.
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Fresh Arrival', 'mobile' => '9000000021',
            'lead_status' => 'unattended',
            'assigned_member_uuid' => $this->e1->uuid,
        ])->assertCreated();

        $fresh = $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads-new')->assertOk()->json();
        $this->assertCount(1, $fresh['data']);
        $this->assertSame('Fresh Arrival', $fresh['data'][0]['company_name']);
        $this->assertSame(15, $fresh['alert_minutes']);

        // It arrived at E-1's desk, nobody else's.
        $this->actingAs($this->e2User)->getJson('/api/v1/crm/leads-new')
            ->assertOk()->assertJsonCount(0, 'data');

        // The new-lead knob turns on its own, without moving the follow-up one.
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/lead-settings', [
            'alert_minutes' => 20, 'new_alert_minutes' => 45,
        ])->assertOk();
        $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads-new')
            ->assertOk()->assertJsonPath('alert_minutes', 45);
        $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads-due')
            ->assertOk()->assertJsonPath('alert_minutes', 20);
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters/lead-settings')
            ->assertOk()->assertJsonPath('data.new_alert_minutes', 45);

        // Attending it — the first follow-up — hands it to the follow-up nag.
        $uuid = Lead::where('company_name', 'Fresh Arrival')->firstOrFail()->uuid;
        $this->actingAs($this->e1User)->postJson("/api/v1/crm/leads/{$uuid}/followup", [
            'note' => 'Called; sending the brochure.', 'follow_up_at' => now()->addDay()->format('Y-m-d H:i'),
        ])->assertCreated();
        $this->actingAs($this->e1User)->getJson('/api/v1/crm/leads-new')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_list_wears_duplication_as_the_status(): void
    {
        // A pair from before the guard existed, sharing a mobile — created
        // straight in the table, as legacy rows were.
        foreach ([['E1 Lead', $this->e1->id], ['E2 Lead', $this->e2->id]] as $i => [$name, $memberId]) {
            Lead::create([
                'organization_id' => $this->org->id, 'lead_no' => $i + 1,
                'company_name' => $name, 'mobile' => '9810237129',
                'assigned_member_id' => $memberId, 'lead_status' => 'unattended',
                'created_by' => $this->adminUser->id,
            ]);
        }
        Lead::create([
            'organization_id' => $this->org->id, 'lead_no' => 3,
            'company_name' => 'Clean Lead', 'mobile' => '9000000009',
            'assigned_member_id' => $this->e1->id, 'lead_status' => 'unattended',
            'created_by' => $this->adminUser->id,
        ]);

        $rows = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads')
            ->assertOk()->json('data'))->keyBy('company_name');

        // The FIRST lead with the contact is the original: it stays clean.
        // Only the later arrival wears Duplicate, pointing back at it.
        $this->assertFalse($rows['E1 Lead']['is_duplicate']);
        $this->assertTrue($rows['E2 Lead']['is_duplicate']);
        $this->assertSame(1, $rows['E2 Lead']['duplicate_of']);
        $this->assertFalse($rows['Clean Lead']['is_duplicate']);

        // A pending request marks its lead too, and clears once decided.
        $this->actingAs($this->e2User)->postJson('/api/v1/crm/leads', [
            'company_name' => 'Try Again', 'mobile' => '9000000009',
        ])->assertStatus(422);

        $rows = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads')
            ->assertOk()->json('data'))->keyBy('company_name');
        $this->assertTrue($rows['Clean Lead']['has_pending_request']);

        $uuid = LeadAccessRequest::firstOrFail()->uuid;
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/lead-requests/{$uuid}/decide", [
            'action' => 'reject',
        ])->assertOk();

        $rows = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/leads')
            ->assertOk()->json('data'))->keyBy('company_name');
        $this->assertFalse($rows['Clean Lead']['has_pending_request']);
    }

    public function test_contact_changes_are_the_admins(): void
    {
        $this->lead($this->adminUser)->assertCreated();   // E-1's lead
        $uuid = Lead::firstOrFail()->uuid;

        // E-1 may work the lead — status, amount, subject — freely…
        $this->actingAs($this->e1User)->putJson("/api/v1/crm/leads/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'mobile' => '98250 12345',
            'email' => 'jaimin@bhavya.test', 'amount' => 5000, 'subject' => 'High Rate',
        ])->assertOk();

        // …but not change who the lead IS.
        $this->actingAs($this->e1User)->putJson("/api/v1/crm/leads/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'mobile' => '9111111111',
        ])->assertStatus(422);

        // The Admin can.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/leads/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'mobile' => '9111111111',
        ])->assertOk();
        $this->assertSame('9111111111', Lead::firstOrFail()->mobile);
    }

    public function test_the_admin_edits_the_words_a_lead_is_described_in(): void
    {
        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/masters/lead-options', [
            'lead_sources' => ['Website', 'Exhibition', 'Partner Referral'],
            'lead_subjects' => ['Growth Plan Enquiry', 'Renewal'],
        ])->assertOk();

        // Every dropdown follows, because masters reads the same lists.
        $masters = $this->actingAs($this->e1User)->getJson('/api/v1/crm/masters')->assertOk()->json('data');
        $this->assertSame(['Website', 'Exhibition', 'Partner Referral'], $masters['lead_sources']);
        $this->assertSame(['Growth Plan Enquiry', 'Renewal'], $masters['lead_subjects']);

        // An employee cannot rewrite the company's words.
        $this->actingAs($this->e1User)->putJson('/api/v1/crm/masters/lead-options', [
            'lead_sources' => ['X'], 'lead_subjects' => ['Y'],
        ])->assertForbidden();
    }
}
