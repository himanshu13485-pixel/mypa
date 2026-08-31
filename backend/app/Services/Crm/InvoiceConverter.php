<?php

namespace App\Services\Crm;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Proforma → tax invoice.
 *
 * Two doors lead here: someone pressing Convert, and money being settled
 * against a proforma — most payments arrive against one, and the tax invoice
 * is what follows the money. Both must produce the same document, so the work
 * lives in one place.
 */
class InvoiceConverter
{
    /**
     * Convert, keeping the proforma and linking the two.
     *
     * The user is optional: a payment gateway settling a link converts a
     * proforma too, and nobody pressed anything.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException via abort()
     */
    public function convert(Invoice $proforma, ?User $user = null, ?Member $actor = null): Invoice
    {
        if ($proforma->kind !== 'proforma') {
            abort(422, 'Only a proforma invoice can be converted.');
        }
        if ($proforma->status === 'cancelled') {
            abort(422, 'A cancelled proforma cannot be converted.');
        }
        if ($proforma->convertedTo()->exists()) {
            abort(422, 'This proforma has already been converted.');
        }

        $invoice = DB::transaction(function () use ($proforma, $user) {
            $company = IssuingCompany::where('organization_id', $proforma->organization_id)
                ->findOrFail($proforma->issuing_company_id);

            $invoice = $proforma->replicate(['uuid', 'kind', 'number', 'converted_from_id', 'created_by', 'updated_by']);
            $invoice->kind = 'invoice';
            $invoice->number = $company->claimNumber('invoice');
            $invoice->invoice_date = now()->toDateString();
            $invoice->converted_from_id = $proforma->id;
            $invoice->payment_status = 'due';
            $invoice->created_by = $user?->id;
            $invoice->save();

            foreach ($proforma->taxes as $tax) {
                $invoice->taxes()->create($tax->only(['key', 'label', 'kind', 'basis', 'rate', 'amount', 'sort']));
            }

            foreach ($proforma->items as $item) {
                $invoice->items()->create($item->only([
                    'membership', 'plan_name', 'description', 'custom_fields', 'validity_from',
                    'validity_to', 'qty', 'unit_price', 'amount', 'amount_fx', 'sort',
                ]));
            }

            return $invoice;
        });

        $trail = [
            'number' => $proforma->number,
            'client' => $proforma->client?->company_name,
            'total' => (float) $proforma->total,
        ];

        ActivityLog::record($actor, $proforma->organization_id, 'proforma.converted', $proforma,
            $trail + ['invoice' => $invoice->number]);
        ActivityLog::record($actor, $proforma->organization_id, 'invoice.created', $invoice,
            ['number' => $invoice->number, 'client' => $trail['client'], 'total' => (float) $invoice->total]
            + ['from_proforma' => $proforma->number]);

        return $invoice;
    }
}
