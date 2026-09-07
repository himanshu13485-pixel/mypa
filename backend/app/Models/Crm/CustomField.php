<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Dedicated Company Workspace field: extra data a single company asked
 * for and the Super Admin approved. It belongs to that organization alone.
 */
class CustomField extends Model
{
    use HasUuids;

    protected $table = 'crm_custom_fields';

    /** Forms that can carry DCW fields. */
    public const ENTITIES = ['client', 'work_order', 'invoice', 'tax'];

    /** Which module's approvers hear about a request for this form. */
    public const ENTITY_MODULES = [
        'client' => 'clients', 'work_order' => 'invoices', 'invoice' => 'invoices', 'tax' => 'invoices',
    ];

    public const ENTITY_LABELS = [
        'client' => 'Client', 'work_order' => 'Work Order', 'invoice' => 'Document', 'tax' => 'Tax line',
    ];

    /**
     * The columns a Work Order line starts with, and how far a company may
     * bend each one. Every company sells something different, so the words
     * are theirs: `rename` changes the heading, `hide` drops the column, and
     * `type` re-types it — typically into a dropdown of their own products.
     *
     * Qty and Unit price are rename-only: the money is computed from them.
     * Validity is a date pair, so it renames or hides but never re-types.
     */
    public const BUILTIN_WORK_ORDER = [
        'membership' => ['label' => 'Membership', 'type' => 'text', 'can' => ['rename', 'hide', 'type'], 'types' => ['text', 'select']],
        'plan_name' => ['label' => 'Plan name', 'type' => 'text', 'can' => ['rename', 'hide', 'type'], 'types' => ['text', 'select']],
        'description' => ['label' => 'Description', 'type' => 'textarea', 'can' => ['rename', 'hide', 'type'], 'types' => ['text', 'textarea', 'select']],
        'validity' => ['label' => 'Validity', 'type' => 'daterange', 'can' => ['rename', 'hide'], 'types' => ['daterange']],
        'qty' => ['label' => 'Qty', 'type' => 'number', 'can' => ['rename'], 'types' => ['number']],
        'unit_price' => ['label' => 'Unit price', 'type' => 'number', 'can' => ['rename'], 'types' => ['number']],
    ];

    public const TYPES = ['text', 'textarea', 'number', 'alphanumeric', 'checkbox', 'date', 'select'];

    /**
     * The document's own fields, before the Work Order lines start. A company
     * words these as it likes and switches off the ones it never fills.
     *
     * The issuing company, the client and the salesperson are not here on
     * purpose: each is picked from its own section (Billing setup, Clients,
     * Users), so their wording belongs there rather than on this form.
     */
    public const BUILTIN_INVOICE = [
        'invoice_date' => ['label' => 'Date', 'type' => 'date', 'can' => ['rename'], 'types' => ['date']],
        'due_date' => ['label' => 'Due date', 'type' => 'date', 'can' => ['rename', 'hide'], 'types' => ['date']],
        'client_category' => ['label' => 'Client status', 'type' => 'select', 'can' => ['rename', 'hide'], 'types' => ['select']],
        'pricing_tier' => ['label' => 'Pricing', 'type' => 'select', 'can' => ['rename', 'hide'], 'types' => ['select']],
        'terms_of_payment' => ['label' => 'Terms of payment', 'type' => 'text', 'can' => ['rename', 'hide', 'type'], 'types' => ['text', 'select']],
        'subscription_type' => ['label' => 'Subscription', 'type' => 'select', 'can' => ['rename', 'hide'], 'types' => ['select']],
        'dispatch_status' => ['label' => 'Dispatch', 'type' => 'select', 'can' => ['rename', 'hide'], 'types' => ['select']],
        'fx' => ['label' => 'Foreign currency', 'type' => 'text', 'can' => ['rename', 'hide'], 'types' => ['text']],
        'notes' => ['label' => 'Notes', 'type' => 'textarea', 'can' => ['rename', 'hide'], 'types' => ['textarea']],
    ];

