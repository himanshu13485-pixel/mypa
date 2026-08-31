<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Document;
use App\Models\Crm\Expense;
use App\Models\Crm\ExpensePayment;
use App\Models\Crm\Vendor;
use App\Support\TextCase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The office spend register, with the GST split the old CRM tracked and
 * bill scans attached through the shared document store. Totals are
 * recomputed server-side from base + GST parts.
 *
 * Every bill names a registered vendor, and carries what has actually been
 * paid against it — so "recorded" and "settled" are two different things and
 * the balance owed is never guesswork. Payments are rows of their own: a
 * wrong one is removed, which puts the bill straight back where it was.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $query = Expense::with([
            'issuingCompany:id,name', 'creator:id,name', 'vendor:id,uuid,company_name',
            'payments.creator:id,name',
        ])
            ->withCount('documents')
            ->where('organization_id', $org->id);

        if ($from = $request->query('date_from')) {
            $query->whereDate('expense_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('expense_date', '<=', $to);
        }
        if ($company = $request->query('issuing_company_id')) {
            $query->where('issuing_company_id', $company);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($vendorUuid = $request->query('vendor')) {
            $vendor = Vendor::where('organization_id', $org->id)->where('uuid', $vendorUuid)->first();
            $query->where('vendor_id', $vendor?->id ?? 0);
        }
        if ($status = $request->query('payment_status')) {
            // "Overdue" is not a stored state — it is an unpaid bill whose
            // date has gone by, so it is asked for as one.
            if ($status === 'overdue') {
                $query->where('payment_status', '!=', 'paid')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', '<', now()->toDateString());
            } else {
                $query->where('payment_status', $status);
            }
        }
        if (($bill = $request->query('bill_available')) !== null && $bill !== '') {
            $query->where('bill_available', (bool) (int) $bill);
        }
        if (($gst = $request->query('gst_claimed')) !== null && $gst !== '') {
            $query->where('gst_claimed', (bool) (int) $gst);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('vendor_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('vendor_gstin', 'like', "%{$search}%");
            });
        }

        $all = (clone $query)->get(['id', 'total_amount', 'amount_paid', 'payment_status', 'due_date', 'base_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'category', 'expense_date', 'gst_claimed']);
        $outstanding = $all->filter(fn ($e) => $e->payment_status !== 'paid');
        $summary = [
            'count' => $all->count(),
            'total' => round($all->sum('total_amount'), 2),
            'paid' => round($all->sum('amount_paid'), 2),
            'outstanding' => round($all->sum(fn ($e) => (float) $e->total_amount - (float) $e->amount_paid), 2),
            'unpaid_bills' => $outstanding->count(),
            'overdue' => round($outstanding->filter(fn ($e) => $e->isOverdue())
                ->sum(fn ($e) => (float) $e->total_amount - (float) $e->amount_paid), 2),
            'overdue_bills' => $outstanding->filter(fn ($e) => $e->isOverdue())->count(),
            'gst_total' => round($all->sum(fn ($e) => (float) $e->cgst_amount + (float) $e->sgst_amount + (float) $e->igst_amount), 2),
            'gst_unclaimed' => round($all->where('gst_claimed', false)
                ->sum(fn ($e) => (float) $e->cgst_amount + (float) $e->sgst_amount + (float) $e->igst_amount), 2),
            'by_category' => $all->groupBy(fn ($e) => $e->category ?: 'Uncategorised')
                ->map(fn ($g, $cat) => ['category' => $cat, 'amount' => round($g->sum('total_amount'), 2), 'count' => $g->count()])
                ->sortByDesc('amount')->values(),
            'by_month' => $all->groupBy(fn ($e) => $e->expense_date->format('Y-m'))
                ->map(fn ($g, $m) => ['month' => $m, 'amount' => round($g->sum('total_amount'), 2)])
                ->sortKeys()->values()->take(-12),
        ];

        $expenses = $query->orderByDesc('expense_date')->orderByDesc('id')->paginate(25);
        $expenses->getCollection()->transform(fn ($e) => $this->serialize($e));

        return response()->json(['summary' => $summary] + $expenses->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $expense = Expense::create($this->validateExpense($request, $org->id) + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'expense.recorded', $expense, [
            'vendor' => $expense->vendor_name,
            'amount' => (float) $expense->total_amount,
        ]);

        return response()->json([
            'message' => 'Expense recorded.',
            'data' => $this->serialize($expense->load(['issuingCompany:id,name', 'vendor:id,uuid,company_name', 'payments.creator:id,name'])),
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $expense = $this->find($request, $uuid);
        $expense->update($this->validateExpense($request, $expense->organization_id));
        // Editing the amount can move a bill in or out of "paid".
        $expense->recomputePayment();

        return response()->json([
            'message' => 'Expense updated.',
            'data' => $this->serialize($expense->fresh()->load(['issuingCompany:id,name', 'vendor:id,uuid,company_name', 'payments.creator:id,name'])),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $expense = $this->find($request, $uuid);
        foreach ($expense->documents as $doc) {
            Storage::disk('local')->delete($doc->path);
            $doc->delete();
        }
        $expense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }

    // ---- Paying the bill ---------------------------------------------------

    /**
     * Record money going out against a bill. Part payments are ordinary —
     * the bill simply moves to "part paid" until the balance reaches zero.
     */
    public function pay(Request $request, string $uuid): JsonResponse
    {
        $expense = $this->find($request, $uuid);
        $balance = $expense->balance();

        if ($balance <= 0) {
            abort(422, 'This bill is already settled in full.');
        }

        $data = $request->validate([
            // Absent, the whole remaining balance goes out — the one-click
            // "mark paid" every register needs.
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'paid_on' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
            'reference_no' => ['nullable', 'string', 'max:128'],
            'bank_account_id' => ['nullable', Rule::exists('crm_bank_accounts', 'id')
                ->where('organization_id', $expense->organization_id)],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $amount = round((float) ($data['amount'] ?? $balance), 2);
        if ($amount > $balance + 0.01) {
            abort(422, 'That is more than the ' . number_format($balance, 2) . ' still owed on this bill.');
        }

        $payment = $expense->payments()->create([
            'paid_on' => $data['paid_on'] ?? now()->toDateString(),
            'amount' => $amount,
            'payment_mode' => $data['payment_mode'] ?? $expense->payment_mode,
            'reference_no' => $data['reference_no'] ?? null,
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $expense->recomputePayment();
        $expense->refresh();

        ActivityLog::record($request->attributes->get('crm_member'), $expense->organization_id, 'expense.paid', $expense, [
            'vendor' => $expense->vendor_name,
            'amount' => $amount,
            'status' => $expense->payment_status,
        ]);

        return response()->json([
            'message' => $expense->payment_status === 'paid'
                ? 'Bill settled in full.'
                : 'Payment of ' . number_format($amount, 2) . ' recorded — '
                    . number_format($expense->balance(), 2) . ' still owed.',
            'data' => $this->serialize($expense->load([
                'issuingCompany:id,name', 'vendor:id,uuid,company_name', 'payments.creator:id,name',
            ])),
            'payment_uuid' => $payment->uuid,
        ], 201);
    }

    /**
     * Undo a payment entered by mistake. The row goes rather than being
     * edited over, so the bill's standing is recomputed from what is left.
     */
    public function unpay(Request $request, string $uuid, string $paymentUuid): JsonResponse
    {
        $expense = $this->find($request, $uuid);
        $payment = $expense->payments()->where('uuid', $paymentUuid)->firstOrFail();
        $amount = (float) $payment->amount;
        $payment->delete();

        $expense->recomputePayment();
        $expense->refresh();

        ActivityLog::record($request->attributes->get('crm_member'), $expense->organization_id, 'expense.payment_removed', $expense, [
            'vendor' => $expense->vendor_name,
            'amount' => $amount,
        ]);

        return response()->json([
            'message' => 'Payment removed — ' . number_format($expense->balance(), 2) . ' owed again.',
            'data' => $this->serialize($expense->load([
                'issuingCompany:id,name', 'vendor:id,uuid,company_name', 'payments.creator:id,name',
            ])),
        ]);
    }

    // ---- Bill attachments (same pattern as employee documents) -------------

    public function uploadBill(Request $request, string $uuid): JsonResponse
    {
        $expense = $this->find($request, $uuid);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $path = $file->store('crm-documents/' . $expense->organization_id . '/expenses/' . $expense->id, 'local');

        $document = Document::create([
            'organization_id' => $expense->organization_id,
            'documentable_type' => Expense::class,
            'documentable_id' => $expense->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        $expense->update(['bill_available' => true]);

        return response()->json(['message' => 'Bill attached.', 'data' => $document->only(['uuid', 'name', 'size'])], 201);
    }

    public function downloadBill(Request $request, string $uuid, string $documentUuid): StreamedResponse
    {
        $expense = $this->find($request, $uuid);
        $document = $expense->documents()->where('uuid', $documentUuid)->firstOrFail();

        return Storage::disk('local')->download($document->path, $document->name);
    }

    public function deleteBill(Request $request, string $uuid, string $documentUuid): JsonResponse
    {
        $expense = $this->find($request, $uuid);
        $document = $expense->documents()->where('uuid', $documentUuid)->firstOrFail();

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(['message' => 'Bill removed.']);
    }

    // ---- Helpers -----------------------------------------------------------

    private function find(Request $request, string $uuid): Expense
    {
        return Expense::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function validateExpense(Request $request, int $orgId): array
    {
        $data = $request->validate([
            'expense_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'issuing_company_id' => ['nullable', Rule::exists('crm_issuing_companies', 'id')->where('organization_id', $orgId)],
            // The supplier is picked, never typed: registration comes first.
            'vendor_uuid' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:512'],
            'base_amount' => ['required', 'numeric', 'min:0'],
            'cgst_amount' => ['nullable', 'numeric', 'min:0'],
            'sgst_amount' => ['nullable', 'numeric', 'min:0'],
            'igst_amount' => ['nullable', 'numeric', 'min:0'],
            // A bill quotes a rate; the amount follows from it.
            'cgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'other_tax_label' => ['nullable', 'string', 'max:64'],
            'other_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'other_tax_amount' => ['nullable', 'numeric', 'min:0'],
            'bill_available' => ['nullable', 'boolean'],
            'gst_claimed' => ['nullable', 'boolean'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $vendor = Vendor::where('organization_id', $orgId)
            ->where('uuid', $data['vendor_uuid'])
            ->firstOrFail();
        unset($data['vendor_uuid']);

        // The name and GSTIN are snapshotted onto the bill, so renaming a
        // vendor later never rewrites paperwork already filed.
        $data['vendor_id'] = $vendor->id;
        $data['vendor_name'] = $vendor->company_name;
        $data['vendor_gstin'] = $vendor->gst_no;

        // No due date given? The vendor's own terms set one.
        if (empty($data['due_date']) && $vendor->payment_terms_days !== null) {
            $data['due_date'] = Carbon::parse($data['expense_date'])
                ->addDays($vendor->payment_terms_days)->toDateString();
        }

        // Each tax line: a rate turns into an amount off the base. Where no
        // rate is given the amount stands on its own, so a bill that rounds
        // its own way is still entered as the paper reads.
        $base = (float) $data['base_amount'];
        $taxes = 0.0;
        foreach (['cgst', 'sgst', 'igst', 'other_tax'] as $key) {
            $rateKey = $key . '_rate';
            $amountKey = $key . '_amount';
            $rate = $data[$rateKey] ?? null;

            if ($rate !== null && $rate !== '') {
                $data[$rateKey] = round((float) $rate, 3);
                $data[$amountKey] = round($base * (float) $rate / 100, 2);
            } else {
                $data[$rateKey] = null;
                $data[$amountKey] = round((float) ($data[$amountKey] ?? 0), 2);
            }
            $taxes += (float) $data[$amountKey];
        }

        // A charge with no name is just "Other tax" on the register.
        if ((float) $data['other_tax_amount'] > 0 && empty($data['other_tax_label'])) {
            $data['other_tax_label'] = 'Other tax';
        }

        // The total is arithmetic, never an input.
        $data['total_amount'] = round($base + $taxes, 2);

        return $data;
    }

    private function serialize(Expense $e): array
    {
        return [
            'uuid' => $e->uuid,
            'expense_date' => $e->expense_date->toDateString(),
            'due_date' => $e->due_date?->toDateString(),
            'issuing_company' => $e->issuingCompany?->name,
            'issuing_company_id' => $e->issuing_company_id,
            'vendor_uuid' => $e->vendor?->uuid,
            'vendor_name' => $e->vendor_name,
            'vendor_gstin' => $e->vendor_gstin,
            'category' => $e->category,
            'description' => $e->description,
            'base_amount' => $e->base_amount,
            'cgst_amount' => $e->cgst_amount,
            'sgst_amount' => $e->sgst_amount,
            'igst_amount' => $e->igst_amount,
            'cgst_rate' => $e->cgst_rate,
            'sgst_rate' => $e->sgst_rate,
            'igst_rate' => $e->igst_rate,
            'other_tax_label' => $e->other_tax_label,
            'other_tax_rate' => $e->other_tax_rate,
            'other_tax_amount' => $e->other_tax_amount,
            'total_amount' => $e->total_amount,
            'amount_paid' => $e->amount_paid,
            'balance' => $e->balance(),
            'payment_status' => $e->payment_status,
            'overdue' => $e->isOverdue(),
            'payments' => $e->payments->sortBy('paid_on')->values()
                ->map(fn (ExpensePayment $p) => [
                    'uuid' => $p->uuid,
                    'paid_on' => $p->paid_on->toDateString(),
                    'amount' => $p->amount,
                    'payment_mode' => $p->payment_mode,
                    'reference_no' => $p->reference_no,
                    'note' => $p->note,
                    'created_by' => $p->creator?->name,
                ]),
            'bill_available' => $e->bill_available,
            'gst_claimed' => $e->gst_claimed,
            'payment_mode' => $e->payment_mode,
            'note' => $e->note,
            'documents_count' => $e->documents_count ?? $e->documents()->count(),
            'documents' => $e->documents()->get()->map(fn ($d) => ['uuid' => $d->uuid, 'name' => $d->name, 'size' => $d->size]),
            'created_by' => $e->creator?->name,
            'created_at' => $e->created_at?->toDateTimeString(),
        ];
    }
}
