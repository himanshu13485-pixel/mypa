<?php

namespace App\Services\Crm;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Organization;
use App\Services\Crm\CompanyMailer;
use App\Models\Crm\PaymentReminder;
use Illuminate\Support\Facades\Mail;

/**
 * The schedule that chases unpaid invoices without anyone typing.
 *
 * A company sets which days after the due date it writes on (a negative day
 * writes before it), and when to give up. Everything the schedule sends is
 * recorded in the same place as a reminder somebody wrote by hand — flagged
 * as automatic, so nobody mistakes it for a colleague's letter.
 */
class PaymentChaser
{
    public function __construct(private ReminderComposer $composer)
    {
    }

    /**
     * Run today's chasing for one company.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function runFor(Organization $organization, bool $dryRun = false): array
    {
        $schedule = $organization->reminderSchedule();
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        if (! $schedule['enabled'] || $schedule['offsets'] === []) {
            return $result;
        }

        $invoices = Invoice::with(['client', 'issuingCompany', 'member.user:id,name'])
            ->withSum('payments as received', 'amount')
            ->where('organization_id', $organization->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['due', 'partial'])
            ->get();

        $today = now()->startOfDay();

        foreach ($invoices as $invoice) {
            $balance = round((float) $invoice->total - (float) ($invoice->received ?? 0), 2);
            if ($balance <= 0) {
                continue;
            }

            $dueOn = ($invoice->due_date ?? $invoice->invoice_date)->copy()->startOfDay();
            $age = (int) $dueOn->diffInDays($today, false);

            if (! in_array($age, $schedule['offsets'], true)) {
                continue;
            }

            $already = $invoice->reminders();
            if ((clone $already)->where('is_auto', true)->count() >= $schedule['stop_after']) {
                $result['skipped']++;
                continue;
            }
            // Never twice in one day, whoever sent the first one — a client
            // chased by a colleague this morning is not chased again tonight.
            if ((clone $already)->whereDate('created_at', $today)->exists()) {
                $result['skipped']++;
                continue;
            }
            if (blank($invoice->client?->email)) {
                $result['skipped']++;
                continue;
            }

            if ($dryRun) {
                $result['sent']++;
                continue;
            }

            $result[$this->send($organization, $invoice, $balance)]++;
        }

        return $result;
    }

    /** @return 'sent'|'failed' */
    private function send(Organization $organization, Invoice $invoice, float $balance): string
    {
        $draft = $this->composer->draft($invoice, null);

        $reminder = new PaymentReminder([
            'organization_id' => $organization->id,
            'invoice_id' => $invoice->id,
            'member_id' => null,           // the schedule, not a person
            'channel' => 'email',
            'is_auto' => true,
            'to_email' => $invoice->client->email,
            'subject' => $draft['subject'],
            'body' => $draft['body'],
            'balance' => $balance,
        ]);

        try {
            $resolved = (new CompanyMailer($organization))->resolve($invoice->issuing_company_id, 'dues');
            $resolved['mailer']->html(nl2br(e($reminder->body)), function ($message) use ($reminder, $resolved) {
                $message->to($reminder->to_email)->from($resolved['address'], $resolved['name'])->subject($reminder->subject);
            });
            $reminder->fill(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $reminder->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        $reminder->save();

        // No actor: the trail should say the schedule did this, not a person.
        ActivityLog::record(null, $organization->id, 'payment.reminder', $invoice, array_filter([
            'number' => $invoice->number,
            'client' => $invoice->client?->company_name,
            'channel' => 'email',
            'to' => $reminder->to_email,
            'status' => $reminder->status,
            'balance' => $balance,
            'automatic' => true,
        ]));

        return $reminder->status === 'sent' ? 'sent' : 'failed';
    }
}
