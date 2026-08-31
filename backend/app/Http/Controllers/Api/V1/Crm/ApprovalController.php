<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Approval;
use App\Models\Crm\ClientAccessRequest;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoiceUpdateRequest;
use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Models\Crm\Task;
use App\Notifications\CrmNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Approvals: the old CRM's money/error approval register, plus an inbox
 * strip counting everything else waiting on a decision (leaves, submitted
 * tasks, invoice update requests) so one screen answers "what needs me?".
 *
 * Invoice updates live here too: a final invoice never changes directly —
 * someone proposes a diff, someone else applies it.
 */
class ApprovalController extends Controller
{
    // ---- The register ------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Approval::with([
            'requester.user:id,name', 'decider.user:id,name',
            'invoice:id,uuid,number', 'client:id,uuid,company_name', 'issuingCompany:id,name',
        ])
            ->where('organization_id', $org->id);

        // Non-deciders see their own requests only.
        if (! (in_array($me->crm_role, ['admin', 'subadmin'], true) || $me->can('approvals', 'view'))) {
            $query->where('requested_by', $me->id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('approval_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('approval_date', '<=', $to);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('details', 'like', "%{$search}%")
                ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', "%{$search}%")));
        }

        $all = (clone $query)->get(['id', 'type', 'status', 'amount']);
        $summary = [
            'pending' => $all->where('status', 'pending')->count(),
            'pending_amount' => round($all->where('status', 'pending')->sum('amount'), 2),
            'by_type' => $all->groupBy('type')
                ->map(fn ($g, $type) => ['type' => $type, 'count' => $g->count(), 'amount' => round($g->sum('amount'), 2)])
                ->sortByDesc('count')->values(),
            'by_status' => collect(['pending', 'approved', 'rejected'])
                ->map(fn ($s) => ['status' => $s, 'count' => $all->where('status', $s)->count()])
                ->filter(fn ($s) => $s['count'] > 0)->values(),
        ];

        $approvals = $query->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderByDesc('id')->paginate(25);
        $approvals->getCollection()->transform(fn ($a) => $this->serialize($a));

        return response()->json(['summary' => $summary, 'inbox' => $this->inbox($request)] + $approvals->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'type' => ['required', 'string', 'max:64'],
            // What the request is about: a document, or the office's own money.
            'scope' => ['nullable', Rule::in(['invoice', 'general'])],
            'approval_date' => ['required', 'date'],
            'issuing_company_id' => ['nullable', Rule::exists('crm_issuing_companies', 'id')->where('organization_id', $org->id)],
            'invoice_uuid' => ['nullable', 'string'],
            'client_uuid' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['scope'] = $data['scope'] ?? 'general';

        // A document request names the document, or at least the client —
        // otherwise whoever decides it is guessing. Both are looked up inside
        // the asker's own window, so nobody can point at a stranger's sale.
        if (! empty($data['invoice_uuid'])) {
            $invoice = Invoice::where('organization_id', $org->id)
                ->visibleTo($me)
                ->where('uuid', $data['invoice_uuid'])->firstOrFail();
            $data['invoice_id'] = $invoice->id;
            $data['client_id'] = $data['client_id'] ?? $invoice->client_id;
            $data['scope'] = 'invoice';
        }
        if (! empty($data['client_uuid'])) {
            $data['client_id'] = $this->clientsInWindow($request)
                ->where('uuid', $data['client_uuid'])->firstOrFail()->id;
            $data['scope'] = 'invoice';
        }
        unset($data['invoice_uuid'], $data['client_uuid']);

        if ($data['scope'] === 'invoice' && empty($data['invoice_id']) && empty($data['client_id'])) {
            abort(422, 'An invoice-related approval needs the invoice, or at least the client it concerns.');
        }

        $approval = Approval::create($data + [
            'organization_id' => $org->id,
            'requested_by' => $me->id,
            'amount' => $data['amount'] ?? 0,
        ]);

        Notification::send(
            Member::deciders($org->id, 'approvals', $me->id),
            new CrmNotification(
                'crm_approval',
                ($me->user?->name ?? 'Someone') . ' requested approval: ' . $approval->type
                    . ((float) $approval->amount > 0 ? ' — ₹' . number_format((float) $approval->amount) : '') . '.',
                '/crm/approvals',
            ),
        );

        return response()->json(['message' => 'Approval requested.', 'data' => $this->serialize($approval->load(['requester.user:id,name', 'invoice:id,uuid,number', 'client:id,uuid,company_name']))], 201);
    }

    /**
     * What this member may point an approval at — their own documents and
     * their own clients, nobody else's. The form reads this, so the dropdown
     * never offers a sale they cannot see.
     */
    public function options(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $search = trim((string) $request->query('search'));

        return response()->json(['data' => [
            'invoices' => Invoice::with('client:id,company_name')
                ->where('organization_id', $org->id)
                ->visibleTo($me)
                ->where('status', '!=', 'cancelled')
                ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($c) => $c->where('company_name', 'like', "%{$search}%"))))
                ->latest('invoice_date')
                ->limit(30)
                ->get(['id', 'uuid', 'number', 'kind', 'total', 'client_id'])
                ->map(fn (Invoice $i) => [
                    'uuid' => $i->uuid,
                    'number' => $i->number,
                    'kind' => $i->kind,
                    'total' => $i->total,
                    'client' => $i->client?->company_name,
                ]),
            'clients' => $this->clientsInWindow($request)
                ->when($search, fn ($q) => $q->where('company_name', 'like', "%{$search}%"))
                ->orderBy('company_name')
                ->limit(30)
                ->get(['uuid', 'company_name'])
                ->map(fn ($c) => ['uuid' => $c->uuid, 'company_name' => $c->company_name]),
            'types' => $org->optionList('approval_types'),
        ]]);
    }

    /**
     * The clients an asker may point an approval at: their own portfolio,
     * shared ones included — the same window the Clients screen shows.
     */
    private function clientsInWindow(Request $request)
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        return \App\Models\Crm\Client::where('organization_id', $org->id)
            ->when(! in_array($me->crm_role, ['admin', 'subadmin'], true), function ($q) use ($me) {
                $team = $me->teamMemberIds();
                $q->where(fn ($w) => $w->whereIn('assigned_member_id', $team)
                    ->orWhereHas('sharedWith', fn ($s) => $s->whereIn('crm_members.id', $team)));
            });
    }

    public function decide(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $approval = Approval::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();
        if ($approval->status !== 'pending') {
            abort(422, 'This request was already decided.');
        }
        if ($approval->requested_by === $me->id) {
            abort(422, 'You cannot decide your own request.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $approval->update([
            'status' => $data['status'],
            'decided_by' => $me->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        if ($approval->requester?->user) {
            $approval->requester->user->notify(new CrmNotification(
                'crm_approval',
                'Your approval request (' . $approval->type . ') was ' . $data['status']
                    . (($data['note'] ?? null) ? ' — "' . $data['note'] . '"' : '') . '.',
                '/crm/approvals',
            ));
        }

        return response()->json(['message' => 'Request ' . $data['status'] . '.', 'data' => $this->serialize($approval->fresh()->load(['requester.user:id,name', 'decider.user:id,name']))]);
    }

    // ---- Invoice update requests -------------------------------------------

    public function requestInvoiceUpdate(Request $request, string $invoiceUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        // You can only propose a change to a document you can see.
        $invoice = Invoice::where('organization_id', $org->id)
            ->visibleTo($me)
            ->where('uuid', $invoiceUuid)->firstOrFail();

        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $changes = array_intersect_key($data['changes'], array_flip(InvoiceUpdateRequest::EDITABLE));
        if ($changes === []) {
            abort(422, 'None of the proposed fields can be changed this way.');
        }

        $pendingExists = InvoiceUpdateRequest::where('invoice_id', $invoice->id)->where('status', 'pending')->exists();
        if ($pendingExists) {
            abort(422, 'This invoice already has a pending update request.');
        }

        $req = InvoiceUpdateRequest::create([
            'organization_id' => $org->id,
            'invoice_id' => $invoice->id,
            'changes' => $changes,
            'reason' => $data['reason'] ?? null,
            'requested_by' => $me->id,
        ]);

        Notification::send(
            Member::deciders($org->id, 'invoices', $me->id),
            new CrmNotification(
                'crm_invoice_update',
                ($me->user?->name ?? 'Someone') . ' proposed changes to ' . $invoice->number
                    . ' (' . implode(', ', array_keys($changes)) . ').',
                '/crm/approvals',
            ),
        );

        return response()->json(['message' => 'Update requested for ' . $invoice->number . '.', 'data' => $this->serializeUpdate($req->load(['invoice:id,uuid,number', 'requester.user:id,name']))], 201);
    }

    public function invoiceUpdates(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = InvoiceUpdateRequest::with(['invoice:id,uuid,number', 'requester.user:id,name', 'decider.user:id,name'])
            ->where('organization_id', $org->id);

        if (! (in_array($me->crm_role, ['admin', 'subadmin'], true) || $me->can('invoices', 'edit'))) {
            $query->where('requested_by', $me->id);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $rows = $query->orderByRaw("case status when 'pending' then 0 else 1 end")->orderByDesc('id')->paginate(25);
        $rows->getCollection()->transform(fn ($r) => $this->serializeUpdate($r));

        return response()->json($rows);
    }

    public function decideInvoiceUpdate(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $req = InvoiceUpdateRequest::with('invoice')
            ->where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();
        if ($req->status !== 'pending') {
            abort(422, 'This request was already decided.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        if ($data['status'] === 'approved' && $req->invoice) {
            $before = $req->invoice->only(array_keys($req->changes));
            $req->invoice->update(array_intersect_key($req->changes, array_flip(InvoiceUpdateRequest::EDITABLE))
                + ['updated_by' => $request->user()->id]);
            ActivityLog::record($me, $org->id, 'invoice.update_applied', $req->invoice, [
                'number' => $req->invoice->number,
                'fields' => collect($req->changes)->map(fn ($to, $field) => [
                    'from' => $before[$field] instanceof \Carbon\CarbonInterface ? $before[$field]->toDateString() : $before[$field],
                    'to' => $to,
                ])->all(),
            ]);
        }

        $req->update([
            'status' => $data['status'],
            'decided_by' => $me->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        if ($req->requester?->user) {
            $req->requester->user->notify(new CrmNotification(
                'crm_invoice_update',
                'Your change request for ' . ($req->invoice?->number ?? 'the invoice') . ' was '
                    . ($data['status'] === 'approved' ? 'approved and applied' : 'rejected') . '.',
                $req->invoice ? '/crm/invoices/' . $req->invoice->uuid : '/crm/approvals',
            ));
        }

        return response()->json(['message' => 'Update ' . $data['status'] . ($data['status'] === 'approved' ? ' and applied.' : '.')]);
    }

    // ---- Helpers -----------------------------------------------------------

    /** What is waiting on the signed-in member, across modules. */
    private function inbox(Request $request): array
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $isManager = in_array($me->crm_role, ['admin', 'subadmin'], true);

        return [
            'leaves' => ($isManager || $me->can('leaves', 'edit'))
                ? Leave::where('organization_id', $org->id)->where('status', 'pending')->count()
                : null,
            'tasks' => ($isManager || $me->can('tasks', 'edit'))
                ? Task::where('organization_id', $org->id)->where('status', 'submitted')->count()
                : null,
            'invoice_updates' => ($isManager || $me->can('invoices', 'edit'))
                ? InvoiceUpdateRequest::where('organization_id', $org->id)->where('status', 'pending')->count()
                : null,
            'client_access' => ($isManager || $me->can('clients', 'edit'))
                ? ClientAccessRequest::where('organization_id', $org->id)->where('status', 'pending')->count()
                : null,
        ];
    }

    private function serialize(Approval $a): array
    {
        return [
            'uuid' => $a->uuid,
            'type' => $a->type,
            'scope' => $a->scope,
            'approval_date' => $a->approval_date->toDateString(),
            'issuing_company' => $a->issuingCompany?->name,
            'invoice' => $a->invoice ? ['uuid' => $a->invoice->uuid, 'number' => $a->invoice->number] : null,
            // Named even when no invoice was picked — "the Bhavya Steel deal"
            // is enough for a decider to know what they are approving.
            'client' => $a->client ? ['uuid' => $a->client->uuid, 'company_name' => $a->client->company_name] : null,
            'amount' => $a->amount,
            'details' => $a->details,
            'requested_by' => $a->requester?->user?->name,
            'status' => $a->status,
            'decided_by' => $a->decider?->user?->name,
            'decided_at' => $a->decided_at?->toDateTimeString(),
            'decision_note' => $a->decision_note,
            'created_at' => $a->created_at?->toDateTimeString(),
        ];
    }

    private function serializeUpdate(InvoiceUpdateRequest $r): array
    {
        return [
            'uuid' => $r->uuid,
            'invoice' => $r->invoice ? ['uuid' => $r->invoice->uuid, 'number' => $r->invoice->number] : null,
            'changes' => $r->changes,
            'reason' => $r->reason,
            'requested_by' => $r->requester?->user?->name,
            'status' => $r->status,
            'decided_by' => $r->decider?->user?->name,
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toDateTimeString(),
        ];
    }
}
