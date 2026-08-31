<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Expense;
use App\Models\Crm\Vendor;
use App\Support\TextCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The vendor register — the supply side of the client book.
 *
 * A supplier is registered before any bill can be entered against them, so
 * spend is grouped under one record rather than under three spellings of a
 * name, and what the company still owes each of them is a real figure.
 */
class VendorController extends Controller
{
    /** What the company buys — the starting list, editable per company later. */
    public const CATEGORIES = [
        'Services', 'Goods', 'Software', 'Rent', 'Utilities',
        'Travel', 'Professional fees', 'Marketing', 'Other',
    ];

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $query = Vendor::where('organization_id', $org->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('gst_no', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        // What each supplier is owed, read from the bills themselves.
        $ledger = Expense::selectRaw('vendor_id, sum(total_amount) as billed, sum(amount_paid) as paid, count(*) as bills')
            ->where('organization_id', $org->id)
            ->whereNotNull('vendor_id')
            ->groupBy('vendor_id')
            ->get()
            ->keyBy('vendor_id');

        $overdue = Expense::selectRaw('vendor_id, count(*) as overdue_bills')
            ->where('organization_id', $org->id)
            ->whereNotNull('vendor_id')
            ->where('payment_status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->groupBy('vendor_id')
            ->get()
            ->keyBy('vendor_id');

        $vendors = $query->orderBy('company_name')->paginate(25);
        $vendors->getCollection()->transform(fn (Vendor $v) => $this->serialize($v, $ledger, $overdue));

        // Only the outstanding side is summarised — that is the question the
        // register exists to answer.
        $outstanding = round($ledger->sum(fn ($r) => (float) $r->billed - (float) $r->paid), 2);

        return response()->json([
            'summary' => [
                'vendors' => Vendor::where('organization_id', $org->id)->count(),
                'active' => Vendor::where('organization_id', $org->id)->where('status', 'active')->count(),
                'billed' => round($ledger->sum('billed'), 2),
                'outstanding' => $outstanding,
                'overdue_bills' => (int) $overdue->sum('overdue_bills'),
            ],
            'categories' => self::CATEGORIES,
        ] + $vendors->toArray());
    }

    /** Everyone a bill may be raised against — small payload for a dropdown. */
    public function options(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $vendors = Vendor::where('organization_id', $org->id)
            ->where('status', 'active')
            ->orderBy('company_name')
            ->get(['uuid', 'company_name', 'gst_no', 'payment_terms_days'])
            ->map(fn (Vendor $v) => [
                'uuid' => $v->uuid,
                'company_name' => $v->company_name,
                'gst_no' => $v->gst_no,
                'payment_terms_days' => $v->payment_terms_days,
            ]);

        return response()->json(['data' => $vendors, 'categories' => self::CATEGORIES]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $vendor = $this->find($request, $uuid);

        $bills = $vendor->expenses()
            ->orderByDesc('expense_date')->orderByDesc('id')
            ->limit(50)
            ->get(['uuid', 'expense_date', 'due_date', 'description', 'category', 'total_amount', 'amount_paid', 'payment_status']);

        // A different key from the serializer's own bill COUNT — a PHP array
        // union keeps the left side, so a clash would silently drop this.
        return response()->json(['data' => $this->serialize($vendor) + [
            'recent_bills' => $bills->map(fn (Expense $e) => [
                'uuid' => $e->uuid,
                'expense_date' => $e->expense_date->toDateString(),
                'due_date' => $e->due_date?->toDateString(),
                'description' => $e->description,
                'category' => $e->category,
                'total_amount' => $e->total_amount,
                'amount_paid' => $e->amount_paid,
                'balance' => $e->balance(),
                'payment_status' => $e->payment_status,
                'overdue' => $e->isOverdue(),
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $this->validated($request);

        // The same supplier twice under two spellings is the whole problem
        // the register solves, so it is refused at the door.
        if ($clash = $this->duplicate($org->id, $data, null)) {
            abort(422, 'A vendor is already registered as “' . $clash->company_name . '”. Open that record instead.');
        }

        $vendor = Vendor::create($data + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'vendor.created', $vendor, [
            'vendor' => $vendor->company_name,
        ]);

        return response()->json(['message' => 'Vendor registered.', 'data' => $this->serialize($vendor)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $vendor = $this->find($request, $uuid);
        $data = $this->validated($request);

        if ($clash = $this->duplicate($vendor->organization_id, $data, $vendor->id)) {
            abort(422, 'That name or GSTIN already belongs to “' . $clash->company_name . '”.');
        }

        $vendor->update($data);
        ActivityLog::record($request->attributes->get('crm_member'), $vendor->organization_id, 'vendor.updated', $vendor, [
            'vendor' => $vendor->company_name,
        ]);

        return response()->json(['message' => 'Vendor updated.', 'data' => $this->serialize($vendor->fresh())]);
    }

    /**
     * A supplier with history is never deleted — the bills would lose the
     * name they were paid under. It is retired instead.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $vendor = $this->find($request, $uuid);
        $bills = $vendor->expenses()->count();

        if ($bills > 0) {
            $vendor->update(['status' => 'inactive']);
            ActivityLog::record($request->attributes->get('crm_member'), $vendor->organization_id, 'vendor.retired', $vendor, [
                'vendor' => $vendor->company_name, 'bills' => $bills,
            ]);

            return response()->json([
                'message' => $vendor->company_name . ' has ' . $bills . ' bill' . ($bills === 1 ? '' : 's')
                    . ' on record, so it was marked inactive instead of deleted.',
                'retired' => true,
            ]);
        }

        ActivityLog::record($request->attributes->get('crm_member'), $vendor->organization_id, 'vendor.deleted', $vendor, [
            'vendor' => $vendor->company_name,
        ]);
        $vendor->delete();

        return response()->json(['message' => 'Vendor removed.', 'retired' => false]);
    }

    // ---- Helpers -------------------------------------------------------------

    private function find(Request $request, string $uuid): Vendor
    {
        return Vendor::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    private function duplicate(int $orgId, array $data, ?int $exceptId): ?Vendor
    {
        $key = Vendor::matchKey($data['company_name'] ?? null);
        $gst = $data['gst_no'] ?? null;

        return Vendor::where('organization_id', $orgId)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get()
            ->first(fn (Vendor $v) => Vendor::matchKey($v->company_name) === $key
                || ($gst && $v->gst_no && strcasecmp($v->gst_no, $gst) === 0));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:512'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'pincode' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:128'],
            'telephone' => ['nullable', 'string', 'max:64'],
            'mobile' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'gst_no' => ['nullable', 'string', 'max:32'],
            'pan_no' => ['nullable', 'string', 'max:16'],
            'category' => ['nullable', 'string', 'max:64'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'bank_name' => ['nullable', 'string', 'max:128'],
            'bank_account_no' => ['nullable', 'string', 'max:64'],
            'bank_ifsc' => ['nullable', 'string', 'max:32'],
            'bank_branch' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:1024'],
        ]);

        // House casing, the same treatment a client's details get.
        $data['company_name'] = TextCase::company($data['company_name']);
        foreach (['contact_person', 'city', 'state', 'country', 'bank_name', 'bank_branch'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = TextCase::name($data[$field]);
            }
        }
        foreach (['gst_no', 'pan_no', 'bank_ifsc'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = TextCase::code($data[$field]);
            }
        }
        if (array_key_exists('email', $data)) {
            $data['email'] = TextCase::email($data['email']);
        }
        return $data;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, mixed>|null  $ledger
     * @param  \Illuminate\Support\Collection<int, mixed>|null  $overdue
     * @return array<string, mixed>
     */
    private function serialize(Vendor $v, $ledger = null, $overdue = null): array
    {
        $row = $ledger?->get($v->id);
        $billed = $row ? (float) $row->billed : (float) $v->expenses()->sum('total_amount');
        $paid = $row ? (float) $row->paid : (float) $v->expenses()->sum('amount_paid');

        return [
            'uuid' => $v->uuid,
            'company_name' => $v->company_name,
            'contact_person' => $v->contact_person,
            'designation' => $v->designation,
            'address' => $v->address,
            'city' => $v->city,
            'state' => $v->state,
            'pincode' => $v->pincode,
            'country' => $v->country,
            'telephone' => $v->telephone,
            'mobile' => $v->mobile,
            'email' => $v->email,
            'website' => $v->website,
            'gst_no' => $v->gst_no,
            'pan_no' => $v->pan_no,
            'category' => $v->category,
            'payment_terms_days' => $v->payment_terms_days,
            'bank_name' => $v->bank_name,
            'bank_account_no' => $v->bank_account_no,
            'bank_ifsc' => $v->bank_ifsc,
            'bank_branch' => $v->bank_branch,
            'status' => $v->status,
            'notes' => $v->notes,
            'bills' => $row ? (int) $row->bills : $v->expenses()->count(),
            'billed' => round($billed, 2),
            'paid' => round($paid, 2),
            'outstanding' => round($billed - $paid, 2),
            'overdue_bills' => (int) ($overdue?->get($v->id)?->overdue_bills ?? 0),
            'created_at' => $v->created_at?->toDateTimeString(),
        ];
    }
}
