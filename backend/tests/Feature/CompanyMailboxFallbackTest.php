<?php

namespace Tests\Feature;

use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Organization;
use App\Services\Crm\CompanyMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * A company that set up its own SMTP meant all of its mail, not just invoices.
 *
 * Mail belonging to no single issuing company - a newsletter, a staff sign-in
 * code, a scheduled invoice - used to fall straight through to the platform's
 * server: sent as Netvork, from an address the recipient does not recognise,
 * and failing the SPF and DKIM records of the domain it claims.
 */
class CompanyMailboxFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Acme', 'code' => 'ACME', 'status' => 'active']);
    }

    private function withMailbox(): IssuingCompany
    {
        $company = IssuingCompany::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Exports',
        ]);

        $this->org->update(['settings' => [
            'communication' => [
                'email_enabled' => true,
                'company_senders' => [
                    (string) $company->id => [
                        'mailer' => 'smtp',
                        'from_address' => 'accounts@acme-exports.com',
                        'from_name' => 'Acme Exports',
                        'smtp_host' => 'smtp.acme-exports.com',
                        'smtp_port' => 587,
                        'smtp_username' => 'accounts',
                        'smtp_password' => Crypt::encryptString('hunter2'),
                    ],
                ],
            ],
        ]]);

        return $company;
    }

    private function addSender(IssuingCompany $company, array $sender): void
    {
        $settings = $this->org->fresh()->settings;
        $settings['communication']['company_senders'][(string) $company->id] = $sender;
        $this->org->update(['settings' => $settings]);
    }

    public function test_a_company_with_one_mailbox_uses_it_for_unattributed_mail(): void
    {
        $this->withMailbox();

        $resolved = (new CompanyMailer($this->org->fresh()))->resolve(null);

        $this->assertSame('accounts@acme-exports.com', $resolved['address']);
        $this->assertSame('Acme Exports', $resolved['name']);
    }

    public function test_a_company_with_no_mailbox_still_falls_back_to_the_platform(): void
    {
        $resolved = (new CompanyMailer($this->org))->resolve(null);

        $this->assertSame(config('mail.from.address'), $resolved['address']);
    }

    /** An answer somebody chose beats one this code inferred. */
    public function test_the_mailbox_marked_as_report_sender_wins(): void
    {
        $this->withMailbox();
        $second = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Logistics']);
        $this->addSender($second, [
            'mailer' => 'smtp',
            'from_address' => 'hello@acme-logistics.com',
            'from_name' => 'Acme Logistics',
            'is_report_sender' => true,
        ]);

        $resolved = (new CompanyMailer($this->org->fresh()))->resolve(null);

        $this->assertSame('hello@acme-logistics.com', $resolved['address']);
    }

    /**
     * Two mailboxes and nothing marking either is not an answer.
     *
     * Guessing between them would put one company's name on the other's mail.
     */
    public function test_two_unmarked_mailboxes_is_no_answer_at_all(): void
    {
        $this->withMailbox();
        $second = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Logistics']);
        $this->addSender($second, ['mailer' => 'smtp', 'from_address' => 'hello@acme-logistics.com']);

        $this->assertNull((new CompanyMailer($this->org->fresh()))->houseMailbox());
    }

    /** The company that pays the salaries is the one that employs people. */
    public function test_the_salary_paying_company_is_preferred_when_nothing_is_marked(): void
    {
        $this->withMailbox();
        $employer = IssuingCompany::create([
            'organization_id' => $this->org->id,
            'name' => 'Acme Holdings',
            'pays_salary' => true,
        ]);
        $this->addSender($employer, ['mailer' => 'smtp', 'from_address' => 'people@acme-holdings.com']);

        $this->assertSame(
            'people@acme-holdings.com',
            (new CompanyMailer($this->org->fresh()))->houseMailbox()['from_address'],
        );
    }

    /** A named issuing company still wins over the house mailbox. */
    public function test_naming_a_company_still_picks_that_companys_mailbox(): void
    {
        $company = $this->withMailbox();
        $other = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Logistics']);
        $this->addSender($other, [
            'mailer' => 'smtp',
            'from_address' => 'hello@acme-logistics.com',
            'is_report_sender' => true,
        ]);

        $resolved = (new CompanyMailer($this->org->fresh()))->resolve($company->id);

        $this->assertSame('accounts@acme-exports.com', $resolved['address']);
    }

    /** Switching the Email channel off switches it off, mailbox or not. */
    public function test_email_switched_off_is_still_switched_off(): void
    {
        $this->withMailbox();

        $settings = $this->org->fresh()->settings;
        $settings['communication']['email_enabled'] = false;
        $this->org->update(['settings' => $settings]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new CompanyMailer($this->org->fresh()))->resolve(null);
    }

    /**
     * A company whose own mailbox is not set up sends through the report
     * sender's mailbox - not through the platform's plain server.
     *
     * Every company has a row on the Communication screen from the start,
     * and the empty row was being taken as its sender: so a company "Not set
     * up" had its invoices leave from a server that is not the group's.
     */
    public function test_a_company_not_set_up_sends_from_the_report_sender(): void
    {
        $house = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'GrapOut Admin']);
        $cgpl = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'CGPL']);

        $this->org->update(['settings' => ['communication' => [
            'email_enabled' => true,
            'company_senders' => [
                (string) $house->id => [
                    'mailer' => 'smtp', 'from_address' => 'admin@grapout.com', 'from_name' => 'GrapOut',
                    'smtp_host' => 'smtp.grapout.com', 'is_report_sender' => true,
                ],
                // The row exists; nobody has filled it in.
                (string) $cgpl->id => ['mailer' => 'default', 'from_address' => '', 'from_name' => ''],
            ],
        ]]]);

        $resolved = (new CompanyMailer($this->org->fresh()))->resolve($cgpl->id, 'invoice');

        $this->assertSame('admin@grapout.com', $resolved['address']);
        $this->assertSame('GrapOut', $resolved['name']);
    }

    /** And a company that has set its own up sends from it. */
    public function test_a_company_set_up_sends_from_its_own_mailbox(): void
    {
        $house = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'GrapOut Admin']);
        $corpcio = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Corpcio Global LLC']);

        $this->org->update(['settings' => ['communication' => [
            'email_enabled' => true,
            'company_senders' => [
                (string) $house->id => [
                    'mailer' => 'smtp', 'from_address' => 'admin@grapout.com', 'smtp_host' => 'smtp.grapout.com',
                    'is_report_sender' => true,
                ],
                (string) $corpcio->id => [
                    'mailer' => 'smtp', 'from_address' => 'accounts@corpcio.com', 'from_name' => 'Corpcio Global LLC',
                    'smtp_host' => 'smtp.corpcio.com',
                ],
            ],
        ]]]);

        $resolved = (new CompanyMailer($this->org->fresh()))->resolve($corpcio->id, 'invoice');

        $this->assertSame('accounts@corpcio.com', $resolved['address']);
        $this->assertSame('Corpcio Global LLC', $resolved['name']);
    }
}
