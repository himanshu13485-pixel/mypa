<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Expense;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Commission paid to a client out of a sale.
 *
 * The invoice is a tax document, so the commission never touches it: it is
 * recorded as an EXPENSE tied to the sale, and the office memory of it lives
 * in an internal note on the invoice — which the client never reads. This
 * screen is a lens over those expenses, nothing more; the books stay in one
 * place.
 */
class CommissionController extends Controller
{
    /** The expense category every commission is filed under. */
    public const CATEGORY = 'Client Commission';

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Expense::with(['invoice.client:id,uuid,company_name', 'invoice.member.user:id,name', 'creator:id,name'])
            ->where('organization_id', $org->id)
            ->where('category', self::CATEGORY)
            ->whereNotNull('invoice_id');

        // The commission follows its sale's window.
        if (! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            $query->whereHas('invoice', fn ($q) => $q->visibleTo($me));
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('vendor_name', 'like', "%{$search}%")
                ->orWhere('note', 'like', "%{$search}%")
                ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', "%{$search}%")));
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('expense_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('expense_date', '<=', $to);
        }

        $all = (clone $query)->get(['id', 'total_amount', 'expense_date']);
        $summary = [
            'count' => $all->count(),
            'total' => round($all->sum('total_amount'), 2),
            'this_month' => round($all->filter(fn ($e) => $e->expense_date->isSameMonth(now()))->sum('total_amount'), 2),
        ];

        $rows = $query->orderByDesc('expense_date')->orderByDesc('id')->paginate(25);
        $rows->getCollection()->transform(fn (Expense $e) => $this->serialize($e));

        return response()->json(['summary' => $summary] + $rows->toArray());
    }

    /**
     * Record one: the expense is created, and the invoice quietly remembers
     * it in an internal note — never on its face.
     */
    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'invoice_uuid' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payee' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $invoice = Invoice::with('client')
            ->where('organization_id', $org->id)
            ->visibleTo($me)
            ->where('uuid', $data['invoice_uuid'])
            ->firstOrFail();

        $payee = $data['payee'] ?? $invoice->client?->company_name ?? 'Client';
        $amount = round((float) $data['amount'], 2);

        $expense = Expense::create([
            'organization_id' => $org->id,
            'expense_date' => $data['expense_date'] ?? now()->toDateString(),
            'issuing_company_id' => $invoice->issuing_company_id,
            'invoice_id' => $invoice->id,
            'vendor_name' => $payee,
            'category' => self::CATEGORY,
            'description' => 'Commission against ' . $invoice->number,
            'base_amount' => $amount,
            'total_amount' => $amount,
            'payment_mode' => $data['payment_mode'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // The office memory, where the client never reads.
        $invoice->internalNotes()->create([
            'organization_id' => $org->id,
            'member_id' => $me->id,
            'body' => 'Commission of ' . ($invoice->currency ?: 'INR') . ' ' . number_format($amount, 2)
                . ' to ' . $payee . ' recorded as an expense.'
                . (($data['note'] ?? null) ? ' ' . $data['note'] : ''),
        ]);

        ActivityLog::record($me, $org->id, 'commission.recorded', $invoice, array_filter([
            'number' => $invoice->number,
            'client' => $invoice->client?->company_name,
            'payee' => $payee,
            'amount' => $amount,
            'by' => $me->user?->name,
        ]));

        return response()->json([
            'message' => 'Commission recorded against ' . $invoice->number . ' — the invoice itself is untouched.',
            'data' => $this->serialize($expense->load(['invoice.client:id,uuid,company_name', 'invoice.member.user:id,name', 'creator:id,name'])),
        ], 201);
    }

    /** Undo a wrongly recorded one — the Admin's, like every correction. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        if (! $me->allows('commissions.remove')) {
            abort(403, 'Removing a commission is the Company Admin’s, or an employee they grant it to.');
        }

        $expense = Expense::with('invoice')
            ->where('organization_id', $org->id)
            ->where('category', self::CATEGORY)
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($expense->invoice) {
            ActivityLog::record($me, $org->id, 'commission.removed', $expense->invoice, [
                'number' => $expense->invoice->number,
                'payee' => $expense->vendor_name,
                'amount' => (float) $expense->total_amount,
                'by' => $me->user?->name,
            ]);
        }

        $expense->delete();

        return response()->json(['message' => 'Commission removed.']);
    }

    // ---- Helpers -----------------------------------------------------------

    private function serialize(Expense $e): array
    {
        return [
            'uuid' => $e->uuid,
            'expense_date' => $e->expense_date->toDateString(),
            'payee' => $e->vendor_name,
            'amount' => (float) $e->total_amount,
            'payment_mode' => $e->payment_mode,
            'note' => $e->note,
            'invoice' => $e->invoice ? [
                'uuid' => $e->invoice->uuid,
                'number' => $e->invoice->number,
                'total' => $e->invoice->total,
            ] : null,
            'client' => $e->invoice?->client?->company_name,
            'salesperson' => $e->invoice?->member?->user?->name,
            'recorded_by' => $e->creator?->name,
            'created_at' => $e->created_at?->toDateTimeString(),
        ];
    }
}