    /**
     * The money lines every company starts with. They can be renamed, given a
     * standing rate, or switched off — and a company adds its own beside them
     * (a service charge, a rebate, a levy nobody else has).
     *
     * kind: discount comes off the subtotal, tax adds, deduction subtracts.
     * basis: what the percentage is worked on.
     */
    public const BUILTIN_TAX = [
        'discount' => ['label' => 'Discount', 'kind' => 'discount', 'basis' => 'subtotal'],
        'cgst' => ['label' => 'CGST', 'kind' => 'tax', 'basis' => 'taxable'],
        'sgst' => ['label' => 'SGST', 'kind' => 'tax', 'basis' => 'taxable'],
        'igst' => ['label' => 'IGST', 'kind' => 'tax', 'basis' => 'taxable'],
        'other_tax' => ['label' => 'Other tax', 'kind' => 'tax', 'basis' => 'taxable'],
        'tds' => ['label' => 'TDS', 'kind' => 'deduction', 'basis' => 'taxable'],
    ];

    public const TAX_KINDS = ['discount', 'tax', 'deduction'];

    public const TAX_BASES = ['subtotal', 'taxable'];

    /** The built-in columns of whichever form carries them. */
    public static function builtinsFor(string $entity): array
    {
        return match ($entity) {
            'work_order' => static::BUILTIN_WORK_ORDER,
            'invoice' => static::BUILTIN_INVOICE,
            'tax' => collect(static::BUILTIN_TAX)
                ->map(fn ($line) => [
                    'label' => $line['label'],
                    'type' => 'number',
                    'can' => ['rename', 'hide', 'rate'],
                    'types' => ['number'],
                ])->all(),
            default => [],
        };
    }

    protected $attributes = [
        'status' => 'pending', 'entity' => 'client', 'type' => 'text',
        'is_builtin' => false, 'is_hidden' => false,
    ];

    protected $fillable = [
        'organization_id', 'entity', 'key', 'label', 'type', 'options',
        'is_required', 'help', 'sort', 'status', 'reason', 'requested_by',
        'decided_by', 'decided_at', 'decision_note', 'is_builtin', 'is_hidden',
        'tax_kind', 'tax_basis', 'default_rate',
        'pending', 'pending_by', 'pending_at',
    ];

