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
 * Filtering by who handed a lead out, not by who holds it.
 *
 * Every row on the screen says both - "Satish Singh, by Manisha" - but only
 * the first could be filtered on. A manager asking what they allocated last
 * week, or checking that one person's allocations are being worked, had no
 * way to ask the question the screen was already answering.
 *
 * The two are easy to confuse in code, because a lead has an assignee and an
 * assigner and both are people in the same list. So the test that matters is
 * the one where they are different people.
 */
class CrmLeadsAssignedByFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private Organization $org;
    private Member $manisha;
    private Member $satish;
    private Member $rahul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        $this->adminUser = User::factory()->create(['name' => 'Boss']);
        $this->adminUser->settings()->create([]);
        $this->adminUser->profile()->create(['timezone' => 'UTC']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);

        $this->manisha = $this->member('Manisha');
        $this->satish = $this->member('Satish Singh');
        $this->rahul = $this->member('Rahul');
    }

    private function member(string $name): Member
    {
        $user = User::factory()->create(['name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => 'employee',
        ]);
    }

    private int $nextNo = 1;

    private function lead(string $company, Member $holder, ?Member $givenBy): Lead
    {
        return Lead::create([
            'organization_id' => $this->org->id,
            'lead_no' => $this->nextNo++,
            'company_name' => $company,
            'contact_person' => 'Someone',
            'mobile' => '+919876543210',
            'source' => 'WhatsApp',
            'lead_status' => 'new',
            'assigned_member_id' => $holder->id,
            'assigned_by' => $givenBy?->user_id,
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
            ->pluck('company_name')
            ->sort()
            ->values()
            ->all();
    }

    public function test_it_finds_what_one_person_handed_out(): void
    {
        $this->lead('Meridian Motors', holder: $this->satish, givenBy: $this->manisha);
        $this->lead('Kalyani Engineering', holder: $this->rahul, givenBy: $this->manisha);
        $this->lead('Gulf Trading', holder: $this->satish, givenBy: $this->rahul);

        $this->assertSame(
            ['Kalyani Engineering', 'Meridian Motors'],
            $this->listed('assigned_by=' . $this->manisha->uuid),
        );
    }

    public function test_it_is_not_the_same_question_as_who_holds_it(): void
    {
        /*
         * The one case that catches the two being confused. Satish holds a
         * lead Manisha gave him and gave a different one away himself; the
         * two filters must return different rows.
         */
        $this->lead('Meridian Motors', holder: $this->satish, givenBy: $this->manisha);
        $this->lead('Gulf Trading', holder: $this->rahul, givenBy: $this->satish);

        $this->assertSame(['Meridian Motors'], $this->listed('assigned_to=' . $this->satish->uuid));
        $this->assertSame(['Gulf Trading'], $this->listed('assigned_by=' . $this->satish->uuid));
    }

    public function test_several_people_at_once(): void
    {
        // The dropdown is a multi-select, as the one beside it is.
        $this->lead('Meridian Motors', holder: $this->satish, givenBy: $this->manisha);
        $this->lead('Gulf Trading', holder: $this->satish, givenBy: $this->rahul);
        $this->lead('Nobody Gave This', holder: $this->satish, givenBy: null);

        $this->assertSame(
            ['Gulf Trading', 'Meridian Motors'],
            $this->listed("assigned_by[]={$this->manisha->uuid}&assigned_by[]={$this->rahul->uuid}"),
        );
    }

    public function test_both_filters_narrow_together(): void
    {
        $this->lead('Meridian Motors', holder: $this->satish, givenBy: $this->manisha);
        $this->lead('Kalyani Engineering', holder: $this->rahul, givenBy: $this->manisha);

        $this->assertSame(
            ['Meridian Motors'],
            $this->listed("assigned_to={$this->satish->uuid}&assigned_by={$this->manisha->uuid}"),
        );
    }

    public function test_asking_for_nobody_in_particular_changes_nothing(): void
    {
        $this->lead('Meridian Motors', holder: $this->satish, givenBy: $this->manisha);
        $this->lead('Gulf Trading', holder: $this->satish, givenBy: null);

        $this->assertSame(['Gulf Trading', 'Meridian Motors'], $this->listed(''));
    }

    public function test_a_member_of_another_company_matches_nothing(): void
    {
        // The uuids are resolved to users through this organisation's own
        // members, so one from somewhere else cannot reach in.
        $other = Organization::create(['name' => 'Other Ltd', 'code' => 'OTHR']);
        $stranger = User::factory()->create(['name' => 'Elsewhere']);
        $stranger->settings()->create([]);
        $outsider = Member::create([
            'organization_id' => $other->id, 'user_id' => $stranger->id, 'crm_role' => 'admin',
        ]);

        $this->lead('Meridian Motors', holder: $this->satish, givenBy: $this->manisha);

        $this->assertSame([], $this->listed('assigned_by=' . $outsider->uuid));
    }
}
