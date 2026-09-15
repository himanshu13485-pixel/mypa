<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use App\Notifications\CrmNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * CRM mail is the company writing to its own staff: its subject, heading,
 * sign-off and footer carry the company's name, not Netvork's - whichever
 * server ends up carrying it.
 */
class CrmMailCarriesCompanyNameTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $org = Organization::create(['name' => 'GrapOut Strategic Partners', 'code' => 'GRAP', 'status' => 'active']);
        $user = User::factory()->create(['name' => 'Priyanshu Yadav', 'email' => 'accounts@grapmail.test']);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);
        Member::create(['organization_id' => $org->id, 'user_id' => $user->id, 'crm_role' => 'subadmin', 'status' => 'active']);

        return $user;
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function sent(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage())
            ->all();
    }

    public function test_a_crm_mail_to_staff_is_headed_and_signed_by_the_company(): void
    {
        config(['mail.default' => 'array', 'app.name' => 'Netvork']);
        $user = $this->staff();

        Notification::sendNow($user, new CrmNotification('crm_approval', 'Vishal requested approval: Office Recharge — ₹378.', '/crm/approvals'), ['mail']);

        $mail = $this->sent()[0];
        $html = $mail->getHtmlBody();

        $this->assertSame('GrapOut Strategic Partners — Approval', $mail->getSubject());
        $this->assertStringContainsString('Open CRM', $html);
        $this->assertStringContainsString('Regards,<br>GrapOut Strategic Partners', $html);
        $this->assertStringContainsString('GrapOut Strategic Partners. All rights reserved.', $html);
        $this->assertStringNotContainsString('Netvork', $html);

        // The worker lives on: the platform's own name is back for the next mail.
        $this->assertSame('Netvork', config('app.name'));
    }

    public function test_somebody_who_is_nobodys_employee_still_hears_from_netvork(): void
    {
        config(['mail.default' => 'array', 'app.name' => 'Netvork']);
        $user = User::factory()->create(['email' => 'solo@example.test']);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        Notification::sendNow($user, new CrmNotification('crm_task', 'A task for you.', '/crm/tasks'), ['mail']);

        $mail = $this->sent()[0];
        $this->assertSame('Netvork CRM — Task', $mail->getSubject());
        $this->assertStringContainsString('Netvork', $mail->getHtmlBody());
    }
}
