<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Asset;
use App\Models\Crm\AssetEvent;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Office Assets register: everything the company hands out, for life.
 *
 * One window answers it all — what is allocated to whom, what came back and
 * when, what stock is left, what is damaged. New items land in stock; an
 * allocation puts them in someone's hands; a return brings them back (or
 * marks them damaged); a beyond-repair item can be removed, its history
 * kept in the trail. Admin/Subadmin manage; an employee sees their own.
 */
class AssetController extends Controller
{
    private function manages(Member $me): bool
    {
        return in_array($me->crm_role, ['admin', 'subadmin'], true);
    }

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Asset::with(['holder.user:id,name'])
            ->where('organization_id', $org->id);

        // An employee's window is their own kit; a Team Workspace leader
        // sees their people's too (filterable); managers see the store.
        if (! $this->manages($me) && ! $me->can('assets', 'view')) {
            $teamIds = $me->teamMemberIds();
            count($teamIds) > 1
                ? $query->whereIn('allocated_to_member_id', $teamIds)
                : $query->where('allocated_to_member_id', $me->id);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($memberUuid = $request->query('member')) {
            $query->whereHas('holder', fn ($m) => $m->where('uuid', $memberUuid));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('model_no', 'like', "%{$search}%")
                ->orWhere('serial_no', 'like', "%{$search}%"));
        }

        $all = Asset::where('organization_id', $org->id)->get(['status']);