    /**
     * The parts of a field a company may ask to change.
     *
     * Named once, because an amendment is stored as this shape and applied by
     * writing it back — a list that drifts from what the form sends is a
     * change that half lands.
     */
    public const AMENDABLE = [
        'label', 'type', 'options', 'is_required', 'help', 'is_hidden',
        'tax_kind', 'tax_basis', 'default_rate', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'is_builtin' => 'boolean',
            'is_hidden' => 'boolean',
            'default_rate' => 'decimal:3',
            'decided_at' => 'datetime',
            'pending' => 'array',
            'pending_at' => 'datetime',
        ];
    }

    /** Whoever asked for the outstanding change, which may not be the requester. */
    public function pendingRequester(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Member::class, 'pending_by');
    }

    /** Whether a change is waiting on the Super Admin. */
    public function hasAmendment(): bool
    {
        return filled($this->pending);
    }

    /**
     * The field as it would be if the outstanding change were allowed.
     *
     * Only the keys actually proposed are taken, so a payload that says
     * nothing about `help` leaves the help text alone rather than clearing
     * it — an amendment is a change, not a replacement.
     */
    public function applyAmendment(): void
    {
        $proposal = collect($this->pending ?? [])->only(self::AMENDABLE)->all();

        $this->update($proposal + ['pending' => null, 'pending_by' => null, 'pending_at' => null]);
    }

    /** Drop the outstanding change; the field carries on as it was. */
    public function dropAmendment(): void
    {
        $this->update(['pending' => null, 'pending_by' => null, 'pending_at' => null]);
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** The approved EXTRA fields a form should render, in order. */
    public static function approvedFor(int $organizationId, string $entity)
    {
        return static::where('organization_id', $organizationId)
            ->where('entity', $entity)
            ->where('is_builtin', false)
            ->where('status', 'approved')
            ->orderBy('sort')->orderBy('id')
            ->get();
    }

    /**
     * A company's approved overrides of our built-in columns, keyed by column.
     */
    protected static function overridesFor(int $organizationId, string $entity)
    {
        return static::where('organization_id', $organizationId)
            ->where('entity', $entity)
            ->where('is_builtin', true)
            ->where('status', 'approved')
            ->get()
            ->keyBy('key');
    }

    /**
     * One form's columns as this company words them: our built-ins with their
     * changes applied, then the fields they added. The form, the printed
     * document and the validator all read this, so there is one definition
     * rather than three that can drift.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function methodFor(int $organizationId, string $entity): array
    {
        $overrides = static::overridesFor($organizationId, $entity);
        $columns = [];

        foreach (static::builtinsFor($entity) as $key => $builtin) {
            $override = $overrides[$key] ?? null;

            $columns[] = [
                'key' => $key,
                'source' => 'builtin',
                'label' => $override?->label ?: $builtin['label'],
                'type' => $override && in_array('type', $builtin['can'], true) ? $override->type : $builtin['type'],
                'options' => $override?->options,
                'is_required' => (bool) $override?->is_required,
                'hidden' => (bool) $override?->is_hidden,
                'help' => $override?->help,
                'customised' => $override !== null,
            ];
        }

        foreach (static::approvedFor($organizationId, $entity) as $field) {
            $columns[] = [
                'key' => $field->key,
                'source' => 'custom',
                'label' => $field->label,
                'type' => $field->type,
                'options' => $field->options,
                'is_required' => (bool) $field->is_required,
                'hidden' => false,
                'help' => $field->help,
                'customised' => true,
            ];
        }

        return $columns;
    }

    /** This company's Work Order, column by column. */
    public static function workOrderMethod(int $organizationId): array
    {
        return static::methodFor($organizationId, 'work_order');
    }

    /** The document's own fields, as this company words them. */
    public static function invoiceMethod(int $organizationId): array
    {
        return static::methodFor($organizationId, 'invoice');
    }

    /**
     * This company's money lines: our six as they word them (renamed, given a
     * standing rate, or switched off), then any they added.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function taxSetup(int $organizationId): array
    {
        $overrides = static::overridesFor($organizationId, 'tax');
        $lines = [];

        foreach (static::BUILTIN_TAX as $key => $builtin) {
            $override = $overrides[$key] ?? null;
            if ($override?->is_hidden) {
                continue;
            }

            $lines[] = [
                'key' => $key,
                'source' => 'builtin',
                'label' => $override?->label ?: $builtin['label'],
                'kind' => $builtin['kind'],
                'basis' => $builtin['basis'],
                'default_rate' => $override?->default_rate !== null ? (float) $override->default_rate : null,
                'customised' => $override !== null,
            ];
        }

        foreach (static::approvedFor($organizationId, 'tax') as $field) {
            $lines[] = [
                'key' => $field->key,
                'source' => 'custom',
                'label' => $field->label,
                'kind' => $field->tax_kind ?: 'tax',
                'basis' => $field->tax_basis ?: 'taxable',
                'default_rate' => $field->default_rate !== null ? (float) $field->default_rate : null,
                'customised' => true,
            ];
        }

        return $lines;
    }

    /** The Laravel validation rule for this field's own value. */
    public function validationRule(): array
    {
        $rules = [$this->is_required && $this->type !== 'checkbox' ? 'required' : 'nullable'];

        return match ($this->type) {
            'number' => [...$rules, 'numeric'],
            'checkbox' => ['nullable', 'boolean'],
            'date' => [...$rules, 'date'],
            'alphanumeric' => [...$rules, 'alpha_num', 'max:255'],
            'select' => [...$rules, \Illuminate\Validation\Rule::in($this->options ?? [])],
            'textarea' => [...$rules, 'string', 'max:5000'],
            default => [...$rules, 'string', 'max:255'],
        };
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'entity' => $this->entity,
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->options,
            'is_required' => $this->is_required,
            'tax_kind' => $this->tax_kind,
            'tax_basis' => $this->tax_basis,
            'default_rate' => $this->default_rate,
            'is_builtin' => $this->is_builtin,
            'is_hidden' => $this->is_hidden,
            'help' => $this->help,
            'status' => $this->status,
            'reason' => $this->reason,
            'requested_by' => $this->requester?->user?->name,
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at?->toDateTimeString(),
            'decision_note' => $this->decision_note,
            /*
             * A change asked for and not yet allowed.
             *
             * Sent beside the live values rather than instead of them, so
             * both screens can say what the field is AND what it would
             * become — which is the only way "awaiting the Super Admin" is
             * readable rather than alarming.
             */
            'pending' => $this->pending,
            'pending_by' => $this->pendingRequester?->user?->name,
            'pending_at' => $this->pending_at?->toDateTimeString(),
            'organization' => $this->relationLoaded('organization')
                ? ['uuid' => $this->organization?->uuid, 'name' => $this->organization?->name]
                : null,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
