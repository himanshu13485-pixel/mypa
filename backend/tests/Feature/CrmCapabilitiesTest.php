<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The delicate acts — deleting a client, moving leads, settling money — are
 * the Admin's by the nature of the job, and any one of them can be handed to
 * a named employee. Plus the two things a returning client needs: a bulk
 * reshuffle, and a closed lead that remembers how it ended.
 */
class CrmCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $headUser;
    protected User $juniorUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $head;
    protected Member $junior;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->headUser = $this->makeUser('head@acme.test');
        $this->juniorUser = $this->makeUser('junior@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = [
            'clients' => ['view', 'create', 'edit', 'delete'],
            'leads' => ['view', 'create', 'edit', 'delete'],
        ];
        $this->head = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->headUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        $this->junior = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->juniorUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->head->id, 'rights' => $rights,
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function client(User $who, string $name): string
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/clients', ['company_name' => $name])
            ->assertCreated()->json('data.uuid');
    }

    private function lead(User $who, string $name, string $mobile): string
    {
        return $this->actingAs($who)->postJson('/api/v1/crm/leads', [
            'company_name' => $name, 'mobile' => $mobile,
        ])->assertCreated()->json('data.uuid');
    }

    // ---- Deleting a client --------------------------------------------------

    public function test_deleting_a_client_is_the_admins_until_it_is_granted(): void
    {
        $uuid = $this->client($this->headUser, 'Bhavya Steel');

        // The module right alone is not enough — the act is separate.
        $this->actingAs($this->headUser)->deleteJson("/api/v1/crm/clients/{$uuid}")->assertForbidden();

        // Ordinary editing stays theirs, and lands in the trail.
        $this->actingAs($this->headUser)->putJson("/api/v1/crm/clients/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'notes' => 'Prefers a call before the invoice.',
        ])->assertOk();
        $this->assertTrue(\App\Models\Crm\ActivityLog::where('action', 'client.updated')->exists());

        // The Admin grants the act by name, and now it works.
        $this->head->update(['capabilities' => ['clients.delete']]);
        $this->actingAs($this->headUser)->deleteJson("/api/v1/crm/clients/{$uuid}")->assertOk();
        $this->assertSame(0, Client::count());
    }

    public function test_the_admin_may_always_delete(): void
    {
        $uuid = $this->client($this->adminUser, 'Quiet Holdings');
        $this->actingAs($this->adminUser)->deleteJson("/api/v1/crm/clients/{$uuid}")->assertOk();
    }

    // ---- A named grant on leads ---------------------------------------------

    public function test_one_granted_act_does_not_carry_the_others(): void
    {
        $uuid = $this->lead($this->headUser, 'Bhavya Steel', '9000000001');

        $this->head->update(['capabilities' => ['leads.transfer']]);

        // Granted: transfer works.
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/transfer", [
            'to_member_uuid' => $this->junior->uuid,
        ])->assertOk();

        // Not granted: sharing is still refused, and so is the contact edit.
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/share", [
            'member_uuids' => [$this->junior->uuid],
        ])->assertForbidden();
        $this->actingAs($this->headUser)->putJson("/api/v1/crm/leads/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'mobile' => '9111111111',
        ])->assertStatus(422);
    }

    public function test_the_rights_screen_shows_and_saves_the_grants(): void
    {
        $catalogue = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/masters')
            ->assertOk()->json('data.capabilities');
        $this->assertNotEmpty(collect($catalogue)->firstWhere('key', 'clients.delete'));

        $this->actingAs($this->adminUser)->putJson('/api/v1/crm/employees/' . $this->head->uuid, [
            'name' => $this->headUser->name,
            'email' => $this->headUser->email,
            'crm_role' => 'employee',
            'capabilities' => ['clients.delete', 'leads.bulk_transfer'],
        ])->assertOk();

        $this->assertSame(['clients.delete', 'leads.bulk_transfer'], $this->head->fresh()->capabilities);

        // The member's own /me tells the UI which buttons to draw.
        $me = $this->actingAs($this->headUser)->getJson('/api/v1/crm/me')->assertOk()->json('data.member');
        $this->assertContains('clients.delete', $me['capabilities']);
        $this->assertNotContains('leads.share', $me['capabilities']);

        // An Admin's own listing carries the lot.
        $adminMe = $this->actingAs($this->adminUser)->getJson('/api/v1/crm/me')->assertOk()->json('data.member');
        $this->assertContains('payments.settle', $adminMe['capabilities']);
    }

    // ---- Bulk transfer ------------------------------------------------------

    public function test_a_team_head_reshuffles_their_own_desk_in_bulk(): void
    {
        $a = $this->lead($this->juniorUser, 'Lead A', '9000000011');
        $b = $this->lead($this->juniorUser, 'Lead B', '9000000012');

        // The head leads a team, so bulk transfer is theirs by that fact.
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/leads/bulk-transfer', [
            'lead_uuids' => [$a, $b], 'to_member_uuid' => $this->head->uuid,
        ])->assertOk()->assertJsonPath('moved', 2);

        $this->assertSame(2, Lead::where('assigned_member_id', $this->head->id)->count());

        // The junior leads nobody, so the same call is refused.
        $this->actingAs($this->juniorUser)->postJson('/api/v1/crm/leads/bulk-transfer', [
            'lead_uuids' => [$a], 'to_member_uuid' => $this->junior->uuid,
        ])->assertForbidden();

        // Granted by name, it works without a team.
        $this->junior->update(['capabilities' => ['leads.bulk_transfer']]);
        $this->actingAs($this->juniorUser)->postJson('/api/v1/crm/leads/bulk-transfer', [
            'lead_uuids' => [$a], 'to_member_uuid' => $this->junior->uuid,
        ])->assertOk()->assertJsonPath('moved', 1);
    }

    public function test_a_bulk_transfer_reaches_only_the_movers_own_window(): void
    {
        $mine = $this->lead($this->juniorUser, 'Junior Lead', '9000000021');
        $theirs = $this->lead($this->adminUser, 'Admin Lead', '9000000022');

        $this->actingAs($this->headUser)->postJson('/api/v1/crm/leads/bulk-transfer', [
            'lead_uuids' => [$mine, $theirs], 'to_member_uuid' => $this->head->uuid,
        ])->assertOk()->assertJsonPath('moved', 1);

        // The admin's own lead never moved.
        $this->assertNull(Lead::where('uuid', $theirs)->firstOrFail()->assigned_member_id);
    }

    // ---- The client who came back -------------------------------------------

    public function test_a_closed_lead_reopens_carrying_how_it_ended(): void
    {
        Carbon::setTestNow('2026-08-30 10:00:00');
        $uuid = $this->lead($this->headUser, 'Bhavya Steel', '9000000031');

        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/followup", [
            'note' => 'Budget cut this year; revisit after Diwali.', 'lead_status' => 'closed',
        ])->assertCreated();

        $this->assertNotNull(Lead::firstOrFail()->closed_at);

        // A month later they call back.
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/reopen", [
            'note' => 'Called back — budget approved.',
        ])->assertOk();

        $lead = Lead::firstOrFail();
        $this->assertSame('follow_up', $lead->lead_status);
        $this->assertSame(1, $lead->reopen_count);

        // The trail carries the old ending beside the new beginning.
        $reopened = \App\Models\Crm\ActivityLog::where('action', 'lead.reopened')->firstOrFail();
        $this->assertSame('Called back — budget approved.', $reopened->changes['note']);
        $this->assertSame('Budget cut this year; revisit after Diwali.', $reopened->changes['previous_closing']);
        $this->assertSame('2026-08-30 10:00:00', $reopened->changes['closed_on']);

        // Nothing was erased: the closing discussion is still its own entry.
        $this->assertTrue(\App\Models\Crm\ActivityLog::where('action', 'lead.followup')->exists());

        Carbon::setTestNow();
    }

    public function test_a_returning_lead_marks_its_client_a_repeat(): void
    {
        $uuid = $this->lead($this->headUser, 'Bhavya Steel', '9000000041');

        // It became a client once…
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/convert")->assertCreated();
        $client = Client::firstOrFail();
        $this->assertFalse((bool) $client->is_repeat);

        // …and the person came back.
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/reopen", [
            'note' => 'Wants the annual plan again.',
        ])->assertOk();

        $client = $client->fresh();
        $this->assertTrue((bool) $client->is_repeat);
        $this->assertSame(1, $client->repeat_count);

        // And the clients screen wears it.
        $this->actingAs($this->headUser)->getJson('/api/v1/crm/clients')
            ->assertOk()->assertJsonPath('data.0.is_repeat', true);
    }

    public function test_reopening_someone_elses_lead_needs_the_grant(): void
    {
        $uuid = $this->lead($this->juniorUser, 'Junior Lead', '9000000051');
        $this->actingAs($this->juniorUser)->postJson("/api/v1/crm/leads/{$uuid}/followup", [
            'note' => 'Not now.', 'lead_status' => 'closed',
        ])->assertCreated();

        // The head can see it (their team) but it is not their lead.
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/reopen", [
            'note' => 'Trying again.',
        ])->assertForbidden();

        // Its owner may pick it up without any grant.
        $this->actingAs($this->juniorUser)->postJson("/api/v1/crm/leads/{$uuid}/reopen", [
            'note' => 'They rang back.',
        ])->assertOk();

        // An open lead cannot be reopened.
        $this->actingAs($this->juniorUser)->postJson("/api/v1/crm/leads/{$uuid}/reopen", [
            'note' => 'Again?',
        ])->assertStatus(422);
    }

    // ---- Where a lead may be handed ----------------------------------------

    public function test_a_team_head_moves_leads_only_inside_their_own_team(): void
    {
        // A second team, under the admin, is not the head's to reach.
        $strangerUser = $this->makeUser('stranger@acme.test');
        $stranger = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $strangerUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id,
            'rights' => ['leads' => ['view', 'create', 'edit']],
        ]);

        $uuid = $this->lead($this->juniorUser, 'Team Lead', '9000000061');
        $this->head->update(['capabilities' => ['leads.transfer', 'leads.bulk_transfer']]);

        // Down into the team: fine.
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/transfer", [
            'to_member_uuid' => $this->head->uuid,
        ])->assertOk();

        // Upward to the Admin: refused, with the reason.
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/transfer", [
            'to_member_uuid' => $this->admin->uuid,
        ])->assertStatus(422);

        // Across to another team: refused.
        $this->actingAs($this->headUser)->postJson("/api/v1/crm/leads/{$uuid}/transfer", [
            'to_member_uuid' => $stranger->uuid,
        ])->assertStatus(422);

        // The same line holds for a bulk move.
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/leads/bulk-transfer', [
            'lead_uuids' => [$uuid], 'to_member_uuid' => $stranger->uuid,
        ])->assertStatus(422);

        // The Admin may hand a lead anywhere, including to themselves.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/leads/{$uuid}/transfer", [
            'to_member_uuid' => $this->admin->uuid,
        ])->assertOk();

        // And the UI is told where it may point.
        $me = $this->actingAs($this->headUser)->getJson('/api/v1/crm/me')->assertOk()->json('data.member');
        $this->assertContains($this->junior->uuid, $me['team_member_uuids']);
        $this->assertNotContains($stranger->uuid, $me['team_member_uuids']);
        $this->assertNull($this->actingAs($this->adminUser)->getJson('/api/v1/crm/me')
            ->json('data.member.team_member_uuids'));
    }

    // ---- The sidebar's unread numbers --------------------------------------

    public function test_the_sidebar_counts_what_colleagues_did_until_you_look(): void
    {
        // The junior works two leads and a client; the head has not looked.
        $this->lead($this->juniorUser, 'Lead One', '9000000071');
        $this->lead($this->juniorUser, 'Lead Two', '9000000072');
        $this->client($this->juniorUser, 'A Client');

        $sections = $this->actingAs($this->headUser)->getJson('/api/v1/crm/badges')
            ->assertOk()->json('data.sections');

        $this->assertSame(2, $sections['leads']);
        $this->assertSame(1, $sections['clients']);

        // Their own work never nags them.
        $ownSections = $this->actingAs($this->juniorUser)->getJson('/api/v1/crm/badges')
            ->assertOk()->json('data.sections');
        $this->assertArrayNotHasKey('leads', $ownSections);

        // Looking at the section quiets it — for that member, that section.
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/sections/leads/seen')->assertOk();

        $after = $this->actingAs($this->headUser)->getJson('/api/v1/crm/badges')
            ->assertOk()->json('data.sections');
        $this->assertArrayNotHasKey('leads', $after);
        $this->assertSame(1, $after['clients']);

        // The next thing a colleague does brings it back.
        $this->lead($this->juniorUser, 'Lead Three', '9000000073');
        $again = $this->actingAs($this->headUser)->getJson('/api/v1/crm/badges')
            ->assertOk()->json('data.sections');
        $this->assertSame(1, $again['leads']);

        // A section nobody has touched carries no number at all.
        $this->assertArrayNotHasKey('expenses', $again);
    }

    public function test_an_unknown_section_is_not_a_place_to_mark(): void
    {
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/sections/nowhere/seen')->assertNotFound();
    }

    public function test_leads_can_be_shared_in_bulk_without_changing_hands(): void
    {
        $a = $this->lead($this->juniorUser, 'Lead A', '9000000081');
        $b = $this->lead($this->juniorUser, 'Lead B', '9000000082');

        // The head leads a team, so bulk sharing is theirs by that fact.
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/leads/bulk-share', [
            'lead_uuids' => [$a, $b], 'member_uuids' => [$this->head->uuid],
        ])->assertOk()->assertJsonPath('shared', 2);

        // Ownership did not move — the junior still holds both…
        $this->assertSame(2, Lead::where('assigned_member_id', $this->junior->id)->count());
        // …and the head now sees them as shared.
        $rows = $this->actingAs($this->headUser)->getJson('/api/v1/crm/leads')->assertOk()->json('data');
        $this->assertSame($this->headUser->name, $rows[0]['shared_with'][0]['name']);

        // Sharing a lead with the person who already owns it changes nothing.
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/leads/bulk-share', [
            'lead_uuids' => [$a], 'member_uuids' => [$this->junior->uuid],
        ])->assertOk()->assertJsonPath('shared', 0);

        // The team boundary holds: never upward to the Admin.
        $this->actingAs($this->headUser)->postJson('/api/v1/crm/leads/bulk-share', [
            'lead_uuids' => [$a], 'member_uuids' => [$this->admin->uuid],
        ])->assertStatus(422);

        // And a lone employee shares nothing in bulk.
        $this->actingAs($this->juniorUser)->postJson('/api/v1/crm/leads/bulk-share', [
            'lead_uuids' => [$a], 'member_uuids' => [$this->head->uuid],
        ])->assertForbidden();
    }

    public function test_billing_details_need_their_own_permission(): void
    {
        $uuid = $this->client($this->headUser, 'Bhavya Steel');

        // What the client is billed as — the name, address, GST — is a
        // granted act, because it changes what prints on the invoice.
        $this->actingAs($this->headUser)->putJson("/api/v1/crm/clients/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'gst_no' => '24AAACS1234A1Z5', 'city' => 'surat',
        ])->assertStatus(422);
        $this->assertNull(Client::firstOrFail()->gst_no);

        // The working fields save as usual meanwhile, so nobody is blocked
        // from doing their job.
        $this->actingAs($this->headUser)->putJson("/api/v1/crm/clients/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'category' => 'existing', 'status' => 'active',
        ])->assertOk()->assertJsonPath('data.category', 'existing');

        // Granted, the billing details are theirs — house casing and all.
        $this->head->update(['capabilities' => ['clients.edit_details']]);
        $this->actingAs($this->headUser)->putJson("/api/v1/crm/clients/{$uuid}", [
            'company_name' => 'Bhavya Steel', 'gst_no' => '24aaacs1234a1z5', 'city' => 'surat',
        ])->assertOk()->assertJsonPath('data.city', 'Surat');
        $this->assertSame('24AAACS1234A1Z5', Client::firstOrFail()->gst_no);

        // An Admin never needed the grant.
        $adminClient = $this->client($this->adminUser, 'Quiet Holdings');
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/clients/{$adminClient}", [
            'company_name' => 'Quiet Holdings', 'city' => 'delhi',
        ])->assertOk()->assertJsonPath('data.city', 'Delhi');
    }
}
