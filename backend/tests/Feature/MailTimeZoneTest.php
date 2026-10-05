<?php

namespace Tests\Feature;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Services\Mail\MailSync;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A mail says when it arrived, in the clock on the office wall.
 *
 * Mail carries the sender's offset. The column does not keep one - like
 * every other datetime here it holds plain local time - so a date arriving
 * from somewhere else has to be converted on the way in. It was not, so it
 * was written as the sender's wall clock and read back as ours: a mail sent
 * from London at nine in the morning claimed to have arrived here at nine,
 * rather than at half past two in the afternoon.
 *
 * What made it hard to see is that mail from Indian senders was always
 * right. Only correspondents abroad were wrong, and each one by exactly
 * their own distance from here.
 */
class MailTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $org = Organization::create([
            'name' => 'Acme', 'code' => 'ACME', 'status' => 'active', 'mails_enabled' => true,
        ]);
        $user = User::factory()->create(['email' => 'boss@acme.test']);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'Asia/Kolkata']);
        $member = Member::create([
            'organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => 'admin', 'status' => 'active',
        ]);

        $this->account = MailAccount::create([
            'organization_id' => $org->id, 'member_id' => $member->id,
            'email' => 'accounts@acme.test', 'provider' => 'custom',
            'imap_host' => 'imap.acme.test', 'imap_password' => 'secret',
        ]);
    }

    private function arrive(string $sentAt): MailMessage
    {
        return MailMessage::create([
            'organization_id' => $this->account->organization_id,
            'mail_account_id' => $this->account->id,
            'folder' => 'inbox',
            'subject' => 'Rates for March',
            'from_email' => 'priya@client.test',
            'thread_key' => MailMessage::threadKeyFor(null, null, null, 'Rates for March'),
            'date' => MailSync::localise(new DateTimeImmutable($sentAt)),
        ]);
    }

    public function test_nine_in_london_is_half_past_two_here(): void
    {
        $mail = $this->arrive('2026-10-05T09:00:00+00:00');

        $this->assertSame('2026-10-05 14:30', $mail->fresh()->date->format('Y-m-d H:i'));
    }

    public function test_a_sender_in_our_own_timezone_is_left_exactly_alone(): void
    {
        // Always looked right, which is why this took so long to notice.
        $mail = $this->arrive('2026-10-05T14:30:00+05:30');

        $this->assertSame('2026-10-05 14:30', $mail->fresh()->date->format('Y-m-d H:i'));
    }

    public function test_a_mail_sent_late_in_new_york_arrives_here_the_next_morning(): void
    {
        // The date moves, not just the time - and a list sorted by date put
        // these in the wrong place as well as showing the wrong hour.
        $mail = $this->arrive('2026-10-05T22:00:00-04:00');

        $this->assertSame('2026-10-06 07:30', $mail->fresh()->date->format('Y-m-d H:i'));
    }

    public function test_mail_with_no_date_at_all_is_stamped_now(): void
    {
        $mail = $this->arrive('now');

        $this->assertTrue($mail->fresh()->date->diffInMinutes(now()) < 1);
    }

    public function test_what_the_screen_is_handed_carries_the_offset(): void
    {
        $mail = $this->arrive('2026-10-05T09:00:00+00:00');

        // The browser renders whatever offset it is given, so the string
        // handed over has to carry one - half past two, plus 05:30.
        $this->assertStringContainsString('14:30:00+05:30', $mail->fresh()->summary()['date']);
    }
}
