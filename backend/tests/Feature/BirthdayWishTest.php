<?php

namespace Tests\Feature;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\BirthdayWish;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Wishing somebody a happy birthday from inside the CRM, and them saying
 * thank you - with every wish and every thank-you kept.
 */
class BirthdayWishTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $priyanshuUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $priyanshu;
    protected Member $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow('2026-09-13 10:00:00');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);

        [$this->adminUser] = $this->member('Company Admin', 'admin', null);
        [$this->priyanshuUser, $this->priyanshu] = $this->member('Priyanshu', 'employee', '1998-09-13');
        [$this->bobUser, $this->bob] = $this->member('Bob', 'employee', '1995-02-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(string $name, string $role, ?string $dob): array
    {
        $user = User::factory()->create(['name' => $name]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);

        $member = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $user->id, 'crm_role' => $role, 'status' => 'active',
        ]);
        $member->forceFill(['dob' => $dob])->save();

        return [$user, $member];
    }

    public function test_everybody_else_is_told_whose_birthday_it_is(): void
    {
        $today = $this->actingAs($this->bobUser)->getJson('/api/v1/crm/birthdays/today')->assertOk();

        $today->assertJsonPath('data.celebrating.0.name', 'Priyanshu');
        $today->assertJsonPath('data.celebrating.0.my_wish', null);
        $today->assertJsonPath('data.is_my_birthday', false);
        $this->assertStringContainsString('{name}', $today->json('data.default_wish'));

        // The birthday person is not asked to wish themselves.
        $own = $this->actingAs($this->priyanshuUser)->getJson('/api/v1/crm/birthdays/today')->assertOk();
        $own->assertJsonCount(0, 'data.celebrating');
        $own->assertJsonPath('data.is_my_birthday', true);
    }

    public function test_a_wish_reaches_the_birthday_person_with_the_senders_name(): void
    {
        Notification::fake();

        // No message typed: the default, with their name in it.
        $sent = $this->actingAs($this->bobUser)
            ->postJson("/api/v1/crm/birthdays/{$this->priyanshu->uuid}/wish")
            ->assertCreated();
        $this->assertStringContainsString('Priyanshu', $sent->json('data.message'));
        $this->assertStringContainsString('🎂', $sent->json('data.message'));

        Notification::assertSentTo($this->priyanshuUser, CrmNotification::class,
            fn ($n) => str_contains($n->message, 'Bob wished you'));

        // It is on their screen, from Bob.
        $this->actingAs($this->priyanshuUser)->getJson('/api/v1/crm/birthdays/today')
            ->assertOk()
            ->assertJsonPath('data.received.0.from.name', 'Bob');

        // Bob's popup now knows he has wished.
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/birthdays/today')
            ->assertOk()->assertJsonPath('data.celebrating.0.my_wish.from.name', 'Bob');

        // Wishing again replaces the wish rather than stacking a second one.
        $this->actingAs($this->bobUser)
            ->postJson("/api/v1/crm/birthdays/{$this->priyanshu->uuid}/wish", ['message' => 'Party tonight? 🎉'])
            ->assertOk()->assertJsonPath('data.message', 'Party tonight? 🎉');
        $this->assertSame(1, BirthdayWish::count());

        $this->assertSame(2, ActivityLog::where('action', 'birthday.wished')->count());
    }

    public function test_only_on_the_day_and_never_yourself(): void
    {
        $this->actingAs($this->priyanshuUser)
            ->postJson("/api/v1/crm/birthdays/{$this->bob->uuid}/wish")
            ->assertStatus(422);

        $this->actingAs($this->priyanshuUser)
            ->postJson("/api/v1/crm/birthdays/{$this->priyanshu->uuid}/wish")
            ->assertStatus(422);

        $this->assertSame(0, BirthdayWish::count());
    }

    public function test_the_birthday_person_says_thank_you_and_only_they_can(): void
    {
        Notification::fake();

        $uuid = $this->actingAs($this->bobUser)
            ->postJson("/api/v1/crm/birthdays/{$this->priyanshu->uuid}/wish")
            ->assertCreated()->json('data.uuid');

        // Somebody else cannot answer a wish that was not theirs.
        $this->actingAs($this->bobUser)->postJson("/api/v1/crm/birthday-wishes/{$uuid}/reply")->assertNotFound();

        $thanked = $this->actingAs($this->priyanshuUser)
            ->postJson("/api/v1/crm/birthday-wishes/{$uuid}/reply")
            ->assertOk();
        $this->assertStringContainsString('Bob', $thanked->json('data.reply'));
        $this->assertNotNull($thanked->json('data.replied_at'));

        Notification::assertSentTo($this->bobUser, CrmNotification::class,
            fn ($n) => str_contains($n->message, 'Priyanshu thanked you'));

        $this->assertSame(1, ActivityLog::where('action', 'birthday.replied')->count());
    }

    public function test_the_history_keeps_every_wish_and_thank_you(): void
    {
        $uuid = $this->actingAs($this->bobUser)
            ->postJson("/api/v1/crm/birthdays/{$this->priyanshu->uuid}/wish", ['message' => 'HBD!'])
            ->json('data.uuid');
        $this->actingAs($this->priyanshuUser)
            ->postJson("/api/v1/crm/birthday-wishes/{$uuid}/reply", ['message' => 'Thanks Bob'])
            ->assertOk();

        // The Admin sees the company's.
        $this->actingAs($this->adminUser)->getJson('/api/v1/crm/birthday-wishes')
            ->assertOk()
            ->assertJsonPath('data.0.from.name', 'Bob')
            ->assertJsonPath('data.0.to.name', 'Priyanshu')
            ->assertJsonPath('data.0.reply', 'Thanks Bob')
            ->assertJsonPath('years.0', 2026);

        // Bob sees it as sent, Priyanshu as received.
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/birthday-wishes?direction=sent')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->bobUser)->getJson('/api/v1/crm/birthday-wishes?direction=received')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->priyanshuUser)->getJson('/api/v1/crm/birthday-wishes?direction=received')
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