        return response()->json([
            'data' => $query->orderByDesc('id')->get()->map(fn ($a) => $this->serialize($a)),
            'summary' => [
                'total' => $all->count(),
                'in_stock' => $all->where('status', 'in_stock')->count(),
                'allocated' => $all->where('status', 'allocated')->count(),
                'damaged' => $all->where('status', 'damaged')->count(),
            ],
            'categories' => Asset::CATEGORIES,
            'manages' => $this->manages($me),
            'can_delete' => $me->crm_role === 'admin' || ($this->manages($me) && $me->can('assets', 'delete')),
        ]);
    }

    /** One's own kit — every employee may see what they hold. */
    public function mine(Request $request): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        return response()->json(['data' => Asset::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('allocated_to_member_id', $me->id)
            ->orderBy('category')->get()->map(fn ($a) => $this->serialize($a))]);
    }

    /** The assets in one employee's hands — for the User section card. */
    public function forMember(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = Member::where('organization_id', $org->id)->where('uuid', $memberUuid)->firstOrFail();

        // Their own kit, their leader's window, or a manager — nobody else.
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(
            $member->id === $me->id
            || $this->manages($me)
            || in_array($member->id, $me->teamMemberIds(), true),
            403,
            'Another member’s assets are visible to their leader or a manager only.',
        );

        return response()->json(['data' => Asset::where('organization_id', $org->id)
            ->where('allocated_to_member_id', $member->id)
            ->orderBy('category')->get()->map(fn ($a) => $this->serialize($a))]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($this->manages($me), 403, 'Registering assets is the Admin’s or a Subadmin’s.');

        $data = $request->validate([
            'category' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'model_no' => ['nullable', 'string', 'max:128'],
            'color' => ['nullable', 'string', 'max:64'],
            'serial_no' => ['nullable', 'string', 'max:128'],
            'details' => ['nullable', 'string', 'max:512'],
            'purchased_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:512'],
            // Bulk stock: ten chargers arrive as ten rows, one entry.
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $qty = (int) ($data['quantity'] ?? 1);
        unset($data['quantity']);

        $made = [];
        for ($i = 0; $i < $qty; $i++) {
            $asset = Asset::create($data + [
                'organization_id' => $org->id,
                'status' => 'in_stock',
                'created_by' => $request->user()->id,
            ]);
            AssetEvent::create([
                'asset_id' => $asset->id, 'action' => 'created',
                'note' => $qty > 1 ? 'Bulk entry of ' . $qty : null,
                'created_by' => $request->user()->id,
            ]);
            $made[] = $asset;
        }

        ActivityLog::record($me, $org->id, 'asset.registered', $made[0], [
            'item' => $data['name'], 'category' => $data['category'], 'quantity' => $qty,
        ]);

        return response()->json([
            'message' => $qty . ' item' . ($qty === 1 ? '' : 's') . ' added to stock.',
            'data' => collect($made)->map(fn ($a) => $this->serialize($a)),
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($this->manages($me), 403, 'Editing assets is the Admin’s or a Subadmin’s.');

        $asset = $this->find($request, $uuid);
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'model_no' => ['nullable', 'string', 'max:128'],
            'color' => ['nullable', 'string', 'max:64'],
            'serial_no' => ['nullable', 'string', 'max:128'],
            'details' => ['nullable', 'string', 'max:512'],
            'purchased_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);
        $asset->update($data);

        return response()->json(['message' => 'Asset updated.', 'data' => $this->serialize($asset->fresh())]);
    }

    /** Put an in-stock item in someone's hands. */
    public function allocate(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($this->manages($me), 403, 'Allocating assets is the Admin’s or a Subadmin’s.');

        $asset = $this->find($request, $uuid);
        abort_unless($asset->status === 'in_stock', 422, 'Only an in-stock item can be allocated.');

        $data = $request->validate([
            'member_uuid' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);
        $member = Member::with('user:id,name')->where('organization_id', $org->id)
            ->where('uuid', $data['member_uuid'])->firstOrFail();

        $asset->update([
            'status' => 'allocated',
            'allocated_to_member_id' => $member->id,
            'allocated_at' => now(),
        ]);
        AssetEvent::create([
            'asset_id' => $asset->id, 'action' => 'allocated', 'member_id' => $member->id,
            'note' => $data['note'] ?? null, 'created_by' => $request->user()->id,
        ]);
        ActivityLog::record($me, $org->id, 'asset.allocated', $asset, [
            'item' => $asset->name, 'to' => $member->user?->name,
        ]);

        return response()->json(['message' => $asset->name . ' allocated to ' . $member->user?->name . '.']);
    }

    /** Take an item back — into stock, or marked damaged on arrival. */
    public function returnAsset(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($this->manages($me), 403, 'Recording a return is the Admin’s or a Subadmin’s.');

        $asset = $this->find($request, $uuid);
        abort_unless($asset->status === 'allocated', 422, 'Only an allocated item can be returned.');

        $data = $request->validate([
            'damaged' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);
        $holder = $asset->holder;
        $damaged = (bool) ($data['damaged'] ?? false);

        $asset->update([
            'status' => $damaged ? 'damaged' : 'in_stock',
            'allocated_to_member_id' => null,
            'allocated_at' => null,
        ]);
        AssetEvent::create([
            'asset_id' => $asset->id, 'action' => $damaged ? 'damaged' : 'returned',
            'member_id' => $holder?->id, 'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        ActivityLog::record($me, $org->id, $damaged ? 'asset.returned_damaged' : 'asset.returned', $asset, [
            'item' => $asset->name, 'from' => $holder?->user?->name,
        ]);

        return response()->json(['message' => $asset->name . ($damaged
            ? ' returned DAMAGED — it sits aside until repaired or removed.'
            : ' back in stock, available to allocate.')]);
    }

    /** A damaged item fixed: back into stock. */
    public function repaired(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($this->manages($me), 403, 'This is the Admin’s or a Subadmin’s.');

        $asset = $this->find($request, $uuid);
        abort_unless($asset->status === 'damaged', 422, 'Only a damaged item can be repaired.');

        $asset->update(['status' => 'in_stock']);
        AssetEvent::create(['asset_id' => $asset->id, 'action' => 'repaired', 'created_by' => $request->user()->id]);

        return response()->json(['message' => $asset->name . ' repaired — back in stock.']);
    }

    /** Beyond repair: remove it from the register (the trail keeps it). */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(
            $me->crm_role === 'admin' || ($me->crm_role === 'subadmin' && $me->can('assets', 'delete')),
            403,
            'Removing an asset is the Admin’s — or a Subadmin with the delete right.',
        );

        $asset = $this->find($request, $uuid);
        abort_unless($asset->status !== 'allocated', 422, 'Take it back from its holder first.');

        ActivityLog::record($me, $org->id, 'asset.removed', $asset, [
            'item' => $asset->name, 'serial_no' => $asset->serial_no, 'status' => $asset->status,
        ]);
        $asset->delete();

        return response()->json(['message' => 'Asset removed from the register. Its history stays on the trail.']);
    }

    /** The item's whole life, event by event. */
    public function history(Request $request, string $uuid): JsonResponse
    {
        $asset = $this->find($request, $uuid);
        $asset->load('events.member.user:id,name', 'events.creator:id,name');

        return response()->json(['data' => $asset->events->map(fn (AssetEvent $e) => [
            'action' => $e->action,
            'member' => $e->member?->user?->name,
            'note' => $e->note,
            'by' => $e->creator?->name,
            'at' => $e->created_at?->toDateTimeString(),
        ])]);
    }

    private function find(Request $request, string $uuid): Asset
    {
        return Asset::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)->firstOrFail();
    }

    private function serialize(Asset $a): array
    {
        return [
            'uuid' => $a->uuid,
            'category' => $a->category,
            'name' => $a->name,
            'model_no' => $a->model_no,
            'color' => $a->color,
            'serial_no' => $a->serial_no,
            'details' => $a->details,
            'status' => $a->status,
            'holder' => $a->holder ? ['uuid' => $a->holder->uuid, 'name' => $a->holder->user?->name] : null,
            'allocated_at' => $a->allocated_at?->toDateTimeString(),
            'purchased_on' => $a->purchased_on?->toDateString(),
            'note' => $a->note,
        ];
    }
}
