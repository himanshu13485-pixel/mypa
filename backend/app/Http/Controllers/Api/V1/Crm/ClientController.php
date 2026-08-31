<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Client;
use App\Models\Crm\CustomField;
use App\Models\Crm\ClientAccessRequest;
use App\Models\Crm\Member;
use App\Notifications\CrmNotification;
use App\Support\TextCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    /**
     * The fields that print on a proforma or an invoice. Changing one changes
     * what a client is billed as, so it is a granted act rather than ordinary
     * editing — the working fields (category, status, notes, the company's own
     * DCW fields) stay with anyone who may edit clients at all.
     */
    private const BILLING_FIELDS = [
        'company_name', 'title', 'contact_person', 'designation', 'address', 'city',
        'state', 'pincode', 'country', 'telephone', 'mobile', 'email',
        'alternate_email', 'website', 'gst_no', 'pan_no',
    ];

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $query = $this->scoped($request)->with(['assignedMember.user:id,name', 'sharedWith.user:id,name']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('gst_no', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($assigned = $request->query('assigned_to')) {
            $query->whereHas('assignedMember', fn ($m) => $m->where('uuid', $assigned));
        }

        $clients = $query->orderBy('company_name')->paginate(25);
        $clients->getCollection()->transform(fn ($c) => $this->serialize($c));

        return response()->json($clients);
    }

    /** Lightweight list for the invoice form's client picker. */
    public function options(Request $request): JsonResponse
    {
        $clients = $this->scoped($request)
            ->where('status', 'active')
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q
                ->where(fn ($w) => $w->where('company_name', 'like', "%{$s}%")
                    ->orWhere('contact_person', 'like', "%{$s}%")))
            ->orderBy('company_name')
            ->limit(30)
            ->get(['uuid', 'company_name', 'contact_person', 'city', 'gst_no', 'category', 'address', 'state', 'email']);

        return response()->json(['data' => $clients]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $data = $this->validateClient($request, $org->id);

        // The company keeps one record per client. Adding one that already
        // exists is not a duplicate row — it is a request to be let in.
        if ($existing = $this->findDuplicate($org->id, $data)) {
            return $this->duplicateResponse($existing, $me, $org->id);
        }

        // Ownership: whoever adds the client owns it. An employee cannot
        // hand it to somebody else; a manager may.
        $data['assigned_member_id'] = $this->isManager($me)
            ? ($data['assigned_member_id'] ?? $me->id)
            : $me->id;

        $client = Client::create($data + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        $shared = $this->syncShares($request, $client, $me);

        ActivityLog::record($me, $org->id, 'client.created', $client, array_filter([
            'company_name' => $client->company_name,
            'owner' => $client->fresh()->assignedMember?->user?->name,
            'shared_with' => $shared ? implode(', ', $shared) : null,
        ]));

        return response()->json([
            'message' => 'Client added.' . ($shared ? ' Shared with ' . implode(', ', $shared) . '.' : ''),
            'data' => $this->serialize($client->load(['assignedMember.user:id,name', 'sharedWith.user:id,name'])),
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $client = $this->find($request, $uuid)->load(['assignedMember.user:id,name', 'sharedWith.user:id,name']);

        $invoices = $client->invoices()->latest('invoice_date')->limit(20)
            ->get()
            ->map(fn ($i) => [
                'uuid' => $i->uuid,
                'kind' => $i->kind,
                'number' => $i->number,
                'invoice_date' => $i->invoice_date->toDateString(),
                'total' => $i->total,
                'currency' => $i->currency,
                'payment_status' => $i->payment_status,
                'status' => $i->status,
            ]);

        // Who held this client before, and when it changed hands.
        $transfers = ActivityLog::with('member.user:id,name')
            ->where('subject_type', $client->getMorphClass())
            ->where('subject_id', $client->id)
            ->whereIn('action', ['client.transferred', 'client.shared'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'action' => $log->action,
                'by' => $log->member?->user?->name,
                'at' => $log->created_at?->toDateTimeString(),
                'from' => data_get($log->changes, 'from'),
                'to' => data_get($log->changes, 'to') ?? data_get($log->changes, 'shared_with'),
                'invoices_kept' => data_get($log->changes, 'invoices_kept'),
                'note' => data_get($log->changes, 'note'),
            ]);

        return response()->json(['data' => $this->serialize($client) + [
            'invoices' => $invoices,
            'notes' => $client->notes,
            'transfers' => $transfers,
        ]]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $client = $this->find($request, $uuid);
        $data = $this->validateClient($request, $org->id);

        // Renaming onto another client's name would split the history.
        if ($existing = $this->findDuplicate($org->id, $data, $client->id)) {
            abort(422, $existing->company_name . ' is already on the books — two records for one client.');
        }

        // Only a manager may hand the client to somebody else.
        if (! $this->isManager($me)) {
            unset($data['assigned_member_id']);
        }

        // What the client is billed as is a granted act. Everything else on
        // the form saves as usual, so ordinary work is never blocked.
        if (! $me->allows('clients.edit_details')) {
            $changed = [];
            foreach (self::BILLING_FIELDS as $field) {
                if (array_key_exists($field, $data) && (string) ($client->{$field} ?? '') !== (string) ($data[$field] ?? '')) {
                    $changed[] = str_replace('_', ' ', $field);
                }
                unset($data[$field]);
            }
            if ($changed !== []) {
                abort(422, 'Changing the ' . implode(', ', $changed)
                    . ' needs the “edit billing details” permission — ask your Company Admin.');
            }
        }

        $client->update($data);
        $this->syncShares($request, $client, $me);
        ActivityLog::record($me, $org->id, 'client.updated', $client, ['company_name' => $client->company_name]);

        return response()->json([
            'message' => 'Client updated.',
            'data' => $this->serialize($client->fresh()->load(['assignedMember.user:id,name', 'sharedWith.user:id,name'])),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        if (! $me->allows('clients.delete')) {
            abort(403, 'Deleting a client is the Company Admin’s, or an employee they grant it to.');
        }

        $client = $this->find($request, $uuid);

        if ($client->invoices()->exists()) {
            // Invoices reference the client, so history wins over deletion.
            $client->update(['status' => 'inactive']);

            return response()->json(['message' => 'Client has invoices, so it was set inactive instead of deleted.']);
        }

        ActivityLog::record($request->attributes->get('crm_member'), $client->organization_id, 'client.deleted', $client, ['company_name' => $client->company_name]);
        $client->delete();

        return response()->json(['message' => 'Client deleted.']);
    }

    // ---- Transfer ----------------------------------------------------------

    /**
     * Hand a client to somebody else. Ownership moves; history does not —
     * invoices already raised stay credited to whoever raised them, and the
     * outgoing employee keeps seeing the client's details on those documents
     * even though the client itself leaves their portfolio.
     */
    public function transfer(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        if (! $me->allows('clients.transfer')) {
            abort(403, 'Transferring a client is the Company Admin’s, or an employee they grant it to.');
        }

        $client = $this->find($request, $uuid);
        $data = $request->validate([
            'to_member_uuid' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $to = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('uuid', $data['to_member_uuid'])
            ->firstOrFail();

        $from = $client->assignedMember()->with('user:id,name')->first();

        if ($from && $from->id === $to->id) {
            abort(422, ($from->user?->name ?? 'That employee') . ' already holds this client.');
        }

        // Freeze the ledger first: any of this client's documents that never
        // named a salesperson are stamped with the outgoing owner, so the
        // transfer cannot quietly re-credit past work to the new one.
        $kept = 0;
        if ($from) {
            $client->invoices()->whereNull('member_id')->update(['member_id' => $from->id]);
            $kept = $client->invoices()->where('member_id', $from->id)->count();
        }

        $client->update(['assigned_member_id' => $to->id]);

        // The outgoing owner loses sight of it entirely, and the new owner
        // does not need a share row on top of owning it.
        $client->sharedWith()->detach(array_filter([$from?->id, $to->id]));

        // A pending "let me in" from the new owner is answered by the move.
        ClientAccessRequest::where('client_id', $client->id)
            ->where('member_id', $to->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'decided_by' => $me->id,
                'decided_at' => now(),
                'decision_note' => 'Client transferred to them.',
            ]);

        ActivityLog::record($me, $org->id, 'client.transferred', $client, array_filter([
            'company_name' => $client->company_name,
            'from' => $from?->user?->name ?? 'Company Admin',
            'to' => $to->user?->name,
            'invoices_kept' => $kept ?: null,
            'note' => $data['note'] ?? null,
        ]));

        $summary = $kept > 0
            ? ' ' . $kept . ' invoice' . ($kept === 1 ? '' : 's') . ' stay' . ($kept === 1 ? 's' : '')
                . ' with ' . ($from?->user?->name ?? 'the previous owner') . '.'
            : '';

        if ($to->user) {
            $to->user->notify(new CrmNotification(
                'crm_client_access',
                $client->company_name . ' was transferred to you by ' . ($me->user?->name ?? 'your admin') . '.'
                    . (($data['note'] ?? null) ? ' "' . $data['note'] . '"' : ''),
                '/crm/clients',
            ));
        }
        if ($from?->user && $from->id !== $me->id) {
            $from->user->notify(new CrmNotification(
                'crm_client_access',
                $client->company_name . ' has moved to ' . ($to->user?->name ?? 'another colleague') . '.' . $summary,
                '/crm/clients',
            ));
        }

        return response()->json([
            'message' => $client->company_name . ' transferred to ' . ($to->user?->name ?? 'the new owner') . '.' . $summary,
            'data' => $this->serialize($client->fresh()->load(['assignedMember.user:id,name', 'sharedWith.user:id,name'])),
        ]);
    }

    // ---- Access requests ---------------------------------------------------

    /** "Let me in on this client" — pending first. */
    public function accessRequests(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = ClientAccessRequest::with([
            'client:id,uuid,company_name,assigned_member_id', 'client.assignedMember.user:id,name',
            'member.user:id,name', 'decider.user:id,name',
        ])->where('organization_id', $org->id);

        // Whoever cannot decide sees only what they asked for.
        if (! $this->isManager($me)) {
            $query->where('member_id', $me->id);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $rows = $query->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(25);

        $rows->getCollection()->transform(fn (ClientAccessRequest $r) => [
            'uuid' => $r->uuid,
            'client' => $r->client ? ['uuid' => $r->client->uuid, 'company_name' => $r->client->company_name] : null,
            'owner' => $r->client?->assignedMember?->user?->name,
            'requested_by' => $r->member?->user?->name,
            'note' => $r->note,
            'status' => $r->status,
            'decided_by' => $r->decider?->user?->name,
            'decided_at' => $r->decided_at?->toDateTimeString(),
            'decision_note' => $r->decision_note,
            'created_at' => $r->created_at?->toDateTimeString(),
        ]);

        return response()->json($rows);
    }

    /** Approving shares the existing client; rejecting closes the request. */
    public function decideAccessRequest(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $accessRequest = ClientAccessRequest::with(['client', 'member.user'])
            ->where('organization_id', $org->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($accessRequest->status !== 'pending') {
            abort(422, 'This request was already decided.');
        }
        // Nobody lets themselves in, whatever rights they hold.
        if ($accessRequest->member_id === $me->id) {
            abort(403, 'You cannot decide your own request.');
        }
        if (! $me->allows('clients.share')) {
            abort(403, 'Deciding a client access request is the Company Admin’s, or an employee they grant it to.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        if ($data['status'] === 'approved' && $accessRequest->client && $accessRequest->member) {
            $accessRequest->client->sharedWith()->syncWithoutDetaching([
                $accessRequest->member_id => ['shared_by' => $me->id],
            ]);
            ActivityLog::record($me, $org->id, 'client.shared', $accessRequest->client, [
                'company_name' => $accessRequest->client->company_name,
                'shared_with' => $accessRequest->member->user?->name,
            ]);
        }

        $accessRequest->update([
            'status' => $data['status'],
            'decided_by' => $me->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        if ($accessRequest->member?->user) {
            $accessRequest->member->user->notify(new CrmNotification(
                'crm_client_access',
                'Your access request for ' . ($accessRequest->client?->company_name ?? 'a client') . ' was '
                    . $data['status'] . (($data['note'] ?? null) ? ' — "' . $data['note'] . '"' : '') . '.',
                '/crm/clients',
            ));
        }

        return response()->json(['message' => 'Request ' . $data['status'] . '.']);
    }

    // ---- Helpers -----------------------------------------------------------

    private function isManager(Member $member): bool
    {
        return in_array($member->crm_role, ['admin', 'subadmin'], true);
    }

    /**
     * A portfolio: managers see the whole company, everybody else sees the
     * clients their team owns plus the ones shared with them.
     */
    private function scoped(Request $request): Builder
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Client::where('organization_id', $org->id);

        if (! $this->isManager($me)) {
            $team = $me->teamMemberIds();
            $query->where(fn ($q) => $q->whereIn('assigned_member_id', $team)
                ->orWhereHas('sharedWith', fn ($s) => $s->whereIn('crm_members.id', $team)));
        }

        return $query;
    }

    /** The same company already on the books — by name, or by GST number. */
    private function findDuplicate(int $orgId, array $data, ?int $ignoreId = null): ?Client
    {
        $key = Client::matchKey($data['company_name'] ?? null);
        $gst = $data['gst_no'] ?? null;

        return Client::where('organization_id', $orgId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->with('assignedMember.user:id,name')
            ->get(['id', 'uuid', 'company_name', 'gst_no', 'assigned_member_id'])
            ->first(fn (Client $c) => Client::matchKey($c->company_name) === $key
                || ($gst && $c->gst_no && strcasecmp($c->gst_no, $gst) === 0));
    }

    /**
     * Someone tried to add a client the company already has. A manager is
     * simply told; anyone else has a request raised for them on the spot.
     */
    private function duplicateResponse(Client $existing, Member $me, int $orgId): JsonResponse
    {
        $owner = $existing->assignedMember?->user?->name;

        if ($this->isManager($me)) {
            return response()->json([
                'message' => $existing->company_name . ' is already on the books'
                    . ($owner ? ' with ' . $owner : '') . '. Open that record instead of adding it again.',
                'duplicate' => ['uuid' => $existing->uuid, 'company_name' => $existing->company_name, 'owner' => $owner],
            ], 422);
        }

        // Already mine? Then it is simply a duplicate, no request needed.
        $alreadyMine = $existing->assigned_member_id === $me->id
            || $existing->sharedWith()->where('crm_members.id', $me->id)->exists();

        if ($alreadyMine) {
            return response()->json([
                'message' => $existing->company_name . ' is already in your client list.',
                'duplicate' => ['uuid' => $existing->uuid, 'company_name' => $existing->company_name, 'owner' => $owner],
            ], 422);
        }

        $pending = ClientAccessRequest::firstOrCreate(
            ['client_id' => $existing->id, 'member_id' => $me->id, 'status' => 'pending'],
            ['organization_id' => $orgId, 'note' => 'Tried to add this client'],
        );

        if ($pending->wasRecentlyCreated) {
            Notification::send(
                Member::deciders($orgId, 'clients', $me->id),
                new CrmNotification(
                    'crm_client_access',
                    ($me->user?->name ?? 'Someone') . ' asked for access to ' . $existing->company_name
                        . ($owner ? ' (currently with ' . $owner . ')' : '') . '.',
                    '/crm/clients?tab=requests',
                ),
            );
            ActivityLog::record($me, $orgId, 'client.access_requested', $existing, [
                'company_name' => $existing->company_name,
                'by' => $me->user?->name,
            ]);
        }

        return response()->json([
            'message' => $existing->company_name . ' already exists in the company. '
                . ($pending->wasRecentlyCreated
                    ? 'A request has been sent to your admin — please contact your Company Admin or Subadmin.'
                    : 'Your earlier request is still awaiting your admin.'),
            'duplicate' => ['company_name' => $existing->company_name],
            'request_pending' => true,
        ], 422);
    }

    /**
     * Managers may share a client with colleagues as they save it. Nobody
     * else can, and no selection means the client stays with its owner.
     *
     * @return string[] the names it is now shared with
     */
    private function syncShares(Request $request, Client $client, Member $me): array
    {
        if (! $me->allows('clients.share') || ! $request->has('share_with')) {
            return [];
        }

        $uuids = array_filter((array) $request->input('share_with'));
        $members = Member::with('user:id,name')
            ->where('organization_id', $client->organization_id)
            ->whereIn('uuid', $uuids)
            // Sharing with the owner would be a no-op row.
            ->where('id', '!=', $client->assigned_member_id)
            ->get();

        $client->sharedWith()->sync($members->mapWithKeys(fn (Member $m) => [$m->id => ['shared_by' => $me->id]])->all());

        return $members->map(fn (Member $m) => $m->user?->name)->filter()->values()->all();
    }

    private function find(Request $request, string $uuid): Client
    {
        return $this->scoped($request)->where('uuid', $uuid)->firstOrFail();
    }

    private function validateClient(Request $request, int $orgId): array
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:8'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:512'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'pincode' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:128'],
            'telephone' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'alternate_email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'gst_no' => ['nullable', 'string', 'max:32'],
            'pan_no' => ['nullable', 'string', 'max:32'],
            'category' => ['nullable', Rule::in(Client::CATEGORIES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'assigned_member_uuid' => ['nullable', 'string'],
        ]);

        // House style, applied on the way in — see App\Support\TextCase.
        foreach (['company_name' => 'company', 'contact_person' => 'name', 'designation' => 'name',
            'city' => 'name', 'state' => 'name', 'country' => 'name'] as $field => $style) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $style === 'company'
                    ? TextCase::company($data[$field])
                    : TextCase::name($data[$field]);
            }
        }
        foreach (['email', 'alternate_email', 'website'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = TextCase::email($data[$field]);
            }
        }
        foreach (['gst_no', 'pan_no'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = TextCase::code($data[$field]);
            }
        }

        if (array_key_exists('assigned_member_uuid', $data)) {
            $uuid = $data['assigned_member_uuid'];
            unset($data['assigned_member_uuid']);
            $data['assigned_member_id'] = $uuid
                ? Member::where('organization_id', $orgId)->where('uuid', $uuid)->firstOrFail()->id
                : null;
        }

        // Dedicated Company Workspace fields: validated against this org's
        // APPROVED fields only, so a stray key can never be smuggled in.
        $fields = CustomField::approvedFor($orgId, 'client');
        if ($fields->isNotEmpty()) {
            $rules = [];
            $names = [];
            foreach ($fields as $field) {
                $rules['custom_fields.' . $field->key] = $field->validationRule();
                $names['custom_fields.' . $field->key] = $field->label;
            }
            $validated = $request->validate($rules, [], $names);
            $values = [];
            foreach ($fields as $field) {
                $value = data_get($validated, 'custom_fields.' . $field->key);
                if ($field->type === 'checkbox') {
                    $values[$field->key] = (bool) $value;
                } elseif ($value !== null && $value !== '') {
                    $values[$field->key] = $value;
                }
            }
            $data['custom_fields'] = $values;
        }

        return $data;
    }

    private function serialize(Client $c): array
    {
        return [
            'uuid' => $c->uuid,
            'company_name' => $c->company_name,
            'title' => $c->title,
            'contact_person' => $c->contact_person,
            'designation' => $c->designation,
            'address' => $c->address,
            'city' => $c->city,
            'state' => $c->state,
            'pincode' => $c->pincode,
            'country' => $c->country,
            'telephone' => $c->telephone,
            'mobile' => $c->mobile,
            'email' => $c->email,
            'alternate_email' => $c->alternate_email,
            'website' => $c->website,
            'gst_no' => $c->gst_no,
            'pan_no' => $c->pan_no,
            'category' => $c->category,
            // Came back after a closed lead — worth knowing before the call.
            'is_repeat' => (bool) $c->is_repeat,
            'repeat_count' => (int) $c->repeat_count,
            'status' => $c->status,
            'assigned_member' => $c->assignedMember
                ? ['uuid' => $c->assignedMember->uuid, 'name' => $c->assignedMember->user?->name]
                : null,
            'shared_with' => $c->relationLoaded('sharedWith')
                ? $c->sharedWith->map(fn ($m) => ['uuid' => $m->uuid, 'name' => $m->user?->name])->values()
                : [],
            'custom_fields' => $c->custom_fields ?? (object) [],
            'created_at' => $c->created_at?->toDateTimeString(),
        ];
    }
}
