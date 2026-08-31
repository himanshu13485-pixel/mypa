<?php

namespace Tests\Feature;

use App\Models\Crm\Client;
use App\Models\Crm\Contest;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Targets and Contests. What matters: achievement is computed from real
 * invoices (never typed), an employee's window shows only their own row,
 * contest answers are one-shot inside the window, answers stay sealed
 * until the end, and the leaderboard ranks by points then speed.
 */
class CrmTargetsContestsTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $salesUser;
    protected Organization $org;
    protected Member $adminMember;
    protected Member $salesMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->salesUser = $this->makeUser('sales@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->adminMember = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $this->salesMember = Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->salesUser->id,
            'crm_role' => 'employee',
            'is_salesperson' => true,
            'rights' => ['targets' => ['view']],
        ]);
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    /** An invoice attributed to the salesperson, dated this month. */
    private function invoiceFor(Member $member, float $total, ?string $category = null): Invoice
    {
        $client = Client::firstOrCreate(
            ['organization_id' => $this->org->id, 'company_name' => 'Achievement Client'],
            ['created_by' => $this->adminUser->id],
        );
        $company = IssuingCompany::firstOrCreate(
            ['organization_id' => $this->org->id, 'name' => 'Acme Billing'],
        );

        return Invoice::create([
            'organization_id' => $this->org->id,
            'kind' => 'invoice',
            'number' => 'INV-' . fake()->unique()->numberBetween(1, 99999),
            'issuing_company_id' => $company->id,
            'client_id' => $client->id,
            'member_id' => $member->id,
            'invoice_date' => now()->toDateString(),
            'client_category' => $category,
            'subtotal' => $total,
            'total' => $total,
        ]);
    }

    public function test_achievement_comes_from_the_ledger_not_from_typing(): void
    {
        $this->invoiceFor($this->salesMember, 60000, 'new');
        $this->invoiceFor($this->salesMember, 40000, 'existing');
        // Cancelled money must not count.
        $this->invoiceFor($this->salesMember, 99999)->update(['status' => 'cancelled']);

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [['member_uuid' => $this->salesMember->uuid, 'target_amount' => 200000]],
        ])->assertOk();

        $row = collect($this->actingAs($this->adminUser)
            ->getJson('/api/v1/crm/targets?year=' . now()->year . '&month=' . now()->month)
            ->assertOk()
            ->json('data'))
            ->firstWhere('member_uuid', $this->salesMember->uuid);

        $this->assertEquals(200000, $row['target']);
        $this->assertEquals(100000, $row['achieved']);
        $this->assertEquals(60000, $row['achieved_new']);
        $this->assertEquals(40000, $row['achieved_existing']);
        $this->assertEquals(100000, $row['due']);
        $this->assertEquals(50, $row['percent']);
    }

    public function test_an_employee_sees_only_their_own_target_row(): void
    {
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [
                ['member_uuid' => $this->salesMember->uuid, 'target_amount' => 100000],
                ['member_uuid' => $this->adminMember->uuid, 'target_amount' => 500000],
            ],
        ])->assertOk();

        $rows = $this->actingAs($this->salesUser)->getJson('/api/v1/crm/targets')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->salesMember->uuid, $rows[0]['member_uuid']);

        // And they cannot set targets.
        $this->actingAs($this->salesUser)->postJson('/api/v1/crm/targets', [
            'year' => now()->year, 'month' => now()->month,
            'targets' => [['member_uuid' => $this->salesMember->uuid, 'target_amount' => 1]],
        ])->assertForbidden();
    }

    public function test_copy_previous_month_fills_the_new_month(): void
    {
        $prev = now()->subMonth();
        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets', [
            'year' => $prev->year, 'month' => $prev->month,
            'targets' => [['member_uuid' => $this->salesMember->uuid, 'target_amount' => 150000]],
        ])->assertOk();

        $this->actingAs($this->adminUser)->postJson('/api/v1/crm/targets/copy-previous', [
            'year' => now()->year, 'month' => now()->month,
        ])->assertOk();

        $row = collect($this->actingAs($this->adminUser)->getJson('/api/v1/crm/targets')->json('data'))
            ->firstWhere('member_uuid', $this->salesMember->uuid);
        $this->assertEquals(150000, $row['target']);
    }

    private function makeLiveContest(): string
    {
        return $this->actingAs($this->adminUser)->postJson('/api/v1/crm/contests', [
            'title' => 'CGPL quiz',
            'starts_at' => now()->subMinutes(5)->toDateTimeString(),
            'ends_at' => now()->addMinutes(10)->toDateTimeString(),
            'status' => 'published',
            'questions' => [
                ['type' => 'option', 'question' => 'What does CIF stand for?',
                    'options' => ['Cost, Insurance, Freight', 'Customs, Insurance, Freight'], 'correct_option' => 0, 'points' => 10],
                ['type' => 'text', 'question' => 'Name the portal.', 'correct_text' => 'Netvork', 'points' => 5],
            ],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_answers_are_one_shot_graded_and_sealed_until_the_end(): void
    {
        $uuid = $this->makeLiveContest();

        $show = $this->actingAs($this->salesUser)->getJson("/api/v1/crm/contests/{$uuid}")->assertOk();
        $q = $show->json('data.questions');
        // The player must not be able to see the right answer while live.
        $this->assertNull($q[0]['correct_option']);

        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/contests/{$uuid}/answer", [
            'question_id' => $q[0]['id'], 'answer_option' => 0,
        ])->assertCreated();
        // Same question twice: refused.
        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/contests/{$uuid}/answer", [
            'question_id' => $q[0]['id'], 'answer_option' => 1,
        ])->assertStatus(422);
        // Text answer auto-grades against the model answer, case-insensitive.
        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/contests/{$uuid}/answer", [
            'question_id' => $q[1]['id'], 'answer_text' => 'netvork',
        ])->assertCreated();

        // Results are sealed while live for players…
        $this->actingAs($this->salesUser)->getJson("/api/v1/crm/contests/{$uuid}/results")->assertStatus(422);

        // …then the window ends and the board opens: 10 + 5 points, rank 1.
        Contest::where('uuid', $uuid)->first()->update(['ends_at' => now()->subMinute()]);
        $board = $this->actingAs($this->salesUser)->getJson("/api/v1/crm/contests/{$uuid}/results")
            ->assertOk()->json('data.board');
        $this->assertSame(15, $board[0]['points']);
        $this->assertSame(1, $board[0]['rank']);

        // And answering after the end is refused.
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/contests/{$uuid}/answer", [
            'question_id' => $q[0]['id'], 'answer_option' => 0,
        ])->assertStatus(422);
    }

    public function test_drafts_are_invisible_to_players_and_questions_lock_after_answers(): void
    {
        $uuid = $this->actingAs($this->adminUser)->postJson('/api/v1/crm/contests', [
            'title' => 'Hidden draft',
            'starts_at' => now()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
            'questions' => [['type' => 'option', 'question' => 'Q', 'options' => ['A', 'B'], 'correct_option' => 0]],
        ])->json('data.uuid');

        // Draft: hidden from the list and the direct URL for players.
        $list = $this->actingAs($this->salesUser)->getJson('/api/v1/crm/contests')->assertOk();
        $this->assertSame(0, $list->json('total'));
        $this->actingAs($this->salesUser)->getJson("/api/v1/crm/contests/{$uuid}")->assertNotFound();

        // Publish, play, then try to rewrite history.
        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/contests/{$uuid}", [
            'title' => 'Hidden draft',
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
            'status' => 'published',
        ])->assertOk();

        $qid = $this->actingAs($this->salesUser)->getJson("/api/v1/crm/contests/{$uuid}")->json('data.questions.0.id');
        $this->actingAs($this->salesUser)->postJson("/api/v1/crm/contests/{$uuid}/answer", [
            'question_id' => $qid, 'answer_option' => 1,
        ])->assertCreated();

        $this->actingAs($this->adminUser)->putJson("/api/v1/crm/contests/{$uuid}", [
            'title' => 'Hidden draft',
            'starts_at' => now()->subMinute()->toDateTimeString(),
            'ends_at' => now()->addHour()->toDateTimeString(),
            'questions' => [['type' => 'option', 'question' => 'Changed', 'options' => ['A', 'B'], 'correct_option' => 1]],
        ])->assertStatus(422);
    }
}
