<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\Member;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accounting export: invoices and payments as CSV that Excel opens
 * cleanly (UTF-8 BOM). Held close — the Admin, plus exactly the Subadmin(s)
 * the Admin named with the exports.excel grant. Nobody else, whatever other
 * rights they hold: this file is the company's whole book.
 */
class ExportController extends Controller
{
    /** The gate: the Admin, or a Subadmin named by the Admin. */
    private function allowExport(Request $request): Member
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $named = in_array('exports.excel', (array) ($me->capabilities ?? []), true);
        abort_unless(
            $me->crm_role === 'admin' || ($me->crm_role === 'subadmin' && $named),
            403,
            'The accounting export is the Admin’s, plus the Subadmin the Admin has named.',
        );

        return $me;
    }

    public function invoices(Request $request): StreamedResponse
    {
        $me = $this->allowExport($request);
        $org = $request->attributes->get('crm_org');

        $query = Invoice::with(['client:id,company_name', 'issuingCompany:id,name', 'member.user:id,name'])
            ->withSum('payments as received', 'amount')
            ->where('organization_id', $org->id)
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind),
                fn ($q) => $q->where('kind', 'invoice'))
            ->when($request->query('date_from'), fn ($q, $d) => $q->whereDate('invoice_date', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->whereDate('invoice_date', '<=', $d))
            ->orderBy('invoice_date')->orderBy('id');

        ActivityLog::record($me, $org->id, 'export.invoices', $org, [
            'range' => trim(($request->query('date_from') ?? '…') . ' to ' . ($request->query('date_to') ?? '…')),
        ]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8 (and ₹)
            fputcsv($out, ['Number', 'Kind', 'Date', 'Due date', 'Client', 'Issuing company', 'Salesperson',
                'Currency', 'Subtotal', 'Discount', 'CGST', 'SGST', 'IGST', 'Other tax', 'TDS', 'Total',
                'INR equivalent', 'Received', 'Due', 'Payment status', 'Status']);
            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $i) {
                    fputcsv($out, [
                        $i->number, $i->kind,
                        $i->invoice_date?->toDateString(), $i->due_date?->toDateString(),
                        $i->client?->company_name, $i->issuingCompany?->name, $i->member?->user?->name,
                        $i->currency ?: 'INR',
                        (float) $i->subtotal, (float) $i->discount, (float) $i->cgst, (float) $i->sgst,
                        (float) $i->igst, (float) $i->other_tax, (float) $i->tds, (float) $i->total,
                        $i->total_fx !== null ? (float) $i->total_fx : '',
                        (float) ($i->received ?? 0),
                        round(max(0, (float) $i->total - (float) ($i->received ?? 0)), 2),
                        $i->payment_status, $i->status,
                    ]);
                }
            });
            fclose($out);
        }, 'invoices-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function payments(Request $request): StreamedResponse
    {
        $me = $this->allowExport($request);
        $org = $request->attributes->get('crm_org');

        $query = InvoicePayment::with(['invoice.client:id,company_name', 'invoice.issuingCompany:id,name'])
            ->whereHas('invoice', fn ($q) => $q->where('organization_id', $org->id))
            ->when($request->query('date_from'), fn ($q, $d) => $q->whereDate('received_at', '>=', $d))
            ->when($request->query('date_to'), fn ($q, $d) => $q->whereDate('received_at', '<=', $d))
            ->orderBy('received_at')->orderBy('id');

        ActivityLog::record($me, $org->id, 'export.payments', $org, [
            'range' => trim(($request->query('date_from') ?? '…') . ' to ' . ($request->query('date_to') ?? '…')),
        ]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Received on', 'Invoice', 'Client', 'Issuing company', 'Gross amount',
                'Gateway/bank charge', 'Net amount', 'Mode', 'Reference', 'Drawee bank', 'Note']);
            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    fputcsv($out, [
                        $p->received_at instanceof \Carbon\Carbon ? $p->received_at->toDateString() : (string) $p->received_at,
                        $p->invoice?->number, $p->invoice?->client?->company_name, $p->invoice?->issuingCompany?->name,
                        (float) $p->amount, (float) ($p->charge_amount ?? 0), $p->netAmount(),
                        $p->payment_mode, $p->reference_no, $p->drawee_bank, $p->note,
                    ]);
                }
            });
            fclose($out);
        }, 'payments-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
