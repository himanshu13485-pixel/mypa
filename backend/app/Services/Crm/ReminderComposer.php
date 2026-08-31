<?php

namespace App\Services\Crm;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;

/**
 * The words a payment reminder starts with.
 *
 * One wording, whether a person is about to edit it or the schedule is about
 * to send it — a client should not be able to tell which letter was typed.
 */
class ReminderComposer
{
    /**
     * What a reminder should say: plain, dated, and specific about the
     * figure. Whoever sends it may then say it differently.
     */
    public function draft(Invoice $invoice, ?Member $me): array
    {
        $balance = $this->balance($invoice);
        $dueOn = $invoice->due_date ?? $invoice->invoice_date;
        $overdue = (int) now()->startOfDay()->diffInDays($dueOn->copy()->startOfDay(), false) * -1;
        $company = $invoice->issuingCompany?->name ?? 'us';
        $money = fn (float $v) => ($invoice->currency ?: 'INR') . ' ' . number_format($v, 2);

        $payOnline = $invoice->paymentLinks()
            ->where('status', 'active')
            ->orderByDesc('id')
            ->get()
            ->first(fn ($link) => $link->isOpen())?->link_url;

        $lines = [
            'Dear ' . ($invoice->client?->contact_person ?: ($invoice->client?->company_name ?? 'Sir/Madam')) . ',',
            '',
            $overdue > 0
                ? 'Our invoice ' . $invoice->number . ' dated ' . $invoice->invoice_date->format('d M Y')
                    . ' for ' . $money((float) $invoice->total) . ' fell due on ' . $dueOn->format('d M Y')
                    . ' and is now ' . $overdue . ' day' . ($overdue === 1 ? '' : 's') . ' overdue.'
                : 'This is a reminder about our invoice ' . $invoice->number . ' dated '
                    . $invoice->invoice_date->format('d M Y') . ' for ' . $money((float) $invoice->total)
                    . ', payable by ' . $dueOn->format('d M Y') . '.',
            '',
            (float) $invoice->total > $balance
                ? 'We have received ' . $money((float) $invoice->total - $balance) . ' so far; '
                    . $money($balance) . ' remains outstanding.'
                : 'The amount outstanding is ' . $money($balance) . '.',
            '',
            // An open link turns a chase into something the client can act
            // on without writing back.
            $payOnline ? 'You can pay online here: ' . $payOnline : null,
            $payOnline ? '' : null,
            'If the payment is already on its way, please ignore this note and accept our thanks.',
            '',
            'Regards,',
            $me?->user?->name ?? '',
            $company,
        ];

        return [
            'to_email' => $invoice->client?->email,
            'subject' => 'Payment reminder: invoice ' . $invoice->number
                . ($overdue > 0 ? ' (overdue)' : ''),
            'body' => implode("\n", array_filter($lines, fn ($l) => $l !== null)),
            'balance' => $balance,
            'days_overdue' => max(0, $overdue),
        ];
    }

    private function balance(Invoice $invoice): float
    {
        return round((float) $invoice->total - (float) $invoice->payments()->sum('amount'), 2);
    }
}
