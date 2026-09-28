<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\InventoryItem;
use App\Models\Crm\InventoryMove;
use App\Models\Crm\IssuingCompany;
use App\Support\TextCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What each company sells, and what is left of it.
 *
 * The list is per issuing company, so the two arms of a business keep
 * their own shelves. A company that sells services keeps the same list
 * without the counting: a price and a tax rate are just as useful on an
 * invoice line whether or not anybody is counting what is left.
 *
 * Counts are never edited straight. Every change is a move with a reason
 * behind it, so "we are four short" has an answer.
 */
class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $query = InventoryItem::where('organization_id', $org->id)
            ->with('issuingCompany:id,name,sells,currency');

        if ($company = $request->query('issuing_company_id')) {
            $query->where('issuing_company_id', $company);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($request->filled('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        if ($request->boolean('low')) {
            $query->where('kind', 'product')->whereNotNull('reorder_at')
                ->whereColumn('quantity', '<=', 'reorder_at');
        }

        $all = (clone $query)->get(['id', 'kind', 'quantity', 'reorder_at', 'unit_price', 'is_active']);
        $counted = $all->where('kind', 'product');

        $summary = [
            'count' => $all->count(),
            'products' => $counted->count(),
            'services' => $all->where('kind', 'service')->count(),
            // What is on the shelf at what it sells for. Not a valuation -
            // that would want cost price, which nobody has been asked for.
            'retail_value' => round($counted->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_price), 2),
            'out_of_stock' => $counted->filter(fn ($i) => (float) $i->quantity <= 0)->count(),
            'low' => $counted->filter(fn ($i) => $i->reorder_at !== null && (float) $i->quantity <= (float) $i->reorder_at)->count(),
        ];

        // Alphabetical, because a price list is read by looking something
        // up rather than by scrolling to whatever was added last.
        $items = $query->orderBy('name')->paginate(50);
        $items->getCollection()->transform(fn (InventoryItem $i) => $this->serialize($i));

        return response()->json(['summary' => $summary] + $items->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $this->validated($request, $org->id);

        $item = InventoryItem::create($data + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        // An opening count is a move like any other, so the history starts
        // where the stock did rather than with an unexplained number.
        if ($item->isCounted() && (float) $item->quantity !== 0.0) {
            InventoryMove::create([
                'organization_id' => $org->id,
                'inventory_item_id' => $item->id,
                'qty' => $item->quantity,
                'reason' => 'adjustment',
                'note' => 'Opening count',
                'created_by' => $request->user()->id,
            ]);
        }

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'inventory.added', $item, [
            'name' => $item->name,
        ]);

        return response()->json(['message' => 'Added to the list.', 'data' => $this->serialize($item)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $item = $this->find($request, $uuid);
        $data = $this->validated($request, $org->id, $item);

        /*
         * The count is not edited here.
         *
         * Typing over it would throw away the reason it says what it says,
         * and the reasons are the point. Stock moves through /adjust, which
         * insists on knowing why.
         */
        unset($data['quantity']);

        $item->update($data);

        return response()->json(['message' => 'Saved.', 'data' => $this->serialize($item->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $item = $this->find($request, $uuid);

        /*
         * Sold at some point, so it stays.
         *
         * Deleting it would take the line off documents already raised, and
         * a document that has been sent is not ours to rewrite. It goes
         * inactive instead: off the pickers, still on the paperwork.
         */
        if ($item->moves()->whereNotNull('invoice_id')->exists()) {
            $item->update(['is_active' => false]);

            return response()->json([
                'message' => 'It has been sold before, so it is kept on those documents and switched off here.',
                'data' => $this->serialize($item->fresh()),
            ]);
        }

        $item->delete();

        return response()->json(['message' => 'Removed.']);
    }

    /**
     * A count, moved, with a reason.
     *
     * Either a change ("three more came in") or a correction to a figure
     * ("there are nine on the shelf, whatever the app thinks"), because
     * both are things people actually do, and a count nobody can correct
     * is one people stop believing.
     */
    public function adjust(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $item = $this->find($request, $uuid);

        abort_unless($item->isCounted(), 422, 'A service has nothing to count.');

        $data = $request->validate([
            'qty' => ['required_without:counted', 'nullable', 'numeric'],
            'counted' => ['required_without:qty', 'nullable', 'numeric', 'min:0'],
            'reason' => ['required', Rule::in(InventoryMove::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $move = DB::transaction(function () use ($item, $data, $org, $request) {
            $locked = InventoryItem::whereKey($item->id)->lockForUpdate()->first();

            $by = array_key_exists('counted', $data) && $data['counted'] !== null
                ? round((float) $data['counted'] - (float) $locked->quantity, 3)
                : round((float) $data['qty'], 3);

            $locked->forceFill(['quantity' => round((float) $locked->quantity + $by, 3)])->save();

            return InventoryMove::create([
                'organization_id' => $org->id,
                'inventory_item_id' => $locked->id,
                'qty' => $by,
                'reason' => $data['reason'],
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'inventory.adjusted', $item, [
            'name' => $item->name, 'by' => (float) $move->qty,
        ]);

        return response()->json([
            'message' => 'Count updated.',
            'data' => $this->serialize($item->fresh()),
        ]);
    }

    /** Why this count reads the way it does. */
    public function moves(Request $request, string $uuid): JsonResponse
    {
        $item = $this->find($request, $uuid);

        $moves = $item->moves()->with(['invoice:id,uuid,number', 'creator:id,name'])
            ->orderByDesc('id')->paginate(50);

        $moves->getCollection()->transform(fn (InventoryMove $m) => [
            'id' => $m->id,
            'qty' => (float) $m->qty,
            'reason' => $m->reason,
            'note' => $m->note,
            'invoice' => $m->invoice ? ['uuid' => $m->invoice->uuid, 'number' => $m->invoice->number] : null,
            'by' => $m->creator?->name,
            'at' => $m->created_at?->toDateTimeString(),
        ]);

        return response()->json($moves->toArray());
    }

    // ---- Helpers -----------------------------------------------------------

    private function find(Request $request, string $uuid): InventoryItem
    {
        return InventoryItem::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function validated(Request $request, int $orgId, ?InventoryItem $item = null): array
    {
        $data = $request->validate([
            'issuing_company_id' => [
                $item ? 'sometimes' : 'required',
                Rule::exists('crm_issuing_companies', 'id')->where('organization_id', $orgId),
            ],
            'name' => [$item ? 'sometimes' : 'required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', Rule::in(InventoryItem::KINDS)],
            'unit' => ['nullable', 'string', 'max:24'],
            'description' => ['nullable', 'string', 'max:1000'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'quantity' => ['nullable', 'numeric'],
            'reorder_at' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        /*
         * The name is left exactly as it was typed.
         *
         * House style restyles what people write, which is right for a
         * company name and wrong for a thing on a shelf: "HDMI cable 2m",
         * "M8x40 bolt", "iPhone 15 Pro". Nobody wants their part numbers
         * tidied up for them.
         */
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (array_key_exists('code', $data) && $data['code'] !== null) {
            $data['code'] = TextCase::code($data['code']);
        }

        /*
         * What the company says it sells is the default for what it lists.
         *
         * Not a rule - a services company may well sell a book at the back
         * of the room - but it is the right first answer, and it saves
         * saying "product" on every line of a price list.
         */
        if (! isset($data['kind'])) {
            $companyId = $data['issuing_company_id'] ?? $item?->issuing_company_id;
            $data['kind'] = IssuingCompany::find($companyId)?->sells === 'products' ? 'product' : 'service';
        }

        // A service is not counted, and a zero would read as "none left".
        if ($data['kind'] === 'service') {
            $data['quantity'] = null;
            $data['reorder_at'] = null;
        } elseif (! $item && ! isset($data['quantity'])) {
            $data['quantity'] = 0;
        }

        return $data;
    }

    private function serialize(InventoryItem $i): array
    {
        return [
            'uuid' => $i->uuid,
            // The numeric id as well, because an invoice line points at it
            // by id - the document is not going to look it up by uuid on
            // every save just to be tidy about it.
            'id' => $i->id,
            'name' => $i->name,
            'code' => $i->code,
            'kind' => $i->kind,
            'unit' => $i->unit,
            'description' => $i->description,
            'unit_price' => $i->unit_price,
            'tax_rate' => $i->tax_rate,
            'quantity' => $i->isCounted() ? (float) $i->quantity : null,
            'reorder_at' => $i->reorder_at === null ? null : (float) $i->reorder_at,
            'low' => $i->isLow(),
            'is_active' => $i->is_active,
            'issuing_company_id' => $i->issuing_company_id,
            'issuing_company' => $i->issuingCompany?->name,
            'currency' => $i->issuingCompany?->currency ?: 'INR',
        ];
    }
}
