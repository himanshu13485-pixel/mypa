<?php

namespace Tests\Feature;

use App\Services\Mail\MailGuard;
use Tests\TestCase;

/**
 * Mail that is pretending to be somebody else.
 *
 * The mail server's own spam filter catches the obvious rubbish. What gets
 * through, and what costs companies money, is the message that looks like an
 * invoice from a supplier - so these are the checks a careful person would
 * make by hand, and the ones this has to keep making.
 */
class MailGuardTest extends TestCase
{
    private function weigh(array $mail): array
    {
        return app(MailGuard::class)->weigh($mail);
    }

    public function test_an_ordinary_message_is_left_alone(): void
    {
        $verdict = $this->weigh([
            'subject' => 'Rates for March',
            'from_email' => 'priya@client.test',
            'from_name' => 'Priya Sharma',
            'html' => '<p>Hello, our rates are attached.</p><p><a href="https://client.test/rates">client.test/rates</a></p>',
            'attachments' => ['rates.pdf'],
            'headers' => 'Authentication-Results: mx.test; spf=pass dkim=pass dmarc=pass',
        ]);

        $this->assertSame(0, $verdict['score']);
        $this->assertFalse($verdict['suspicious']);
        $this->assertSame([], $verdict['reasons']);
    }

    public function test_a_name_that_shows_one_address_and_sends_from_another_is_caught(): void
    {
        $verdict = $this->weigh([
            'subject' => 'Invoice',
            'from_email' => 'billing@random-host.test',
            'from_name' => 'Accounts <accounts@grapout.test>',
        ]);

        $this->assertGreaterThanOrEqual(MailGuard::SUSPICIOUS_AT, $verdict['score']);
        $this->assertStringContainsString('accounts@grapout.test', $verdict['reasons'][0]);
        $this->assertStringContainsString('billing@random-host.test', $verdict['reasons'][0]);
    }

    public function test_a_link_that_says_one_place_and_goes_to_another_is_caught(): void
    {
        $verdict = $this->weigh([
            'from_email' => 'noreply@bank-alerts.test',
            'html' => '<p><a href="https://evil.test/login">yourbank.test/login</a></p>',
        ]);

        $this->assertGreaterThanOrEqual(MailGuard::SUSPICIOUS_AT, $verdict['score']);
        $this->assertStringContainsString('goes to evil.test', implode(' ', $verdict['reasons']));
    }

    public function test_failed_sender_checks_and_a_program_attached_land_it_in_spam(): void
    {
        $verdict = $this->weigh([
            'subject' => 'Your password will expire - verify your account',
            'from_email' => 'it@support-desk.test',
            'html' => '<p>Click here immediately</p>',
            'attachments' => ['update.exe'],
            'headers' => 'Authentication-Results: mx.test; spf=fail dkim=fail dmarc=fail',
        ]);

        $this->assertTrue($verdict['spam']);
        $this->assertStringContainsString('update.exe', implode(' ', $verdict['reasons']));
        $this->assertStringContainsString('SPF', implode(' ', $verdict['reasons']));
    }

    public function test_bare_addresses_shorteners_and_look_alike_domains_are_noticed(): void
    {
        $ip = $this->weigh(['from_email' => 'a@b.test', 'html' => '<p><a href="http://203.0.113.9/pay">Pay now</a></p>']);
        $this->assertStringContainsString('bare IP address', implode(' ', $ip['reasons']));

        $short = $this->weigh(['from_email' => 'a@b.test', 'html' => '<p><a href="https://bit.ly/x9">Open</a></p>']);
        $this->assertStringContainsString('shortened', implode(' ', $short['reasons']));

        $punycode = $this->weigh(['from_email' => 'a@b.test', 'html' => '<p><a href="https://xn--80ak6aa92e.test/">Sign in</a></p>']);
        $this->assertStringContainsString('look-alike', implode(' ', $punycode['reasons']));
    }

    public function test_replies_pointed_somewhere_else_are_noticed(): void
    {
        $verdict = $this->weigh([
            'from_email' => 'ceo@grapout.test',
            'reply_to' => 'ceo.grapout@gmail-mail.test',
        ]);

        $this->assertStringContainsString('Replies would go to', implode(' ', $verdict['reasons']));
    }

    public function test_a_held_back_link_keeps_its_address_visible_but_not_clickable(): void
    {
        $disarmed = MailGuard::disarm('<p>Hello <a href="https://evil.test/login">click here</a></p>');

        $this->assertStringNotContainsString('<a ', $disarmed);
        $this->assertStringNotContainsString('href=', $disarmed);
        $this->assertStringContainsString('click here', $disarmed, 'the words stay');
        $this->assertStringContainsString('https://evil.test/login', $disarmed, 'and so does where it would have gone');
    }
}
