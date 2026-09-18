<?php

namespace App\Services\Crm;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoiceItem;
use App\Models\Crm\Organization;
use App\Models\Crm\RenewalReminder;
use App\Notifications\CrmNotification;
use App\Services\Crm\CompanyMailer;
use Illuminate\Support\Carbon;

/**
 * Saying a work order is about to run out, while there is still time to sell
 * the renewal.
 *
 * Three warnings ahead of each expiry — a month, a fortnight, a week, or
 * whatever the company set. One is easy to miss; a daily drip is easy to
 * ignore.
 *
 * Who hears it:
 *
 *   the executive  — always a notification, because it is their sale, and
 *                    their e-mail too unless the company turns it off;
 *   the client     — only by e-mail, and only if the company has ticked it.
 *                    Writing to a client is a decision, not a default that
 *                    somebody discovers after it has gone out.
 *
 * Each warning is written down as it goes, so tomorrow's sweep knows not to
 * say it again — see the unique key on crm_renewal_reminders.
 */
class RenewalWarner
{
    /**
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function runFor(Organization $organization, bool $dryRun = false): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $schedule = $organization->renewalReminders();

        if (! $schedule['enabled']) {
            return $result;
        }

        /*
         * The dates being warned about, as whole days.
         *
         * Asked as "which work orders end on exactly one of these days"
         * rather than "within thirty days", so the three warnings are three
         * events rather than one long window that fires every morning.
         */
        $wanted = collect($schedule['offsets'])
            ->mapWithKeys(fn (int $days) => [now()->addDays($days)->toDateString() => $days]);

        $items = InvoiceItem::with(['invoice.client', 'invoice.member.user', 'invoice.issuingCompany'])
            /*
             * By date, not by string.
             *
             * The column is a date, but the store keeps "2026-10-19 00:00:00"
             * in it - so matching the text "2026-10-19" found nothing at all
             * and the sweep ran silently every morning.
             */
            ->where(function ($q) use ($wanted) {
                foreach ($wanted->keys() as $date) {
                    $q->orWhereDate('validity_to', $date);
                }
            })
            ->whereHas('invoice', fn ($i) => $i
                ->where('organization_id', $organization->id)
                ->where('kind', 'invoice')
                ->where('status', '!=', 'cancelled'))
            ->get();

        foreach ($items as $item) {
            $offset = (int) $wanted[$item->validity_to->toDateString()];
            $invoice = $item->invoice;
            if (! $invoice) {
                continue;
            }

            // The executive always hears about it, in the app.
            $result[$this->tell($organization, $invoice, $item, $offset, 'executive', 'notification', $dryRun)]++;

            if ($schedule['email_executive']) {
                $result[$this->tell($organization, $invoice, $item, $offset, 'executive', 'email', $dryRun)]++;
            }
            if ($schedule['email_client']) {
                $result[$this->tell($organization, $invoice, $item, $offset, 'client', 'email', $dryRun)]++;
            }
        }

        return $result;
    }

    /**
     * One warning, to one audience, on one channel — once.
     *
     * @return 'sent'|'failed'|'skipped'
     */
    private function tell(
        Organization $organization,
        Invoice $invoice,
        InvoiceItem $item,
        int $offset,
        string $audience,
        string $channel,
        bool $dryRun,
    ): string {
        $already = RenewalReminder::where('invoice_item_id', $item->id)
            ->where('offset_days', $offset)
            ->where('audience', $audience)
            ->where('channel', $channel)
            ->exists();

        if ($already) {
            return 'skipped';
        }

        $to = $audience === 'client' ? $invoice->client?->email : $invoice->member?->user?->email;
        if ($channel === 'email' && ! $to) {
            // Nobody to write to is not a failure - it is a client without an
            // address on file, and saying "failed" every morning about it
            // would bury the ones that are.
            return 'skipped';
        }

        if ($dryRun) {
            return 'sent';
        }

        $row = new RenewalReminder([
            'organization_id' => $organization->id,
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item->id,
            'offset_days' => $offset,
            'audience' => $audience,
            'channel' => $channel,
            'to_email' => $channel === 'email' ? $to : null,
            'expires_on' => $item->validity_to->toDateString(),
        ]);

        try {
            if ($channel === 'notification') {
                $invoice->member?->user?->notify(new CrmNotification(
                    'crm_invoice_update',
                    $this->line($invoice, $item, $offset),
                    '/crm/invoices/' . $invoice->uuid,
                ));
            } else {
                $resolved = (new CompanyMailer($organization))->resolve($invoice->issuing_company_id, 'general');
                $body = $this->line($invoice, $item, $offset);
                $resolved['mailer']->html(nl2br(e($body)), function ($message) use ($to, $resolved, $item, $offset) {
                    $message->to($to)
                        ->from($resolved['address'], $resolved['name'])
                        ->subject($this->subject($item, $offset));
                });
            }

            $row->fill(['status' => 'sent']);
        } catch (\Throwable $e) {
            $row->fill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        $row->save();

        ActivityLog::record(null, $organization->id, 'invoice.renewal_warned', $invoice, array_filter([
            'number' => $invoice->number,
            'plan' => $item->plan_name,
            'expires_on' => $item->validity_to->toDateString(),
            'days' => $offset,
            'audience' => $audience,
            'channel' => $channel,
            'status' => $row->status,
        ]));

        return $row->status === 'sent' ? 'sent' : 'failed';
    }

    private function subject(InvoiceItem $item, int $offset): string
    {
        $what = $item->plan_name ?: ($item->membership ?: 'A work order');

        return $what . ' expires in ' . $offset . ' ' . ($offset === 1 ? 'day' : 'days');
    }

    private function line(Invoice $invoice, InvoiceItem $item, int $offset): string
    {
        $what = trim(($item->membership ? $item->membership . ' · ' : '') . ($item->plan_name ?: ''));

        return sprintf(
            "%s on %s (%s) runs out on %s — %d %s from today.\n\nRaised %s. Worth a call before it lapses.",
            $what !== '' ? $what : 'A work order',
            $invoice->number,
            $invoice->client?->company_name ?: 'the client',
            $item->validity_to->format('d M Y'),
            $offset,
            $offset === 1 ? 'day' : 'days',
            $invoice->invoice_date?->format('d M Y') ?: 'earlier',
        );
    }
}
