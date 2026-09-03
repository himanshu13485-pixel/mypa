<?php

namespace App\Services\Crm;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Organization;
use App\Models\Crm\RecurringInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The runs of a repeating bill.
 *
 * Each due schedule copies its source document into a fresh one — items,
 * taxes and Work Order fields carried over, the number claimed from the same
 * series, dated the day it runs. Run dates are counted from the start date,
 * never from the last run, so a bill anchored to the 31st does not drift.
 *
 * A run can also send the document and raise a Cashfree link. Either of
 * those failing must not stop the billing: the document is the point, the
 * rest is remembered on the schedule as its last error.
 */
class RecurringInvoiceGenerator
{
    public function __construct(private CashfreeGateway $cashfree)
    {
    }

    /**
     * Run everything due for one company.
     *
     * @return array{generated: int, completed: int, failed: int}
     */
    public function runFor(Organization $organization, bool $dryRun = false): array
    {
        $result = ['generated' => 0, 'completed' => 0, 'failed' => 0];

        $due = RecurringInvoice::with('source.items', 'source.taxes', 'source.client', 'client')
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->whereDate('next_run_on', '<=', now()->toDateString())
            ->orderBy('next_run_on')
            ->get();

        foreach ($due as $schedule) {
            // A schedule can be several cycles behind (the server was down, a
            // long pause) — each missed cycle is still owed its document.
            while ($schedule->status === 'active'
                && $schedule->hasRunsLeft()
                && ! $schedule->runDate($schedule->occurrences)->isAfter(now())) {
                if ($dryRun) {
                    $result['generated']++;
                    $schedule->occurrences++;
                    continue;
                }

                try {
                    $this->generateOne($schedule);
                    $result['generated']++;
                } catch (\Throwable $e) {
                    // A broken schedule must not take the rest of the
                    // morning's billing down with it.
                    $schedule->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
                    $result['failed']++;
                    break;
                }
            }

            if (! $dryRun && $schedule->status === 'active') {
                if (! $schedule->hasRunsLeft()) {
                    $schedule->update(['status' => 'completed']);
                    $result['completed']++;
                } else {
                    $schedule->update(['next_run_on' => $schedule->runDate($schedule->occurrences)->toDateString()]);
                }
            }
        }

        return $result;
    }

    /** One cycle: the document, and whatever the schedule sends with it. */
    /**
     * Run ONE schedule up to date — every cycle already owed gets its
     * document now. Used the moment a schedule is created with a date that
     * has already arrived: "raise it on the 15th" said on the 30th means
     * "raise it now", not "wait for tomorrow's timer".
     *
     * @return int how many documents were raised
     */
    public function catchUp(RecurringInvoice $schedule): int
    {
        $raised = 0;

        while ($schedule->status === 'active'
            && $schedule->hasRunsLeft()
            && ! $schedule->runDate($schedule->occurrences)->isAfter(now())) {
            $this->generateOne($schedule);
            $raised++;
        }

        if ($schedule->status === 'active') {
            if (! $schedule->hasRunsLeft()) {
                $schedule->update(['status' => 'completed']);
            } else {
                $schedule->update(['next_run_on' => $schedule->runDate($schedule->occurrences)->toDateString()]);
            }
        }

        return $raised;
    }

    public function generateOne(RecurringInvoice $schedule): Invoice
    {
        $source = $schedule->source;
        if (! $source) {
            abort(422, 'The document this schedule copies is gone.');
        }

        $runDate = $schedule->runDate($schedule->occurrences);

        $invoice = DB::transaction(function () use ($schedule, $source, $runDate) {
            $company = IssuingCompany::where('organization_id', $schedule->organization_id)
                ->findOrFail($source->issuing_company_id);

            $invoice = $source->replicate(['uuid', 'number', 'converted_from_id', 'created_by', 'updated_by']);
            $invoice->number = $company->claimNumber($source->kind);
            $invoice->invoice_date = $runDate->toDateString();
            // The gap between raised and due is part of the deal, so it is
            // carried, not the old dates themselves.
            $invoice->due_date = $source->due_date
                ? $runDate->copy()->addDays((int) $source->invoice_date->diffInDays($source->due_date))->toDateString()
                : null;
            $invoice->payment_status = 'due';
            $invoice->dispatch_status = 'pending';
            $invoice->status = 'final';
            // The note is stamped at raising — a snapshot, so cancelling the
            // schedule later never rewrites paperwork already sent.
            $invoice->recurring_note = $schedule->noteFor($schedule->occurrences);
            $invoice->recurring_invoice_id = $schedule->id;
            $invoice->created_by = $schedule->created_by;
            $invoice->save();

            foreach ($source->taxes as $tax) {
                $invoice->taxes()->create($tax->only(['key', 'label', 'kind', 'basis', 'rate', 'amount', 'sort']));
            }
            foreach ($source->items as $item) {
                $invoice->items()->create($item->only([
                    'membership', 'plan_name', 'description', 'custom_fields', 'validity_from',
                    'validity_to', 'qty', 'unit_price', 'amount', 'amount_fx', 'sort',
                ]));
            }

            $schedule->forceFill([
                'occurrences' => $schedule->occurrences + 1,
                'last_invoice_id' => $invoice->id,
                'last_run_at' => now(),
                'last_error' => null,
            ])->save();

            return $invoice;
        });

        ActivityLog::record(null, $schedule->organization_id, $source->kind . '.created', $invoice, array_filter([
            'number' => $invoice->number,
            'client' => $source->client?->company_name,
            'total' => (float) $invoice->total,
            'recurring' => $source->number,
        ]));

        // The extras, best-effort: their failure is noted, never fatal.
        $link = null;
        if ($schedule->auto_payment_link) {
            try {
                $link = $this->cashfree->createLink(
                    $invoice->load('client', 'organization'),
                    (float) $invoice->total,
                    $schedule->creator,
                );
            } catch (\Throwable $e) {
                $schedule->update(['last_error' => 'Payment link: ' . mb_substr($e->getMessage(), 0, 480)]);
            }
        }

        if ($schedule->auto_email && filled($source->client?->email)) {
            try {
                $this->email($schedule, $invoice, $link?->link_url);
            } catch (\Throwable $e) {
                $schedule->update(['last_error' => 'E-mail: ' . mb_substr($e->getMessage(), 0, 480)]);
            }
        }

        return $invoice;
    }

