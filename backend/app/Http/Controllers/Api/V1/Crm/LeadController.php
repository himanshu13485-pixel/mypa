<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadAccessRequest;
use App\Models\Crm\Member;
use App\Notifications\CrmNotification;
use App\Support\TextCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Lead Generation, rebuilt from the old CRM: numbered leads allocated to a
 * salesperson, with status, follow-up date, subject, source and amount. Every
 * change lands in the shared activity trail; the Lead Log screen is a
 * filtered view over that trail, so nothing can happen to a lead unrecorded.
 *
 * Visibility follows the old CRM's rule: admins and subadmins see the whole
 * pipeline, an employee sees the leads allocated to them or created by them.
 */
class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request)
            ->with(['assignedMember.user:id,name', 'sharedWith.user:id,name', 'creator:id,name', 'client:id,uuid,company_name']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($no = $request->query('lead_no')) {
            $query->where('lead_no', (int) $no);
        }
        if ($status = $request->query('lead_status')) {
            $query->where('lead_status', $status);
        }
        if ($type = $request->query('lead_type')) {
            $query->where('lead_type', $type);
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($subject = $request->query('subject')) {
            $query->where('subject', $subject);
        }
        if ($assigned = $request->query('assigned_to')) {
            $query->whereHas('assignedMember', fn ($m) => $m->where('uuid', $assigned));
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($fuFrom = $request->query('follow_up_from')) {
            $query->whereDate('follow_up_at', '>=', $fuFrom);
        }
        if ($fuTo = $request->query('follow_up_to')) {
            $query->whereDate('follow_up_at', '<=', $fuTo);
        }
        // "due=1": follow-ups that are overdue or due today — the work list.
        if ($request->boolean('due')) {
            $query->where('lead_status', 'follow_up')->where('follow_up_at', '<=', now()->endOfDay());
        }

        $totals = [
            'count' => (clone $query)->count(),
            'amount' => (clone $query)->sum('amount'),
        ];

        // Which leads are Lead Duplication: sharing a mobile, phone or
        // e-mail with another lead (rows from before the guard existed), or
        // sitting under a pending duplication request. The list wears it as
        // the status, so a pair like this cannot hide behind "Unattended".
        $org = $request->attributes->get('crm_org');
        $byKey = [];
        $numbers = [];
        foreach (Lead::orderBy('id')->where('organization_id', $org->id)
            ->get(['id', 'lead_no', 'mobile', 'phone', 'email']) as $row) {
            $numbers[$row->id] = $row->lead_no;
            foreach (Lead::contactKeys($row->mobile, $row->phone, $row->email) as $key) {
                $byKey[$key][] = $row->id;
            }
        }
        // The FIRST lead with a contact is the original — it stays clean.
        // Only the later arrivals wear Duplicate, each pointing back at it.
        $duplicateOf = [];
        foreach ($byKey as $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 2) {
                continue;
            }
            $original = min($ids);
            foreach ($ids as $id) {
                if ($id !== $original) {
                    $duplicateOf[$id] = min($duplicateOf[$id] ?? PHP_INT_MAX, $original);
                }
            }
        }
        $pendingIds = LeadAccessRequest::where('organization_id', $org->id)
            ->where('status', 'pending')
            ->pluck('lead_id')->flip();

        // Urgent leads ride above every scheduled one.
        $leads = $query->orderByDesc('is_urgent')->orderByDesc('lead_no')->paginate(25);
        // A settled duplicate stops wearing the badge — the Admin sorted it.
        $leads->getCollection()->transform(fn ($l) => $this->serialize($l) + [
            'is_duplicate' => isset($duplicateOf[$l->id]) && $l->duplicate_settled_at === null,
            'duplicate_of' => isset($duplicateOf[$l->id]) && $l->duplicate_settled_at === null
                ? ($numbers[$duplicateOf[$l->id]] ?? null) : null,
            'has_pending_request' => isset($pendingIds[$l->id]),
        ]);

        return response()->json(['totals' => $totals] + $leads->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $data = $this->validateLead($request, $org->id);

        // One person, one lead: a matching mobile, phone or e-mail means
        // this lead already exists — Lead Duplication, never a second row.
        if ($existing = $this->findDuplicate($org->id, $data)) {
            return $this->duplicateResponse($existing, $me, $org->id);
        }

        // Whoever adds a lead owns it. An employee cannot allocate to
        // somebody else, so the field is theirs rather than empty — the same
        // rule the client book follows.
        if (! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            $data['assigned_member_id'] = $me->id;
        }

        $lead = DB::transaction(fn () => Lead::create($data + [
            'organization_id' => $org->id,
            'lead_no' => Lead::nextNumber($org->id),
            'created_by' => $request->user()->id,
        ]));

        // The birth entry carries the full opening state, like the old log did.
        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'lead.created', $lead, [
            'lead_no' => $lead->lead_no,
            'fields' => collect($data)->filter(fn ($v) => $v !== null && $v !== '')->all(),
        ]);

        return response()->json([
            'message' => 'Lead #' . $lead->lead_no . ' created.',
            'data' => $this->serialize($lead->load(['assignedMember.user:id,name', 'creator:id,name'])),
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $lead = $this->find($request, $uuid)
            ->load(['assignedMember.user:id,name', 'sharedWith.user:id,name', 'creator:id,name', 'client:id,uuid,company_name', 'logs.member.user:id,name']);

        // A duplicate stays sealed to employees until the Admin/Subadmin
        // sorts it — settle, share, transfer or delete decides its fate.
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        if (! in_array($me->crm_role, ['admin', 'subadmin'], true)
            && $lead->duplicate_settled_at === null
            && $this->duplicateOriginalOf($lead) !== null) {
            abort(403, 'This lead is a duplicate awaiting settlement by the Admin — it opens once sorted.');
        }

        return response()->json(['data' => $this->serialize($lead, full: true)]);
    }

    /**
     * The earlier lead this one duplicates, if any — the same contact-key
     * rule the list badge uses, checked for one row.
     */
    /**
     * The new-lead nag: leads that ARRIVED at this person's desk and are
     * still unattended. The first popup fires the moment one lands; the
     * repeat interval is the Admin's own knob (15 minutes by default).
     * Attending it - any follow-up or status change - clears it, and the
     * ordinary follow-up alerts take over from there.
     */
    public function fresh(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $leads = Lead::with('creator:id,name')
            ->where('organization_id', $org->id)
            ->where('lead_status', 'unattended')
            ->where('assigned_member_id', $me->id)
            ->orderByDesc('is_urgent')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Lead $lead) => [
                'uuid' => $lead->uuid,
                'lead_no' => $lead->lead_no,
                'company_name' => $lead->company_name,
                'contact_person' => $lead->contact_person,
                'mobile' => $lead->mobile,
                'is_urgent' => (bool) $lead->is_urgent,
                'created_by' => $lead->creator?->name,
                'arrived_at' => $lead->created_at?->toDateTimeString(),
                'waiting_minutes' => (int) $lead->created_at?->diffInMinutes(now()),
            ]);

        return response()->json([
            'data' => $leads,
            'alert_minutes' => $org->newLeadAlertMinutes(),
        ]);
    }

    private function duplicateOriginalOf(Lead $lead): ?Lead
    {
        $keys = Lead::contactKeys($lead->mobile, $lead->phone, $lead->email);
        if ($keys === []) {
            return null;
        }

        foreach (Lead::where('organization_id', $lead->organization_id)
            ->where('id', '<', $lead->id)->orderBy('id')
            ->get(['id', 'lead_no', 'mobile', 'phone', 'email']) as $row) {
            if (array_intersect($keys, Lead::contactKeys($row->mobile, $row->phone, $row->email)) !== []) {
                return $row;
            }
        }

        return null;
    }

    /** The Admin's gavel: mark a duplicate sorted so it opens again. */
    public function settleDuplicate(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'Settling a duplicate is for the Admin or a Subadmin.');

        $lead = $this->find($request, $uuid);
        $lead->update(['duplicate_settled_at' => now(), 'updated_by' => $request->user()->id]);

        ActivityLog::record($me, $lead->organization_id, 'lead.duplicate_settled', $lead, [
            'lead_no' => $lead->lead_no,
        ]);

        return response()->json(['message' => 'Duplicate settled — the lead opens normally now.']);
    }

    /** Urgency: an urgent lead rides above every scheduled one. */
    public function setUrgent(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['urgent' => ['required', 'boolean']]);
        $lead = $this->find($request, $uuid);
        $lead->update(['is_urgent' => $data['urgent'], 'updated_by' => $request->user()->id]);

        ActivityLog::record($request->attributes->get('crm_member'), $lead->organization_id,
            $data['urgent'] ? 'lead.marked_urgent' : 'lead.urgency_cleared', $lead, [
                'lead_no' => $lead->lead_no,
            ]);

        return response()->json(['message' => $data['urgent']
            ? 'Marked URGENT - it now rides above every scheduled lead.'
            : 'Urgency cleared.']);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $lead = $this->find($request, $uuid);
        $data = $this->validateLead($request, $org->id);

        // Changing the contacts onto another lead's would merge two people.
        if ($existing = $this->findDuplicate($org->id, $data, $lead->id)) {
            abort(422, 'Lead Duplication: those contact details already belong to lead #'
                . $existing->lead_no . ' (' . $existing->company_name . ').');
        }

        // The contacts ARE the lead's identity, so changing them is the
        // Admin's: an employee asks, the Admin edits.
        /** @var Member $editor */
        $editor = $request->attributes->get('crm_member');
        if (! $editor->allows('leads.edit_contacts')) {
            foreach (['mobile', 'phone', 'email'] as $field) {
                $was = (string) ($lead->{$field} ?? '');
                $now = (string) ($data[$field] ?? '');
                if (array_key_exists($field, $data) && $was !== $now) {
                    abort(422, 'Changing the ' . str_replace('_', ' ', $field)
                        . ' needs your Company Admin or Subadmin — ask them to update it.');
                }
            }
        }

        // Log only what actually changed, old value → new value.
        $before = $lead->only(array_keys($data));
        $lead->update($data + ['updated_by' => $request->user()->id]);
        $after = $lead->fresh()->only(array_keys($data));

        $changes = [];
        foreach ($after as $key => $value) {
            $was = $before[$key] instanceof \Carbon\CarbonInterface ? $before[$key]->toDateTimeString() : $before[$key];
            $now = $value instanceof \Carbon\CarbonInterface ? $value->toDateTimeString() : $value;
            if ((string) $was !== (string) $now) {
                $changes[$key] = ['from' => $was, 'to' => $now];
            }
        }
        if ($changes !== []) {
            ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'lead.updated', $lead, [
                'lead_no' => $lead->lead_no, 'fields' => $changes,
            ]);
        }

        return response()->json([
            'message' => 'Lead updated.',
            'data' => $this->serialize($lead->load(['assignedMember.user:id,name', 'creator:id,name'])),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        // Deleting a lead is the Admin's or a Subadmin's — never an
        // employee's or a Team Workspace leader's, whatever rights they hold.
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'Deleting a lead is for the Admin or a Subadmin.');

        $lead = $this->find($request, $uuid);

        ActivityLog::record($request->attributes->get('crm_member'), $lead->organization_id, 'lead.deleted', $lead, [
            'lead_no' => $lead->lead_no, 'company_name' => $lead->company_name,
        ]);
        $lead->delete();

        return response()->json(['message' => 'Lead deleted.']);
    }

    /**
     * The daily telecaller action: a follow-up note, optionally moving the
     * status and booking the next call. One endpoint so the trail always
     * shows note + status + next date as a single event.
     */
    /**
     * Hand many leads to one person at once — the reshuffle a Team Head or an
     * Admin does when someone leaves, joins, or a territory changes. Each
     * lead is moved through the same door as a single transfer, so the trail
     * reads the same whether one moved or forty.
     */
    public function bulkTransfer(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        // A Team Head reshuffles their own desk; an Admin the whole floor.
        // A lone employee moves nothing, whatever rights they hold.
        if (! $me->allows('leads.bulk_transfer') && ! $me->leadsATeam()) {
            abort(403, 'Bulk transfer belongs to a Team Head or the Company Admin.');
        }

        $data = $request->validate([
            'lead_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'lead_uuids.*' => ['string'],
            'to_member_uuid' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $to = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('uuid', $data['to_member_uuid'])
            ->firstOrFail();

        $this->assertCanReceive($me, $to);

        // Only leads this member can already see — the window is the limit.
        $leads = $this->scoped($request)
            ->with('assignedMember.user:id,name')
            ->whereIn('uuid', $data['lead_uuids'])
            ->get();

        $moved = 0;
        foreach ($leads as $lead) {
            if ($lead->assigned_member_id === $to->id) {
                continue;
            }
            $this->moveLead($lead, $to, $me, $data['note'] ?? null, notify: false);
            $moved++;
        }

        if ($moved > 0 && $to->user) {
            // One notice for the batch, not forty.
            $to->user->notify(new CrmNotification(
                'crm_lead_access',
                $moved . ' lead' . ($moved === 1 ? '' : 's') . ' transferred to you by '
                    . ($me->user?->name ?? 'your admin') . '.',
                '/crm/leads',
            ));
        }

        ActivityLog::record($me, $org->id, 'lead.bulk_transferred', $to, array_filter([
            'count' => $moved,
            'to' => $to->user?->name,
            'note' => $data['note'] ?? null,
            'by' => $me->user?->name,
        ]));

        return response()->json([
            'message' => $moved === 0
                ? 'Nothing to move — those leads are already there.'
                : $moved . ' lead' . ($moved === 1 ? '' : 's') . ' transferred to ' . ($to->user?->name ?? 'them') . '.',
            'moved' => $moved,
        ]);
    }

    /**
     * Let a colleague in on many leads at once — the other half of the
     * reshuffle. Sharing leaves ownership where it is, so the desk that
     * built the relationship keeps it; the second pair of hands simply sees
     * the same leads.
     */
    public function bulkShare(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        if (! $me->allows('leads.share') && ! $me->leadsATeam()) {
            abort(403, 'Bulk sharing belongs to a Team Head or the Company Admin.');
        }

        $data = $request->validate([
            'lead_uuids' => ['required', 'array', 'min:1', 'max:500'],
            'lead_uuids.*' => ['string'],
            'member_uuids' => ['required', 'array', 'min:1'],
            'member_uuids.*' => ['string'],
        ]);

        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->whereIn('uuid', $data['member_uuids'])
            ->get();

        if ($members->isEmpty()) {
            abort(422, 'Nobody to share with.');
        }
        foreach ($members as $member) {
            // The same boundary a transfer obeys: your own team, and never
            // upward to the people who run the company.
            $this->assertCanReceive($me, $member);
        }

        // Only leads this member can already see — the window is the limit.
        $leads = $this->scoped($request)->whereIn('uuid', $data['lead_uuids'])->get();
        $names = $members->map(fn (Member $m) => $m->user?->name)->filter()->values();
        $shared = 0;

        foreach ($leads as $lead) {
            // Sharing a lead with the person who already owns it is a no-op
            // row, so those are quietly skipped.
            $targets = $members->reject(fn (Member $m) => $m->id === $lead->assigned_member_id);
            if ($targets->isEmpty()) {
                continue;
            }

            $lead->sharedWith()->syncWithoutDetaching(
                $targets->mapWithKeys(fn (Member $m) => [$m->id => ['shared_by' => $me->id]])->all(),
            );
            ActivityLog::record($me, $org->id, 'lead.shared', $lead, [
                'lead_no' => $lead->lead_no,
                'shared_with' => $targets->map(fn (Member $m) => $m->user?->name)->filter()->implode(', '),
            ]);
            $shared++;
        }

        // One notice for the batch, not forty.
        foreach ($members as $member) {
            if ($shared > 0 && $member->user) {
                $member->user->notify(new CrmNotification(
                    'crm_lead_access',
                    $shared . ' lead' . ($shared === 1 ? '' : 's') . ' shared with you by '
                        . ($me->user?->name ?? 'your admin') . '.',
                    '/crm/leads',
                ));
            }
        }

        return response()->json([
            'message' => $shared === 0
                ? 'Nothing to share — those leads are already theirs.'
                : $shared . ' lead' . ($shared === 1 ? '' : 's') . ' shared with ' . $names->implode(', ') . '.',
            'shared' => $shared,
        ]);
    }

    /**
     * A closed lead whose person came back.
     *
     * Nothing is erased: the old discussion stays in the trail, the reopening
     * is stamped on top with what was said when it closed, and the count of
     * returns rides on the lead. If they became a client once, that client is
     * marked a repeat — the office should know before the call.
     */
    public function reopen(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $lead = $this->find($request, $uuid);

        // Their own returning client is the owner's to pick up; anyone else
        // needs the grant.
        // Theirs if it is allocated to them, or if they raised it.
        $isOwner = $lead->assigned_member_id === $me->id || $lead->created_by === $me->user_id;
        if (! $isOwner && ! $me->allows('leads.reopen')) {
            abort(403, 'Reopening someone else’s closed lead needs the Company Admin, or the grant to do it.');
        }
        if (! in_array($lead->lead_status, ['closed', 'not_interested'], true)) {
            abort(422, 'Lead #' . $lead->lead_no . ' is still open.');
        }

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        // What was said when it closed — carried forward so the person who
        // picks up the phone reads the ending before the beginning.
        $closing = ActivityLog::where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->whereIn('action', ['lead.followup', 'lead.updated'])
            ->orderByDesc('id')
            ->get()
            ->first(fn ($log) => filled(data_get($log->changes, 'note')));

        $lead->update([
            'lead_status' => 'follow_up',
            'follow_up_at' => $data['follow_up_at'] ?? now()->addDay(),
            'reopen_count' => $lead->reopen_count + 1,
            'updated_by' => $request->user()->id,
        ]);

        ActivityLog::record($me, $org->id, 'lead.reopened', $lead, array_filter([
            'lead_no' => $lead->lead_no,
            'company_name' => $lead->company_name,
            'note' => $data['note'],
            'previous_closing' => data_get($closing?->changes, 'note'),
            'closed_on' => $lead->closed_at?->toDateTimeString(),
            'times_reopened' => $lead->reopen_count,
            'next_follow_up' => $lead->follow_up_at?->toDateTimeString(),
        ]));

        // The client they became, if any, is now on record as a returner.
        $client = $lead->client ?? Client::where('organization_id', $org->id)
            ->get(['id', 'company_name', 'email', 'mobile', 'telephone'])
            ->first(fn (Client $c) => array_intersect(
                Lead::contactKeys($lead->mobile, $lead->phone, $lead->email),
                Lead::contactKeys($c->mobile, $c->telephone, $c->email),
            ) !== []);

        if ($client) {
            $client = Client::find($client->id);
            $client->update([
                'is_repeat' => true,
                'repeat_count' => $client->repeat_count + 1,
            ]);
            ActivityLog::record($me, $org->id, 'client.repeat', $client, [
                'company_name' => $client->company_name,
                'lead_no' => $lead->lead_no,
                'times' => $client->repeat_count,
            ]);
        }

        return response()->json([
            'message' => 'Lead #' . $lead->lead_no . ' reopened'
                . ($client ? ' — ' . $client->company_name . ' is marked a repeat client.' : '.'),
            'data' => $this->serialize($lead->fresh()->load(['assignedMember.user:id,name', 'sharedWith.user:id,name', 'creator:id,name'])),
        ]);
    }

    public function followUp(Request $request, string $uuid): JsonResponse
    {
        $lead = $this->find($request, $uuid);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'lead_status' => ['nullable', Rule::in(Lead::STATUSES)],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $updates = array_filter([
            'lead_status' => $data['lead_status'] ?? null,
            'follow_up_at' => $data['follow_up_at'] ?? null,
        ]);
        // Recording a follow-up IS attending: an unattended lead moves to
        // Follow Up on its first one, which also stops the new-lead nag.
        if (! isset($updates['lead_status']) && $lead->lead_status === 'unattended') {
            $updates['lead_status'] = 'follow_up';
        }
        // Remember when it shut, so a return can say how long it was quiet.
        if (in_array($data['lead_status'] ?? '', ['closed', 'not_interested'], true)) {
            $updates['closed_at'] = now();
        }
        if ($updates !== []) {
            $lead->update($updates + ['updated_by' => $request->user()->id]);
        }

        ActivityLog::record($request->attributes->get('crm_member'), $lead->organization_id, 'lead.followup', $lead, array_filter([
            'lead_no' => $lead->lead_no,
            'note' => $data['note'],
            'status' => $data['lead_status'] ?? null,
            'next_follow_up' => $data['follow_up_at'] ?? null,
        ]));

        return response()->json([
            'message' => 'Follow-up recorded.',
            'data' => $this->serialize($lead->fresh()->load(['assignedMember.user:id,name', 'creator:id,name'])),
        ], 201);
    }

    /** A won lead becomes a client (or links to one already known). */
    public function convert(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $request->attributes->get('crm_member');
        $lead = $this->find($request, $uuid);

        if ($lead->client_id) {
            abort(422, 'This lead is already linked to a client.');
        }
        if (! $member->can('clients', 'create')) {
            abort(403, 'You need client-create rights to convert a lead.');
        }

        $client = Client::create([
            'organization_id' => $org->id,
            'company_name' => $lead->company_name,
            'contact_person' => $lead->contact_person,
            'telephone' => $lead->phone,
            'mobile' => $lead->mobile,
            'email' => $lead->email,
            'category' => $lead->lead_type === 'existing' ? 'existing' : 'new',
            'assigned_member_id' => $lead->assigned_member_id,
            'notes' => $lead->requirement,
            'created_by' => $request->user()->id,
        ]);

        $lead->update([
            'client_id' => $client->id,
            'lead_status' => 'closed',
            'closed_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        ActivityLog::record($member, $org->id, 'lead.converted', $lead, [
            'lead_no' => $lead->lead_no, 'client' => $client->company_name,
        ]);

        return response()->json([
            'message' => 'Lead #' . $lead->lead_no . ' converted to client.',
            'data' => ['client_uuid' => $client->uuid],
        ], 201);
    }

    /**
     * The Lead Log: the org-wide trail of everything that happened to every
     * lead, filterable like the old screen (date range, user, lead id, text).
     */
    public function log(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $request->attributes->get('crm_member');

        $query = ActivityLog::with(['member.user:id,name'])
            ->where('organization_id', $org->id)
            ->where('subject_type', Lead::class)
            ->where('action', 'like', 'lead.%');

        // Employees see the trail of their own leads only.
        if (! in_array($member->crm_role, ['admin', 'subadmin'], true)) {
            $visible = $this->scoped($request)->select('id');
            $query->whereIn('subject_id', $visible);
        }

        if ($no = $request->query('lead_no')) {
            $query->where('changes->lead_no', (int) $no);
        }
        if ($by = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $by));
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($text = trim((string) $request->query('search'))) {
            $query->where('changes', 'like', "%{$text}%");
        }

        $logs = $query->latest()->latest('id')->paginate(50);

        // One lookup so each entry can deep-link to its (still existing) lead.
        $uuids = Lead::whereIn('id', $logs->getCollection()->pluck('subject_id')->unique())
            ->pluck('uuid', 'id');
        $logs->getCollection()->transform(fn ($log) => $this->serializeLog($log) + [
            'lead_uuid' => $uuids[$log->subject_id] ?? null,
        ]);

        return response()->json($logs);
    }

    /**
     * The leads whose follow-up moment has arrived — what the popup nags
     * about. A lead leaves this list the moment it is attended: a new
     * follow-up date, or a status that is not "follow up" any more.
     */
    public function due(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $leads = $this->scoped($request)
            ->with('assignedMember.user:id,name')
            ->where('lead_status', 'follow_up')
            ->whereNotNull('follow_up_at')
            ->where('follow_up_at', '<=', now())
            // Urgent leads ride above every scheduled one, here too.
            ->orderByDesc('is_urgent')
            ->orderBy('follow_up_at')
            ->limit(50)
            ->get()
            ->map(fn (Lead $lead) => [
                'uuid' => $lead->uuid,
                'lead_no' => $lead->lead_no,
                'company_name' => $lead->company_name,
                'contact_person' => $lead->contact_person,
                'mobile' => $lead->mobile,
                'is_urgent' => (bool) $lead->is_urgent,
                'follow_up_at' => $lead->follow_up_at->toDateTimeString(),
                'overdue_minutes' => (int) $lead->follow_up_at->diffInMinutes(now()),
                'assigned_to' => $lead->assignedMember?->user?->name,
            ]);

        return response()->json([
            'data' => $leads,
            // The Admin's knob: how long the popup stays quiet when dismissed.
            'alert_minutes' => $org->leadAlertMinutes(),
        ]);
    }

    // ---- Lead Duplication --------------------------------------------------

    /**
     * The person behind a lead has one mobile, one phone, one e-mail — any of
     * the three matching an existing lead means it IS that lead. Courting the
     * same person from two desks embarrasses everyone, so a duplicate is
     * never a second row.
     */
    private function findDuplicate(int $orgId, array $data, ?int $ignoreId = null): ?Lead
    {
        $keys = Lead::contactKeys($data['mobile'] ?? null, $data['phone'] ?? null, $data['email'] ?? null);
        if ($keys === []) {
            return null;
        }

        return Lead::where('organization_id', $orgId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with('assignedMember.user:id,name')
            ->get(['id', 'uuid', 'lead_no', 'company_name', 'mobile', 'phone', 'email', 'assigned_member_id'])
            ->first(fn (Lead $lead) => array_intersect(
                $keys,
                Lead::contactKeys($lead->mobile, $lead->phone, $lead->email),
            ) !== []);
    }

    /**
     * Lead Duplication found. A manager is shown the existing lead with the
     * three ways forward — transfer, share, or leave it be; anyone else has
     * a request raised for the Admin to decide the same three ways.
     */
    private function duplicateResponse(Lead $existing, Member $me, int $orgId): JsonResponse
    {
        $owner = $existing->assignedMember?->user?->name;
        $summary = [
            'uuid' => $existing->uuid,
            'lead_no' => $existing->lead_no,
            'company_name' => $existing->company_name,
            'owner' => $owner,
        ];

        if (in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            return response()->json([
                'message' => 'Lead Duplication: lead #' . $existing->lead_no
                    . ($owner ? ' is already with ' . $owner : ' already exists')
                    . ' — the mobile, phone or e-mail matches. Open it to transfer, share or update it.',
                'duplicate' => $summary,
                'can_decide' => true,
            ], 422);
        }

        $alreadyMine = $existing->assigned_member_id === $me->id
            || $existing->sharedWith()->where('crm_members.id', $me->id)->exists();
        if ($alreadyMine) {
            return response()->json([
                'message' => 'Lead #' . $existing->lead_no . ' is already in your list — same mobile, phone or e-mail.',
                'duplicate' => $summary,
            ], 422);
        }

        $pending = LeadAccessRequest::firstOrCreate(
            ['lead_id' => $existing->id, 'member_id' => $me->id, 'status' => 'pending'],
            ['organization_id' => $orgId, 'note' => 'Tried to add this lead'],
        );

        if ($pending->wasRecentlyCreated) {
            Notification::send(
                Member::deciders($orgId, 'leads', $me->id),
                new CrmNotification(
                    'crm_lead_access',
                    ($me->user?->name ?? 'Someone') . ' tried to add lead #' . $existing->lead_no
                        . ($owner ? ' (with ' . $owner . ')' : '') . ' — same contact details. Share, transfer or reject.',
                    '/crm/leads?tab=requests',
                ),
            );
            ActivityLog::record($me, $orgId, 'lead.duplicate_requested', $existing, [
                'lead_no' => $existing->lead_no,
                'company_name' => $existing->company_name,
                'by' => $me->user?->name,
            ]);
        }

        return response()->json([
            'message' => 'Lead Duplication: this person is already lead #' . $existing->lead_no . '. '
                . ($pending->wasRecentlyCreated
                    ? 'Your Admin has been asked whether to share it with you, transfer it, or not.'
                    : 'Your earlier request is still with the Admin.'),
            'duplicate' => ['lead_no' => $existing->lead_no],
            'request_pending' => true,
        ], 422);
    }

    /** The duplication requests — pending first, own-only for non-deciders. */
    public function accessRequests(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = LeadAccessRequest::with([
            'lead:id,uuid,lead_no,company_name,mobile,phone,email,assigned_member_id',
            'lead.assignedMember.user:id,name',
            'member.user:id,name', 'decider.user:id,name',
        ])->where('organization_id', $org->id);

        if (! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            $query->where('member_id', $me->id);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $rows = $query->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(25);

        $rows->getCollection()->transform(fn (LeadAccessRequest $r) => [
            'uuid' => $r->uuid,
            'lead' => $r->lead ? [
                'uuid' => $r->lead->uuid,
                'lead_no' => $r->lead->lead_no,
                'company_name' => $r->lead->company_name,
                'mobile' => $r->lead->mobile,
                'email' => $r->lead->email,
            ] : null,
            'owner' => $r->lead?->assignedMember?->user?->name,
            'requested_by' => $r->member?->user?->name,
            'status' => $r->status,
            'decided_by' => $r->decider?->user?->name,
            'decided_at' => $r->decided_at?->toDateTimeString(),
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toDateTimeString(),
        ]);

        return response()->json($rows);
    }

    /**
     * The Admin's three ways with a duplication request: share the lead,
     * transfer it to the requester, or reject the ask.
     */
    public function decideAccessRequest(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        if (! $me->allows('leads.share')) {
            abort(403, 'Deciding a Lead Duplication is the Company Admin’s, or an employee they grant it to.');
        }

        $accessRequest = LeadAccessRequest::with(['lead.assignedMember.user', 'member.user'])
            ->where('organization_id', $org->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($accessRequest->status !== 'pending') {
            abort(422, 'This request was already decided.');
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['share', 'transfer', 'reject'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $lead = $accessRequest->lead;
        if (! $lead && $data['action'] !== 'reject') {
            abort(422, 'The lead this request is about is gone.');
        }

        if ($data['action'] === 'share') {
            $lead->sharedWith()->syncWithoutDetaching([
                $accessRequest->member_id => ['shared_by' => $me->id],
            ]);
            ActivityLog::record($me, $org->id, 'lead.shared', $lead, [
                'lead_no' => $lead->lead_no,
                'shared_with' => $accessRequest->member?->user?->name,
            ]);
        }
        if ($data['action'] === 'transfer') {
            $this->assertCanReceive($me, $accessRequest->member);
            $this->moveLead($lead, $accessRequest->member, $me, $data['note'] ?? null);
        }

        $accessRequest->update([
            'status' => match ($data['action']) {
                'share' => 'shared', 'transfer' => 'transferred', 'reject' => 'rejected',
            },
            'decided_by' => $me->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        if ($data['action'] === 'reject' && $lead) {
            ActivityLog::record($me, $org->id, 'lead.duplicate_rejected', $lead, array_filter([
                'lead_no' => $lead->lead_no,
                'requested_by' => $accessRequest->member?->user?->name,
                'note' => $data['note'] ?? null,
            ]));
        }

        if ($accessRequest->member?->user) {
            $accessRequest->member->user->notify(new CrmNotification(
                'crm_lead_access',
                'Lead #' . ($lead?->lead_no ?? '?') . ': your request was '
                    . $accessRequest->status . (($data['note'] ?? null) ? ' — "' . $data['note'] . '"' : '') . '.',
                '/crm/leads',
            ));
        }

        return response()->json(['message' => 'Request ' . $accessRequest->status . '.']);
    }

    /** Hand a lead to somebody else — the Admin's, like every reallocation. */
    public function transfer(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        if (! $me->allows('leads.transfer')) {
            abort(403, 'Transferring a lead is the Company Admin’s, or an employee they grant it to.');
        }

        $lead = $this->find($request, $uuid);
        $data = $request->validate([
            'to_member_uuid' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $to = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('uuid', $data['to_member_uuid'])
            ->firstOrFail();

        if ($lead->assigned_member_id === $to->id) {
            abort(422, ($to->user?->name ?? 'That employee') . ' already holds this lead.');
        }
        $this->assertCanReceive($me, $to);

        $this->moveLead($lead, $to, $me, $data['note'] ?? null);

        return response()->json([
            'message' => 'Lead #' . $lead->lead_no . ' transferred to ' . ($to->user?->name ?? 'the new owner') . '.',
            'data' => $this->serialize($lead->fresh()->load(['assignedMember.user:id,name', 'sharedWith.user:id,name', 'creator:id,name'])),
        ]);
    }

    /** Share a lead with colleagues — the Admin's front door for it. */
    public function share(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        if (! $me->allows('leads.share')) {
            abort(403, 'Sharing a lead is the Company Admin’s, or an employee they grant it to.');
        }

        $lead = $this->find($request, $uuid);
        $data = $request->validate([
            'member_uuids' => ['required', 'array', 'min:1'],
            'member_uuids.*' => ['string'],
        ]);

        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->whereIn('uuid', $data['member_uuids'])
            ->where('id', '!=', $lead->assigned_member_id)
            ->get();

        $lead->sharedWith()->syncWithoutDetaching(
            $members->mapWithKeys(fn (Member $m) => [$m->id => ['shared_by' => $me->id]])->all(),
        );

        $names = $members->map(fn (Member $m) => $m->user?->name)->filter()->values();
        ActivityLog::record($me, $org->id, 'lead.shared', $lead, [
            'lead_no' => $lead->lead_no,
            'shared_with' => $names->implode(', '),
        ]);

        return response()->json([
            'message' => 'Lead #' . $lead->lead_no . ' shared with ' . $names->implode(', ') . '.',
            'data' => $this->serialize($lead->fresh()->load(['assignedMember.user:id,name', 'sharedWith.user:id,name', 'creator:id,name'])),
        ]);
    }

    /**
     * Who a lead may be handed to.
     *
     * An Admin or Subadmin moves leads anywhere in the company. Anyone else —
     * a Team Head reshuffling their desk — may only move them within their
     * own team: not up to the Admin, and not across to another team's people.
     */
    private function assertCanReceive(Member $me, Member $to): void
    {
        if (in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            return;
        }

        if (in_array($to->crm_role, ['admin', 'subadmin'], true)) {
            abort(422, ($to->user?->name ?? 'That account')
                . ' runs the company — leads are worked by the team, not handed upward.');
        }
        if (! in_array($to->id, $me->teamMemberIds(), true)) {
            abort(422, ($to->user?->name ?? 'That employee') . ' is not in your team.');
        }
    }

    /** Ownership moves; the trail stays. Shares for both parties are cleared. */
    private function moveLead(Lead $lead, Member $to, Member $by, ?string $note, bool $notify = true): void
    {
        $from = $lead->assignedMember?->user?->name;

        $lead->update(['assigned_member_id' => $to->id, 'lead_status' => $lead->lead_status]);
        $lead->sharedWith()->detach(array_filter([$to->id]));

        ActivityLog::record($by, $lead->organization_id, 'lead.transferred', $lead, array_filter([
            'lead_no' => $lead->lead_no,
            'company_name' => $lead->company_name,
            'from' => $from ?? 'Unassigned',
            'to' => $to->user?->name,
            'note' => $note,
        ]));

        if ($notify && $to->user) {
            $to->user->notify(new CrmNotification(
                'crm_lead_access',
                'Lead #' . $lead->lead_no . ' (' . $lead->company_name . ') was transferred to you by '
                    . ($by->user?->name ?? 'your admin') . '.',
                '/crm/leads',
            ));
        }
    }

    // ---- Helpers -----------------------------------------------------------

    /** Org scope plus the employee's own-leads-only rule. */
    /**
     * Every call anybody in the company has made to this lead.
     *
     * The whole company's, not the reader's own — the point of a lead's call
     * history is seeing that three people have already rung it this week, and
     * a log filtered to yourself would hide exactly the fact worth knowing.
     * Reaching the lead at all is the permission; the ledger window has
     * already decided that by the time we are here.
     *
     * Netvork calls are not in here and cannot be: they are placed to a
     * Netvork account, and a lead is a phone number belonging to somebody who
     * has never heard of us.
     */
    public function calls(Request $request, string $uuid): JsonResponse
    {
        $lead = $this->find($request, $uuid);

        $calls = \App\Models\PhoneCall::with('user:id,uuid,name')
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->latest('placed_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $calls->map->serialize()->values(),
            'summary' => [
                'total' => $calls->count(),
                'connected' => $calls->where('outcome', 'connected')->count(),
                // Only the calls somebody actually reported a length for; an
                // average that counted unanswered ones as zero would say the
                // team talks for half as long as it does.
                'talk_seconds' => $calls->whereNotNull('duration_seconds')->sum('duration_seconds'),
                'callers' => $calls->pluck('user.name')->filter()->unique()->values(),
                // Said once here as well as on every row: nothing in this
                // total was measured by a network.
                'durations_are_reported' => true,
            ],
        ]);
    }

    private function scoped(Request $request): Builder
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $member */
        $member = $request->attributes->get('crm_member');

        // The window itself lives on the model, so the call log and anything
        // after it ask the same question rather than a similar one.
        return Lead::where('organization_id', $org->id)->visibleTo($member);
    }

    private function find(Request $request, string $uuid): Lead
    {
        return $this->scoped($request)->where('uuid', $uuid)->firstOrFail();
    }

    private function validateLead(Request $request, int $orgId): array
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'lead_status' => ['nullable', Rule::in(Lead::STATUSES)],
            'follow_up_at' => ['nullable', 'date'],
            'subject' => ['nullable', 'string', 'max:128'],
            'requirement' => ['nullable', 'string', 'max:5000'],
            'lead_type' => ['nullable', Rule::in(Lead::TYPES)],
            'source' => ['nullable', 'string', 'max:64'],
            'assigned_member_uuid' => ['nullable', 'string'],
        ]);

        // House style on names, email and codes.
        if (array_key_exists('company_name', $data)) {
            $data['company_name'] = TextCase::company($data['company_name']);
        }
        if (array_key_exists('contact_person', $data)) {
            $data['contact_person'] = TextCase::name($data['contact_person']);
        }
        if (array_key_exists('email', $data)) {
            $data['email'] = TextCase::email($data['email']);
        }

        if (array_key_exists('assigned_member_uuid', $data)) {
            $uuid = $data['assigned_member_uuid'];
            unset($data['assigned_member_uuid']);
            $data['assigned_member_id'] = $uuid
                ? Member::where('organization_id', $orgId)->where('uuid', $uuid)->firstOrFail()->id
                : null;
        }

        return $data;
    }

    private function serialize(Lead $l, bool $full = false): array
    {
        $base = [
            'uuid' => $l->uuid,
            'lead_no' => $l->lead_no,
            'company_name' => $l->company_name,
            'contact_person' => $l->contact_person,
            'phone' => $l->phone,
            'mobile' => $l->mobile,
            'email' => $l->email,
            'amount' => $l->amount,
            'lead_status' => $l->lead_status,
            'is_urgent' => (bool) $l->is_urgent,
            'duplicate_settled' => $l->duplicate_settled_at !== null,
            'follow_up_at' => $l->follow_up_at?->toDateTimeString(),
            'follow_up_due' => $l->lead_status === 'follow_up'
                && $l->follow_up_at !== null && $l->follow_up_at->lte(now()->endOfDay()),
            'subject' => $l->subject,
            'lead_type' => $l->lead_type,
            'source' => $l->source,
            'assigned_member' => $l->assignedMember
                ? ['uuid' => $l->assignedMember->uuid, 'name' => $l->assignedMember->user?->name]
                : null,
            'created_by' => $l->creator?->name,
            'reopen_count' => $l->reopen_count,
            'closed_at' => $l->closed_at?->toDateTimeString(),
            'shared_with' => $l->relationLoaded('sharedWith')
                ? $l->sharedWith->map(fn ($m) => ['uuid' => $m->uuid, 'name' => $m->user?->name])->values()
                : [],
            'client' => $l->client ? ['uuid' => $l->client->uuid, 'company_name' => $l->client->company_name] : null,
            'created_at' => $l->created_at?->toDateTimeString(),
        ];

        if (! $full) {
            return $base;
        }

        return $base + [
            'requirement' => $l->requirement,
            'logs' => $l->logs->map(fn ($log) => $this->serializeLog($log)),
        ];
    }

    private function serializeLog(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'by' => $log->member?->user?->name,
            'at' => $log->created_at->toDateTimeString(),
            'lead_no' => $log->changes['lead_no'] ?? null,
            'note' => $log->changes['note'] ?? null,
            'status' => $log->changes['status'] ?? null,
            'next_follow_up' => $log->changes['next_follow_up'] ?? null,
            'fields' => $log->changes['fields'] ?? null,
            'client' => $log->changes['client'] ?? null,
            'company_name' => $log->changes['company_name'] ?? null,
        ];
    }
}
