<?php

namespace Tests\Feature;

use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Standing rules, and the mailbox each one belongs to.
 *
 * A rule says what to look for in arriving mail and which label a match
 * wears. The OTP that comes eleven times a day files itself; everything a
 * rule does not ask about is left alone.
 */
class MailFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $bossUser;
    private Member $boss;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create([
            'name' => 'Grapout', 'code' => 'GRAP', 'status' => 'active',
            'mails_enabled' => true, 'mails_mailbox_cap' => 3,
        ]);
        $this->bossUser = User::factory()->create(['email' => 'boss@grapout.test']);
        $this->bossUser->settings()->create([]);
        $this->bossUser->profile()->create(['timezone' => 'Asia/Kolkata']);
        $this->boss = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bossUser->id,
            'crm_role' => 'admin', 'status' => 'active',
        ]);
    }

    private function mailbox(string $email, bool $default = false): MailAccount
    {
        return MailAccount::create([
            'organization_id' => $this->org->id, 'member_id' => $this->boss->id, 'email' => $email,
            'provider' => 'custom', 'is_default' => $default,
            'smtp_host' => 'smtp.test', 'smtp_password' => 'x',
            'imap_host' => 'imap.test', 'imap_password' => 'x',
        ]);
    }

    /**
     * A mail landing in the inbox, filters and all.
     *
     * The sync applies the mailbox's rules to each message as it stores it,
     * so a message arriving here does the same thing rather than pretending
     * rules only exist when somebody presses a button.
     */
    private function arrived(MailAccount $box, string $subject, array $extra = []): MailMessage
    {
        $mail = MailMessage::create($extra + [
            'organization_id' => $this->org->id, 'mail_account_id' => $box->id,
            'folder' => 'inbox', 'from_email' => 'bank@hdfc.test', 'from_name' => 'HDFC Bank',
            'thread_key' => MailMessage::threadKeyFor(null, null, null, $subject),
            'to' => [['email' => $box->email]], 'subject' => $subject, 'body_text' => $subject,
            'date' => now(), 'size' => 4096,
        ]);
        app(\App\Services\Mail\MailFilters::class)->apply($mail);

        return $mail;
    }

    public function test_a_rule_files_matching_mail_under_its_label(): void
    {
        $box = $this->mailbox('admin@grapout.test', true);

        $label = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'OTP', 'color' => '#f59e0b',
        ])->assertCreated()->json('data.uuid');

        // Two already sitting in the Inbox, and one that has nothing to do
        // with it - the rule is written after the fact, as they always are.
        $otp = $this->arrived($box, 'Your OTP is 449120');
        $also = $this->arrived($box, 'Login code', ['body_text' => 'Use OTP 55231 to sign in']);
        $other = $this->arrived($box, 'Statement for September');

        $made = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/filters', [
            'account' => $box->uuid, 'label' => $label, 'body_has' => 'otp',
            'mark_read' => true, 'apply_now' => true,
        ])->assertCreated();

        $made->assertJsonPath('data.label_name', 'OTP');
        $this->assertStringContainsString('2 messages', $made->json('message'));

        $this->assertSame(['OTP'], $otp->fresh()->labels->pluck('name')->all());
        $this->assertSame(['OTP'], $also->fresh()->labels->pluck('name')->all());
        $this->assertSame([], $other->fresh()->labels->pluck('name')->all());
        $this->assertTrue($otp->fresh()->is_read, 'the rule was told to mark it read');
    }

    public function test_every_condition_has_to_pass_and_a_rule_with_none_is_refused(): void
    {
        $box = $this->mailbox('admin@grapout.test', true);
        $label = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'Bank',
        ])->assertCreated()->json('data.uuid');

        // From the bank AND about a statement: an OTP from the same sender
        // fails the second test and is left alone.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/filters', [
            'account' => $box->uuid, 'label' => $label,
            'from_has' => 'hdfc', 'subject_has' => 'statement',
        ])->assertCreated();

        $statement = $this->arrived($box, 'Statement for September');
        $otp = $this->arrived($box, 'Your OTP is 449120');

        $this->assertSame(['Bank'], $statement->fresh()->labels->pluck('name')->all());
        $this->assertSame([], $otp->fresh()->labels->pluck('name')->all());

        // A rule that asks nothing would catch everything, so it is refused.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/filters', [
            'account' => $box->uuid, 'label' => $label, 'mark_read' => true,
        ])->assertStatus(422);
    }

    public function test_the_words_it_must_not_have_keep_a_mail_out(): void
    {
        $box = $this->mailbox('admin@grapout.test', true);
        $label = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'Invoices',
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/filters', [
            'account' => $box->uuid, 'label' => $label,
            'body_has' => 'invoice', 'body_lacks' => 'reminder',
        ])->assertCreated();

        $real = $this->arrived($box, 'Invoice GRP-1041 attached');
        $chase = $this->arrived($box, 'Invoice GRP-1041 - payment reminder');

        $this->assertSame(['Invoices'], $real->fresh()->labels->pluck('name')->all());
        $this->assertSame([], $chase->fresh()->labels->pluck('name')->all());
    }

    public function test_a_rule_and_its_label_stay_inside_their_own_mailbox(): void
    {
        $grapout = $this->mailbox('admin@grapout.test', true);
        $zma = $this->mailbox('admin@zma.test');

        $theirs = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $zma->uuid, 'name' => 'OTP',
        ])->assertCreated()->json('data.uuid');

        // The same name again in the other mailbox is fine - that is the
        // point of a mailbox keeping its own.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $grapout->uuid, 'name' => 'OTP',
        ])->assertCreated();

        // But a GrapOut rule may not file into a ZMA label.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/filters', [
            'account' => $grapout->uuid, 'label' => $theirs, 'body_has' => 'otp',
        ])->assertStatus(422);

        // Reading one mailbox shows one mailbox's labels; All shows both.
        $this->assertSame(['OTP'], collect($this->actingAs($this->bossUser)
            ->getJson('/api/v1/crm/mails/labels?account=' . $zma->uuid)->assertOk()->json('data'))
            ->pluck('name')->all());
        $this->assertCount(2, $this->actingAs($this->bossUser)
            ->getJson('/api/v1/crm/mails/labels?account=all')->assertOk()->json('data'));
    }

    public function test_a_mailbox_holds_as_many_labels_as_the_rail_can_list(): void
    {
        $box = $this->mailbox('admin@grapout.test', true);

        foreach (range(1, MailAccount::LABEL_CAP) as $n) {
            $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
                'account' => $box->uuid, 'name' => "Label {$n}",
            ])->assertCreated();
        }

        $refused = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'One too many',
        ])->assertStatus(422);
        $this->assertStringContainsString((string) MailAccount::LABEL_CAP, $refused->json('message'));

        // The cap is per mailbox, so the next mailbox starts with a clean slate.
        $second = $this->mailbox('admin@zma.test');
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $second->uuid, 'name' => 'Label 1',
        ])->assertCreated();
    }

    public function test_a_label_files_the_mail_and_taking_it_off_hands_it_back(): void
    {
        $box = $this->mailbox('admin@grapout.test', true);
        $invoices = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'Invoices',
        ])->assertCreated()->json('data.uuid');
        $urgent = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'Urgent',
        ])->assertCreated()->json('data.uuid');

        $mail = $this->arrived($box, 'Invoice GRP-1041');
        $this->assertSame('inbox', $mail->fresh()->folder);

        // Labelling it files it: out of the Inbox, read under the label.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/messages/bulk', [
            'uuids' => [$mail->uuid], 'action' => 'label', 'label' => $invoices,
        ])->assertOk();
        $this->assertSame('archive', $mail->fresh()->folder);

        // A second label changes nothing about where it lives.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/messages/bulk', [
            'uuids' => [$mail->uuid], 'action' => 'label', 'label' => $urgent,
        ])->assertOk();
        $this->assertSame('archive', $mail->fresh()->folder);

        // Nor does taking one off while the other still files it.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/messages/bulk', [
            'uuids' => [$mail->uuid], 'action' => 'unlabel', 'label' => $urgent,
        ])->assertOk();
        $this->assertSame('archive', $mail->fresh()->folder);

        // The last one off, and it is back where it arrived.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/messages/bulk', [
            'uuids' => [$mail->uuid], 'action' => 'unlabel', 'label' => $invoices,
        ])->assertOk();
        $this->assertSame('inbox', $mail->fresh()->folder);
    }

    public function test_deleting_a_label_returns_its_mail_to_the_inbox(): void
    {
        $box = $this->mailbox('admin@grapout.test', true);
        $otp = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'OTP',
        ])->assertCreated()->json('data.uuid');
        $kept = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'Keep',
        ])->assertCreated()->json('data.uuid');

        $alone = $this->arrived($box, 'Your OTP is 449120');
        $both = $this->arrived($box, 'Your OTP is 553120');
        foreach ([[$alone, $otp], [$both, $otp], [$both, $kept]] as [$mail, $label]) {
            $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/messages/bulk', [
                'uuids' => [$mail->uuid], 'action' => 'label', 'label' => $label,
            ])->assertOk();
        }
        $this->assertSame('archive', $alone->fresh()->folder);

        $gone = $this->actingAs($this->bossUser)
            ->deleteJson('/api/v1/crm/mails/labels/' . $otp)->assertOk();

        // Filed under nothing else, so it comes back rather than sitting in
        // Archive where only a search would find it.
        $this->assertSame('inbox', $alone->fresh()->folder);
        $this->assertStringContainsString('1 message is back', $gone->json('message'));

        // Still filed under Keep, so it stays filed.
        $this->assertSame('archive', $both->fresh()->folder);
    }

    public function test_a_rule_can_be_changed_after_it_is_written(): void
    {
        $box = $this->mailbox('admin@grapout.test', true);
        $label = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/labels', [
            'account' => $box->uuid, 'name' => 'Bank',
        ])->assertCreated()->json('data.uuid');

        $rule = $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/filters', [
            'account' => $box->uuid, 'label' => $label, 'subject_has' => 'statement',
        ])->assertCreated()->json('data.uuid');

        // Widened to catch the OTPs too, and swept over what is already here.
        $this->arrived($box, 'Your OTP is 449120');
        $changed = $this->actingAs($this->bossUser)->putJson('/api/v1/crm/mails/filters/' . $rule, [
            'label' => $label, 'subject_has' => null, 'body_has' => 'otp', 'apply_now' => true,
        ])->assertOk();

        $this->assertSame('has the words "otp"', $changed->json('data.in_words'));
        $this->assertStringContainsString('1 matched', $changed->json('message'));
    }

    public function test_two_mailboxes_may_not_read_the_same_mailbox_on_the_server(): void
    {
        $grapout = $this->mailbox('admin@grapout.test', true);
        $grapout->update(['imap_host' => 'mail.grapout.test', 'imap_username' => 'admin@grapout.test']);

        $zma = $this->mailbox('admin@zma.test');

        /*
         * The sign-in decides which mail is read, not the address on the
         * card. Pointing this one at GrapOut's host under GrapOut's username
         * would fetch GrapOut's mail into ZMA's folders - which is exactly
         * how one company's Inbox ends up full of another's.
         */
        $refused = $this->actingAs($this->bossUser)->putJson('/api/v1/crm/mails/accounts/' . $zma->uuid, [
            'email' => 'admin@zma.test',
            'imap_host' => 'mail.grapout.test',
            'imap_username' => 'admin@grapout.test',
        ])->assertStatus(422);
        $this->assertStringContainsString('admin@grapout.test', $refused->json('message'));

        // Its own username on the same host is a different mailbox, and fine.
        $this->actingAs($this->bossUser)->putJson('/api/v1/crm/mails/accounts/' . $zma->uuid, [
            'email' => 'admin@zma.test',
            'imap_host' => 'mail.grapout.test',
            'imap_username' => 'admin@zma.test',
        ])->assertOk();

        // And a copy that would sign in as the original is refused too.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/accounts/' . $grapout->uuid . '/replicate', [
            'email' => 'sales@grapout.test', 'same_credentials' => true,
        ])->assertStatus(422);
    }

    public function test_an_address_book_belongs_to_the_mailbox_that_wrote_to_it(): void
    {
        $grapout = $this->mailbox('admin@grapout.test', true);
        $zma = $this->mailbox('admin@zma.test');

        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/contacts', [
            'account' => $grapout->uuid, 'email' => 'buyer@client.test', 'name' => 'Buyer Ltd',
        ])->assertCreated();
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/contacts', [
            'account' => $zma->uuid, 'email' => 'other@client.test', 'name' => 'Other Ltd',
        ])->assertCreated();

        $mine = fn (string $account) => collect($this->actingAs($this->bossUser)
            ->getJson('/api/v1/crm/mails/contacts?account=' . $account)->assertOk()->json('data'))
            ->pluck('email')->all();

        $this->assertSame(['buyer@client.test'], $mine($grapout->uuid));
        $this->assertSame(['other@client.test'], $mine($zma->uuid));
        $this->assertCount(2, $mine('all'));

        // Adding one needs a mailbox to add it to.
        $this->actingAs($this->bossUser)->postJson('/api/v1/crm/mails/contacts', [
            'email' => 'nowhere@client.test',
        ])->assertStatus(422);
    }
}