    /** The document in the client's inbox, PDF attached, link included. */
    private function email(RecurringInvoice $schedule, Invoice $invoice, ?string $payUrl): void
    {
        $client = $schedule->source?->client;
        $company = $invoice->issuingCompany;
        $money = ($invoice->currency ?: 'INR') . ' ' . number_format((float) $invoice->total, 2);

        $lines = array_filter([
            'Dear ' . ($client->contact_person ?: $client->company_name) . ',',
            '',
            'Please find attached ' . ($invoice->kind === 'proforma' ? 'proforma invoice ' : 'invoice ')
                . $invoice->number . ' dated ' . $invoice->invoice_date->format('d M Y') . ' for ' . $money . '.',
            $invoice->due_date ? 'It is payable by ' . $invoice->due_date->format('d M Y') . '.' : null,
            '',
            $payUrl ? 'You can pay online here: ' . $payUrl : null,
            $payUrl ? '' : null,
            'Regards,',
            $company?->name,
        ], fn ($l) => $l !== null);

        /*
         * The letterhead and the stamp, which this path never passed.
         *
         * The same invoice looked different depending on whether a person
         * pressed send or a schedule did: no logo, no stamp, just the words.
         * Resolved to real paths because dompdf reads from disk, and checked
         * for existence because a missing file breaks the whole document
         * rather than one image.
         */
        $logoPath = $company?->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path)
            : null;
        $stampPath = $company?->stamp_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->stamp_path)
            : null;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('crm.document', [
            'invoice' => $invoice->load(['client', 'issuingCompany', 'items', 'taxes', 'member.user:id,name', 'payments']),
            'company' => $company,
            'logoPath' => $logoPath && is_file($logoPath) ? $logoPath : null,
            'stampPath' => $stampPath && is_file($stampPath) ? $stampPath : null,
            'currency' => $invoice->currency ?: 'INR',
            'received' => 0.0,
            'columns' => collect(\App\Models\Crm\CustomField::workOrderMethod($invoice->organization_id))
                ->where('source', 'builtin')->keyBy('key')->all(),
            'extraColumns' => collect(\App\Models\Crm\CustomField::workOrderMethod($invoice->organization_id))
                ->where('source', 'custom')->values()->all(),
            'moneyLines' => $invoice->taxes->map(fn ($t) => [
                'label' => $t->label, 'rate' => $t->rate, 'amount' => (float) $t->amount,
                'sign' => $t->kind === 'tax' ? '+' : '-',
            ])->filter(fn ($line) => $line['amount'] > 0)->values()->all(),
            'documentFields' => [],
            'headings' => collect(\App\Models\Crm\CustomField::invoiceMethod($invoice->organization_id))
                ->where('source', 'builtin')->keyBy('key')->all(),
            'bank' => \App\Models\Crm\BankAccount::where('organization_id', $invoice->organization_id)
                ->where('is_active', true)->orderBy('id')->first(),
        ])->setPaper('a4');

        /*
         * Through the issuing company's own mailbox.
         *
         * The invoice screen has always sent this way; the recurring
         * generator did not, so the same invoice arrived from a different
         * address depending on whether a person pressed send or a schedule
         * did — and the scheduled one failed the sending domain's SPF.
         */
        $resolved = (new CompanyMailer($invoice->organization))
            ->resolve($invoice->issuing_company_id, 'invoice');

        $resolved['mailer']->html(nl2br(e(implode("\n", $lines))), function ($message) use ($client, $invoice, $pdf, $resolved) {
            $message->from($resolved['address'], $resolved['name'])
                ->to($client->email)
                ->subject(($invoice->kind === 'proforma' ? 'Proforma invoice ' : 'Invoice ') . $invoice->number)
                ->attachData($pdf->output(), str_replace(['/', '\\', ' '], '-', $invoice->number) . '.pdf', [
                    'mime' => 'application/pdf',
                ]);
        });
    }
}
