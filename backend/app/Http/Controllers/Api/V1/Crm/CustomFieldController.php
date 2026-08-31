<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\CustomField;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CrmNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Dedicated Company Workspace (DCW): a company asks for an extra field on a
 * form, the Super Admin approves it, and it appears in that company's
 * workspace only. Client forms first; the entity is a column so the same
 * machinery serves the sections that come later.
 */
class CustomFieldController extends Controller
{
    // ---- Company side ------------------------------------------------------

    /** Every DCW field of this organization, whatever its status. */
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $fields = CustomField::with('requester.user:id,name', 'decider:id,name')
            ->where('organization_id', $org->id)
            ->when($request->query('entity'), fn ($q, $e) => $q->where('entity', $e))
            ->orderBy('entity')->orderBy('sort')->orderBy('id')
            ->get();

        return response()->json([
            'data' => $fields,
            'entities' => CustomField::ENTITIES,
            'entity_labels' => CustomField::ENTITY_LABELS,
            'types' => CustomField::TYPES,
            // The Work Order as this company words it today, plus what each
            // built-in column will and will not allow.
            'work_order_method' => CustomField::workOrderMethod($org->id),
            'invoice_method' => CustomField::invoiceMethod($org->id),
            'tax_setup' => CustomField::taxSetup($org->id),
            'builtins' => [
                'work_order' => CustomField::builtinsFor('work_order'),
                'invoice' => CustomField::builtinsFor('invoice'),
                'tax' => CustomField::builtinsFor('tax'),
            ],
            'tax_kinds' => CustomField::TAX_KINDS,
            'tax_bases' => CustomField::TAX_BASES,
        ]);
    }

    /** Request a new field — it starts life pending. */
    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'entity' => ['required', Rule::in(CustomField::ENTITIES)],
            'label' => ['required', 'string', 'max:255'],
            // A built-in column may keep a shape we do not offer as a new
            // field — Validity is a date pair, not a picker.
            'type' => ['required', Rule::in($request->filled('builtin_key')
                ? array_merge(CustomField::TYPES, ['daterange'])
                : CustomField::TYPES)],
            'options' => ['nullable', 'array', 'max:30'],
            'options.*' => ['string', 'max:120'],
            'is_required' => ['nullable', 'boolean'],
            'help' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:512'],
            // Set when the request re-words one of our own Work Order columns
            // rather than adding a new one.
            'builtin_key' => ['nullable', 'string', 'max:64'],
            'is_hidden' => ['nullable', 'boolean'],
            // Tax lines carry three things a plain field does not.
            'tax_kind' => ['nullable', Rule::in(CustomField::TAX_KINDS)],
            'tax_basis' => ['nullable', Rule::in(CustomField::TAX_BASES)],
            'default_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $builtinKey = $data['builtin_key'] ?? null;
        unset($data['builtin_key']);

        if ($builtinKey !== null) {
            $data = $this->validateBuiltin($org->id, $builtinKey, $data);
            $key = $builtinKey;
        } else {
            unset($data['is_hidden']);
            $key = $this->uniqueKey($org->id, $data['entity'], $data['label']);
        }

        if ($data['entity'] === 'tax') {
            // A money line is a rate, whatever the form called the type.
            $data['type'] = 'number';
            $data['tax_kind'] = $data['tax_kind'] ?? 'tax';
            $data['tax_basis'] = $data['tax_basis'] ?? 'taxable';
        } else {
            unset($data['tax_kind'], $data['tax_basis'], $data['default_rate']);
        }

        if (($data['type'] ?? null) === 'select' && empty($data['is_hidden'])
            && count(array_filter($data['options'] ?? [])) < 2) {
            abort(422, 'A dropdown field needs at least two options.');
        }

        $field = CustomField::create($data + [
            'organization_id' => $org->id,
            'key' => $key,
            'is_builtin' => $builtinKey !== null,
            'requested_by' => $me->id,
            'sort' => (int) CustomField::where('organization_id', $org->id)->where('entity', $data['entity'])->max('sort') + 1,
        ]);

        // The company's own trail: who asked for what, and when.
        ActivityLog::record($me, $org->id, $builtinKey !== null ? 'dcw.column_requested' : 'dcw.requested', $field, [
            'label' => $field->label,
            'entity' => $field->entity,
            'type' => $field->type,
            'by' => $me->user?->name,
            'reason' => $field->reason,
        ]);

        // The Super Admin decides these, so they are the ones told.
        Notification::send($this->superAdmins(), new CrmNotification(
            'crm_dcw',
            $org->name . ' requested a new ' . (CustomField::ENTITY_LABELS[$data['entity']] ?? $data['entity'])
                . ' field: "' . $data['label'] . '".',
            '/crm/field-requests',
        ));

        return response()->json([
            'message' => $builtinKey !== null
                ? 'Column change requested — your Work Order keeps its current wording until the Super Admin approves it.'
                : 'Field requested — it appears in your forms once the Super Admin approves it.',
            'data' => $field->load('requester.user:id,name'),
        ], 201);
    }

    /** Withdraw a pending request, or retire an approved field. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $field = CustomField::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        // Values already captured stay in the records; only the field goes.
        $field->delete();

        return response()->json(['message' => 'Field removed from your workspace.']);
    }

    // ---- Super Admin side --------------------------------------------------

    /** Every company's requests, pending first, filterable by company. */
    public function pending(Request $request): JsonResponse
    {
        $fields = CustomField::with(['organization:id,uuid,name', 'requester.user:id,name', 'decider:id,name'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('organization'), fn ($q, $o) => $q
                ->whereHas('organization', fn ($org) => $org->where('uuid', $o)))
            ->when($request->query('entity'), fn ($q, $e) => $q->where('entity', $e))
            ->orderByRaw("case status when 'pending' then 0 else 1 end")
            ->orderByDesc('id')
            ->paginate(30);

        $fields->getCollection()->transform(fn (CustomField $f) => $f->toArray() + [
            'organization' => ['uuid' => $f->organization?->uuid, 'name' => $f->organization?->name],
        ]);

        return response()->json([
            'pending_count' => CustomField::where('status', 'pending')->count(),
            // The companies that have ever asked — the filter's options.
            'organizations' => Organization::whereIn('id', CustomField::select('organization_id'))
                ->orderBy('name')
                ->get(['uuid', 'name']),
        ] + $fields->toArray());
    }

    public function decide(Request $request, string $uuid): JsonResponse
    {
        $field = CustomField::with('organization', 'requester.user')->where('uuid', $uuid)->firstOrFail();

        if ($field->status !== 'pending') {
            abort(422, 'This request was already decided.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $field->update([
            'status' => $data['status'],
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        // The decision lands in the company's trail too, so their User log
        // reads end to end: requested by X, then decided by the Super Admin.
        // The decider may have no membership there, hence the null actor.
        $actor = Member::where('organization_id', $field->organization_id)
            ->where('user_id', $request->user()->id)
            ->first();
        ActivityLog::record($actor, $field->organization_id, 'dcw.' . $data['status'], $field, [
            'label' => $field->label,
            'entity' => $field->entity,
            'by' => $request->user()->name,
            'requested_by' => $field->requester?->user?->name,
            'note' => $data['note'] ?? null,
        ]);

        // Tell whoever asked, and the company's admins with them.
        $audience = Member::deciders($field->organization_id, CustomField::ENTITY_MODULES[$field->entity] ?? 'clients')
            ->push($field->requester?->user)
            ->filter()
            ->unique('id');
        Notification::send($audience, new CrmNotification(
            'crm_dcw',
            'Your field "' . $field->label . '" was ' . $data['status']
                . ($data['status'] === 'approved'
                    ? ' and is now live on your ' . (CustomField::ENTITY_LABELS[$field->entity] ?? $field->entity) . ' form.'
                    : '.'),
            '/crm/workspace-fields',
        ));

        return response()->json(['message' => 'Field ' . $data['status'] . '.', 'data' => $field->fresh()]);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * What a company may do to one of our own Work Order columns. Qty and
     * Unit price only take a new name — the arithmetic depends on them — and
     * a date pair cannot become a dropdown.
     */
    private function validateBuiltin(int $orgId, string $key, array $data): array
    {
        $entity = $data['entity'] ?? '';
        $builtins = CustomField::builtinsFor($entity);

        if (! isset($builtins[$key])) {
            abort(422, 'That is not a column of the ' . (CustomField::ENTITY_LABELS[$entity] ?? $entity) . ' form.');
        }

        $builtin = $builtins[$key];

        // One customisation per column: remove the old one to change it, so
        // the live Work Order is never two competing definitions.
        $existing = CustomField::where('organization_id', $orgId)
            ->where('entity', $entity)
            ->where('key', $key)
            ->first();
        if ($existing) {
            abort(422, $builtin['label'] . ' already has a customisation ('
                . $existing->status . '). Remove it first to request a different one.');
        }

        if (! empty($data['is_hidden']) && ! in_array('hide', $builtin['can'], true)) {
            abort(422, $builtin['label'] . ' cannot be hidden — the line total is worked out from it.');
        }

        // A tax line's "type" is always a rate; only its wording, standing
        // rate and whether it is used at all are the company's to change.
        if ($entity === 'tax') {
            return $data;
        }

        if (! in_array($data['type'], $builtin['types'], true)) {
            abort(422, in_array('type', $builtin['can'], true)
                ? $builtin['label'] . ' can be ' . implode(' or ', $builtin['types']) . ' only.'
                : $builtin['label'] . ' keeps the type it has — you can rename it, not re-type it.');
        }

        return $data;
    }

    private function uniqueKey(int $orgId, string $entity, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $base = Str::limit($base, 50, '');
        $key = $base;
        $n = 2;
        while (CustomField::where('organization_id', $orgId)->where('entity', $entity)->where('key', $key)->exists()) {
            $key = $base . '_' . $n++;
        }

        return $key;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function superAdmins()
    {
        $role = Role::where('slug', 'super_admin')->first();

        return $role ? $role->users()->get() : collect();
    }
}
