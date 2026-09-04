<?php

namespace Tests\Feature;

use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\PhoneCall;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A record of the calls that leave the app.
 *
 * The line this feature walks is what can honestly be known. Dialling is
 * ours: the app opened the dialler, so the attempt and its moment are facts.
 * What happened next is not — Android does not let an app watch a cellular
 * call — so the outcome comes back from the person who made it, and every
 * duration here says on its face that somebody typed it.
 */
class PhoneCallLogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $seller;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->seller = $this->staff(['leads' => ['view', 'create', 'edit']]);

        $this->lead = Lead::create([
            'organization_id' => $this->org->id,
            'lead_no' => 1,
            'company_name' => 'Bhavya Steel',
            'contact_person' => 'Jaimin',
            'mobile' => '9876543210',
            'created_by' => $this->seller->id,
            'assigned_member_id' => Member::where('user_id', $this->seller->id)->value('id'),
        ]);
    }

    private function staff(array $rights): User
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'crm_role' => 'employee',
            'rights' => $rights,
        ]);

        return $user;
    }

    private function place(User $who, array $extra = [])
    {
        return $this->actingAs($who)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->postJson('/api/v1/phone-calls', $extra + [
                'number' => '9876543210',
                'label' => 'Bhavya Steel',
                'subject_type' => 'lead',
                'subject_uuid' => $this->lead->uuid,
            ]);
    }

    public function test_a_call_is_recorded_the_moment_it_is_placed(): void
    {
        /*
         * Recorded on dialling, not afterwards. Waiting for the caller to
         * come back to the app would lose every call where somebody rang,
         * talked and put the phone down — which is most of them.
         */
        $this->place($this->seller)->assertCreated()->assertJsonPath('data.outcome', null);

        $call = PhoneCall::firstOrFail();

        $this->assertNotNull($call->placed_at);
        $this->assertSame($this->lead->id, $call->subject_id);
        $this->assertSame($this->org->id, $call->organization_id);
    }

    public function test_the_caller_says_afterwards_how_it_went(): void
    {
        $uuid = $this->place($this->seller)->json('data.uuid');

        $this->actingAs($this->seller)->patchJson("/api/v1/phone-calls/{$uuid}", [
            'outcome' => 'connected',
            'duration_seconds' => 245,
            'notes' => 'Wants a quote by Friday.',
        ])->assertOk()
            ->assertJsonPath('data.duration_seconds', 245)
            // Said on the row itself: this number was typed, not metered.
            ->assertJsonPath('data.duration_is_reported', true);
    }

    public function test_a_call_nobody_answered_keeps_no_duration(): void
    {
        // Otherwise whatever was left in the box becomes "no answer, 4 min"
        // on the lead's history.
        $uuid = $this->place($this->seller)->json('data.uuid');

        $this->actingAs($this->seller)->patchJson("/api/v1/phone-calls/{$uuid}", [
            'outcome' => 'no_answer',
            'duration_seconds' => 240,
        ])->assertOk()->assertJsonPath('data.duration_seconds', null);
    }

    public function test_only_the_person_who_made_it_may_say_how_it_went(): void
    {
        $uuid = $this->place($this->seller)->json('data.uuid');
        $colleague = $this->staff(['leads' => ['view']]);

        $this->actingAs($colleague)->patchJson("/api/v1/phone-calls/{$uuid}", [
            'outcome' => 'connected',
        ])->assertStatus(403);
    }

    public function test_a_lead_shows_everybody_who_rang_it(): void
    {
        /*
         * The whole company's calls, not the reader's own — seeing that three
         * people have already rung this lead this week is the point, and a
         * log filtered to yourself hides exactly that.
         */
        $colleague = $this->staff(['leads' => ['view']]);
        Member::where('user_id', $colleague->id)->update(['crm_role' => 'subadmin']);

        $this->place($this->seller)->assertCreated();
        $this->place($colleague)->assertCreated();

        $body = $this->actingAs($this->seller)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson("/api/v1/crm/leads/{$this->lead->uuid}/calls")
            ->assertOk()->json();

        $this->assertSame(2, $body['summary']['total']);
        $this->assertCount(2, $body['summary']['callers']);
    }

    public function test_the_talk_time_counts_only_calls_somebody_reported(): void
    {
        $first = $this->place($this->seller)->json('data.uuid');
        $second = $this->place($this->seller)->json('data.uuid');

        $this->actingAs($this->seller)->patchJson("/api/v1/phone-calls/{$first}", [
            'outcome' => 'connected', 'duration_seconds' => 300,
        ])->assertOk();
        $this->actingAs($this->seller)->patchJson("/api/v1/phone-calls/{$second}", [
            'outcome' => 'no_answer',
        ])->assertOk();

        $body = $this->actingAs($this->seller)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->getJson("/api/v1/crm/leads/{$this->lead->uuid}/calls")
            ->assertOk()->json();

        // An average that counted the unanswered one as zero would say the
        // team talks for half as long as it does.
        $this->assertSame(300, $body['summary']['talk_seconds']);
        $this->assertSame(1, $body['summary']['connected']);
    }

    public function test_a_lead_in_another_company_cannot_be_written_to(): void
    {
        /*
         * Without the subject check, logging a call is a way to write a line
         * into any lead anywhere by guessing at a uuid.
         */
        $rival = Organization::create(['name' => 'Rival', 'code' => 'RIVAL']);
        $theirs = Lead::create([
            'organization_id' => $rival->id,
            'lead_no' => 1,
            'company_name' => 'Their Lead',
            'mobile' => '9000000000',
        ]);

        $this->actingAs($this->seller)
            ->withHeader('X-Crm-Org', $this->org->uuid)
            ->postJson('/api/v1/phone-calls', [
                'number' => '9000000000',
                'subject_type' => 'lead',
                'subject_uuid' => $theirs->uuid,
            ])->assertStatus(404);

        $this->assertSame(0, PhoneCall::count());
    }

    public function test_the_history_carries_both_kinds_and_says_which(): void
    {
        $this->place($this->seller)->assertCreated();

        $row = $this->actingAs($this->seller)->getJson('/api/v1/calls/history')
            ->assertOk()->json('data.0');

        $this->assertSame('phone', $row['channel']);
        $this->assertTrue($row['duration_is_reported']);
    }

    public function test_the_history_can_be_filtered_to_one_kind(): void
    {
        $this->place($this->seller)->assertCreated();

        $this->actingAs($this->seller)->getJson('/api/v1/calls/history?channel=netvork')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->seller)->getJson('/api/v1/calls/history?channel=phone')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_it_asks_about_calls_still_unaccounted_for(): void
    {
        $this->place($this->seller)->assertCreated();

        $this->actingAs($this->seller)->getJson('/api/v1/phone-calls/pending')
            ->assertOk()->assertJsonCount(1, 'data');

        $uuid = PhoneCall::firstOrFail()->uuid;
        $this->actingAs($this->seller)->patchJson("/api/v1/phone-calls/{$uuid}", [
            'outcome' => 'connected',
        ])->assertOk();

        // Answered, so it stops asking.
        $this->actingAs($this->seller)->getJson('/api/v1/phone-calls/pending')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
