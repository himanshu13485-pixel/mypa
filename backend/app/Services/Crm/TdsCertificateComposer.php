<?php

namespace App\Services\Crm;

use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use Illuminate\Support\Collection;

/**
 * The words a TDS certificate request starts with.
 *
 * One letter per client rather than one per invoice: a client who deducted
 * tax on four of our bills should be asked once, with the four listed, not
 * mailed four times in a morning. Whoever sends it may then say it
 * differently.
 */
class TdsCertificateComposer
{
    /**
     * @param  Collection<int, Invoice>  $invoices  all belonging to one client
     */
    public function draft(Collection $invoices, ?Member $me): array
    {
        $first = $invoices->first();
        $client = $first?->client;
        $company = $first?->issuingCompany?->name ?? 'us';
        $currency = $first?->currency ?: 'INR';
        $total = round($invoices->sum(fn (Invoice $i) => (float) $i->tds), 2);

        $rows = $invoices->map(fn (Invoice $i) => '  - ' . $i->number
            . ' dated ' . $i->invoice_date->format('d M Y')
            . ': TDS ' . $currency . ' ' . number_format((float) $i->tds, 2));

        $lines = array_merge([
            'Dear ' . ($client?->contact_person ?: ($client?->company_name ?? 'Sir/Madam')) . ',',
            '',
            'Tax was deducted at source on the following '
                . ($invoices->count() === 1 ? 'invoice' : 'invoices') . ' raised by ' . $company . ':',
            '',
        ], $rows->all(), [
            '',
            'The total tax deducted is ' . $currency . ' ' . number_format($total, 2) . '.',
            '',
            'We would be grateful if you could send us the TDS certificate (Form 16A) for '
                . $this->period($invoices) . ', so that we can reconcile it against our 26AS.',
            '',
            'If the certificate has already been issued, please ignore this note and accept our thanks.',
            '',
            'Regards,',
            $me?->user?->name ?? '',
            $company,
        ]);

        return [
            'to_email' => $client?->email,
            'subject' => 'TDS certificate request: ' . ($invoices->count() === 1
                ? 'invoice ' . $first?->number
                : $invoices->count() . ' invoices'),
            'body' => implode("\n", $lines),
            'tds_total' => $total,
        ];
    }

    /**
     * The quarter, or the span of them - a certificate is issued by quarter,
     * so that is the unit worth asking for.
     */
    private function period(Collection $invoices): string
    {
        $quarters = $invoices
            ->sortBy(fn (Invoice $i) => $i->invoice_date)
            ->map(fn (Invoice $i) => $this->quarterOf($i))
            ->unique()
            ->values();

        return $quarters->count() === 1
            ? (string) $quarters->first()
            : $quarters->first() . ' to ' . $quarters->last();
    }

    /** Indian financial year quarters: April to June is Q1. */
    private function quarterOf(Invoice $invoice): string
    {
        $date = $invoice->invoice_date;
        $month = (int) $date->format('n');
        $quarter = (int) floor((($month - 4 + 12) % 12) / 3) + 1;
        $startYear = $month >= 4 ? (int) $date->format('Y') : (int) $date->format('Y') - 1;

        return 'Q' . $quarter . ' FY ' . $startYear . '-' . substr((string) ($startYear + 1), 2);
    }
}
