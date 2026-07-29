<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bills = Bill::visibleTo($request->user())
            ->with('group:id,uuid,name')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("status = 'paid'")
            ->orderBy('due_on')
            ->paginate(50);

        $bills->getCollection()->transform(fn ($bill) => $this->serialize($bill, $request));

        return response()->json($bills);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $bill = Bill::create($data + ['user_id' => $request->user()->id]);

        return response()->json([
            'message' => 'Bill added.',
            'data' => $this->serialize($bill->load('group:id,uuid,name'), $request),
        ], 201);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($bill->user_id === $request->user()->id, 403);

        $bill->update($this->validated($request, $bill));

        return response()->json([
            'message' => 'Bill updated.',
            'data' => $this->serialize($bill->fresh()->load('group:id,uuid,name'), $request),
        ]);
    }

    public function destroy(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($bill->user_id === $request->user()->id, 403);

        $bill->delete();

        return response()->json(['message' => 'Bill deleted.']);
    }

    /** Mark paid; recurring bills spawn the next unpaid occurrence. */
    public function markPaid(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($bill->user_id === $request->user()->id, 403);
        abort_if($bill->status === 'paid', 409, 'This bill is already paid.');

        $bill->update(['status' => 'paid', 'paid_at' => now()]);

        $next = null;
        if ($nextDue = $bill->nextDueDate()) {
            $next = Bill::create([
                'user_id' => $bill->user_id,
                'group_id' => $bill->group_id,
                'name' => $bill->name,
                'category' => $bill->category,
                'amount' => $bill->amount,
                'currency' => $bill->currency,
                'due_on' => $nextDue,
                'repeat_frequency' => $bill->repeat_frequency,
                'payment_account' => $bill->payment_account,
                'remind_days_before' => $bill->remind_days_before,
                'notes' => $bill->notes,
            ]);
        }

        return response()->json([
            'message' => $next
                ? 'Bill marked paid. Next occurrence created for ' . $next->due_on->toFormattedDateString() . '.'
                : 'Bill marked paid.',
            'data' => $this->serialize($bill->fresh()->load('group:id,uuid,name'), $request),
            'next' => $next ? $this->serialize($next, $request) : null,
        ]);
    }

    protected function validated(Request $request, ?Bill $bill = null): array
    {
        $data = $request->validate([
            'name' => [$bill ? 'sometimes' : 'required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'due_on' => [$bill ? 'sometimes' : 'required', 'date'],
            'repeat_frequency' => ['nullable', 'in:' . implode(',', Bill::FREQUENCIES)],
            'payment_account' => ['nullable', 'string', 'max:255'],
            'remind_days_before' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'group_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (array_key_exists('group_uuid', $data)) {
            $group = $data['group_uuid']
                ? Group::withMember($request->user())->where('uuid', $data['group_uuid'])->firstOrFail()
                : null;
            $data['group_id'] = $group?->id;
            unset($data['group_uuid']);
        }

        return $data;
    }

    protected function serialize(Bill $bill, Request $request): array
    {
        return [
            'uuid' => $bill->uuid,
            'name' => $bill->name,
            'category' => $bill->category,
            'amount' => $bill->amount,
            'currency' => $bill->currency,
            'due_on' => $bill->due_on->toDateString(),
            'status' => $bill->status,
            'is_overdue' => $bill->status === 'unpaid' && $bill->due_on->isPast() && ! $bill->due_on->isToday(),
            'repeat_frequency' => $bill->repeat_frequency,
            'payment_account' => $bill->payment_account,
            'remind_days_before' => $bill->remind_days_before,
            'notes' => $bill->notes,
            'is_own' => $bill->user_id === $request->user()->id,
            'group' => $bill->group ? ['uuid' => $bill->group->uuid, 'name' => $bill->group->name] : null,
            'paid_at' => $bill->paid_at,
        ];
    }
}
