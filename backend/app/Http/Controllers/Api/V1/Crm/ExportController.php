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

    /**
     * Every lead in the company, as one file.
     *
     * The Admin's alone, and not behind the exports.excel grant the two above
     * use. That grant is about the accounting book; this file is the whole
     * pipeline with a name, a mobile and an address on every row — the one
     * thing a salesperson leaving would most like a copy of. Whoever wants a
     * Subadmin to have it can say so, but they should have to say so
     * deliberately rather than inherit it from a decision about invoices.
     *
     * Deliberately not scoped to the caller's own leads either. It is the
     * company's list, which is exactly why only the person who owns the
     * company gets it.
     */
    public function leads(Request $request): StreamedResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(
            $me->crm_role === 'admin',
            403,
            'The full lead export is the Company Admin’s.',
        );

        $org = $request->attributes->get('crm_org');

        $query = \App\Models\Crm\Lead::with(['assignedMember.user:id,name', 'creator:id,name'])
            ->where('organization_id', $org->id)
            // The same narrowing the screen offers, so "download what I am
            // looking at" is a thing somebody can actually do.
            ->when($request->query('status'), fn ($q, $v) => $q->where('lead_status', $v))
            ->when($request->query('source'), fn ($q, $v) => $q->where('source', $v))
            ->when($request->query('member'), fn ($q, $v) => $q->whereHas('assignedMember', fn ($m) => $m->where('uuid', $v)))
            ->when($request->query('search'), function ($q, $term) {
                $like = '%' . $term . '%';
                $q->where(fn ($w) => $w->where('company_name', 'like', $like)
                    ->orWhere('contact_person', 'like', $like)
                    ->orWhere('mobile', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->orderBy('lead_no');

        ActivityLog::record($me, $org->id, 'export.leads', $org, [
            'filters' => array_filter($request->only(['status', 'source', 'member', 'search'])),
        ]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
            fputcsv($out, ['Lead', 'Company', 'Contact person', 'Mobile', 'Phone', 'E-mail',
                'Source', 'Type', 'Subject', 'Requirement', 'Amount', 'Status', 'Urgent',
                'Allocated to', 'Created by', 'Follow up at', 'Reopened', 'Closed at', 'Created at']);
            $query->chunk(200, function ($rows) use ($out) {
                foreach ($rows as $l) {
                    fputcsv($out, [
                        $l->lead_no, $l->company_name, $l->contact_person, $l->mobile, $l->phone, $l->email,
                        $l->source, $l->lead_type, $l->subject, $l->requirement,
                        (float) $l->amount, $l->lead_status, $l->is_urgent ? 'Yes' : '',
                        $l->assignedMember?->user?->name, $l->creator?->name,
                        $l->follow_up_at?->toDateTimeString(), $l->reopen_count ?: '',
                        $l->closed_at?->toDateTimeString(), $l->created_at?->toDateTimeString(),
                    ]);
                }
            });
            fclose($out);
        }, 'leads-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
