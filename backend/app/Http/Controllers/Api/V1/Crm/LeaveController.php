<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Notifications\CrmNotification;
use App\Services\Crm\LeaveAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Leave Approvals. Anyone requests their own leave (category, full or half
 * day, date span); deciding needs the leaves module right. Days are computed
 * from the span times the duration factor — never typed.
 *
 * Approval is also an accounting act: the days come out of the member's
 * paid-leave account, and whatever the balance cannot cover is still leave
 * but is not paid for. The punch calendar then shows those days as Leave
 * rather than Absent, which is the whole point of asking first.
 */
class LeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request)->with(['member.user:id,name', 'decider.user:id,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($member = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $member));
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('date_from', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('date_to', '<=', $to);
        }

        // Chart feed over the whole filtered range.
        $all = (clone $query)->get(['id', 'category', 'status', 'days', 'member_id']);
        $summary = [
            'pending' => $all->where('status', 'pending')->count(),
            'approved_days' => round($all->where('status', 'approved')->sum('days'), 2),
            'by_category' => $all->where('status', '!=', 'cancelled')
                ->groupBy('category')
                ->map(fn ($g, $cat) => ['category' => $cat, 'days' => round($g->sum('days'), 2), 'count' => $g->count()])
                ->sortByDesc('days')->values(),
            'by_status' => collect(Leave::STATUSES)
                ->map(fn ($s) => ['status' => $s, 'count' => $all->where('status', $s)->count()])
                ->filter(fn ($s) => $s['count'] > 0)->values(),
        ];

        $leaves = $query->orderByDesc('id')->paginate(25);
        $leaves->getCollection()->transform(fn ($l) => $this->serialize($l));

        // What the person reading this actually has left to spend.
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $summary['account'] = (new LeaveAccount($org))->statement($me);

        return response()->json(['summary' => $summary] + $leaves->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'category' => ['required', 'string', 'max:64'],
            'duration' => ['required', Rule::in(array_keys(Leave::DURATIONS))],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $span = Carbon::parse($data['date_from'])->diffInDays(Carbon::parse($data['date_to'])) + 1;
        $days = round($span * Leave::DURATIONS[$data['duration']], 2);

        $leave = Leave::create($data + [
            'organization_id' => $org->id,
            'member_id' => $me->id,
            'days' => $days,
        ]);

        // Said now, rather than discovered on the payslip.
        $balance = (new LeaveAccount($org))->balance($me);
        $warning = '';
        if ($me->onProbation((int) $org->hrPolicy()['probation_days'])) {
            $warning = ' You are still on probation, so this will be unpaid leave.';
        } elseif ($balance < $days) {
            $warning = ' Your account holds ' . $balance . ' day(s), so '
                . round($days - $balance, 2) . ' of this would be unpaid.';
        }

        Notification::send(
            Member::deciders($org->id, 'leaves', $me->id),
            new CrmNotification(
                'crm_leave',
                ($me->user?->name ?? 'Someone') . ' requested ' . $days . ' day(s) ' . $data['category']
                    . ' (' . $leave->date_from->format('d M') . ($leave->date_to->ne($leave->date_from) ? ' → ' . $leave->date_to->format('d M') : '') . ').',
                '/crm/leaves?status=pending',
            ),
        );

        return response()->json([
            'message' => 'Leave requested — ' . $days . ' day(s), awaiting approval.' . $warning,
            'data' => $this->serialize($leave->load('member.user:id,name')),
        ], 201);
    }

    public function decide(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $leave = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        if ($leave->status !== 'pending') {
            abort(422, 'This request was already decided.');
        }
        if ($leave->member_id === $me->id) {
            abort(422, 'You cannot decide your own leave request.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $leave->update([
            'status' => $data['status'],
            'decided_by' => $me->id,
            'decided_at' => now(),
            'decision_note' => $data['note'] ?? null,
        ]);

        // Approving is also an accounting act: the days leave the account,
        // and what the balance could not cover is named as unpaid.
        $split = ['paid' => 0.0, 'unpaid' => 0.0];
        if ($data['status'] === 'approved') {
            $split = (new LeaveAccount($request->attributes->get('crm_org')))
                ->spend($leave->fresh()->load('member'), $request->user()->id);
        }
        $unpaidLine = $split['unpaid'] > 0
            ? ' ' . $split['unpaid'] . ' day(s) of it are unpaid — the account did not cover them.'
            : '';

        ActivityLog::record($me, $leave->organization_id, 'leave.' . $data['status'], $leave, array_filter([
            'employee' => $leave->member?->user?->name,
            'category' => $leave->category,
            'dates' => $leave->date_from->toDateString()
                . ($leave->date_to->ne($leave->date_from) ? ' → ' . $leave->date_to->toDateString() : ''),
            'days' => (float) $leave->days,
            'unpaid_days' => $split['unpaid'] > 0 ? $split['unpaid'] : null,
            'note' => $data['note'] ?? null,
        ]));

        if ($leave->member?->user) {
            $leave->member->user->notify(new CrmNotification(
                'crm_leave',
                'Your ' . $leave->category . ' (' . $leave->date_from->format('d M') . ') was '
                    . $data['status'] . ' by ' . ($me->user?->name ?? 'a manager')
                    . (($data['note'] ?? null) ? ' — "' . $data['note'] . '"' : '') . '.' . $unpaidLine,
                '/crm/leaves',
            ));
        }

        return response()->json([
            'message' => 'Leave ' . $data['status'] . '.' . $unpaidLine,
            'data' => $this->serialize($leave->fresh()->load(['member.user:id,name', 'decider.user:id,name'])),
        ]);
    }

    /** The requester withdraws a pending request. */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $leave = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        if ($leave->member_id !== $me->id && ! $me->can('leaves', 'delete')) {
            abort(403, 'Only the requester can withdraw this.');
        }
        // A manager may pull back an approval; the days go back with it.
        if ($leave->status === 'approved') {
            abort_unless($me->can('leaves', 'edit'), 422, 'Only a pending request can be withdrawn.');
            (new LeaveAccount($request->attributes->get('crm_org')))->refund($leave);
        } elseif ($leave->status !== 'pending') {
            abort(422, 'Only a pending request can be withdrawn.');
        }

        $wasApproved = $leave->status === 'approved';
        $leave->update(['status' => 'cancelled']);

        // Pulling back an APPROVED leave rewrites the attendance calendar —
        // the day becomes absence again — so it must be on the trail.
        ActivityLog::record($me, $leave->organization_id, $wasApproved ? 'leave.approval_withdrawn' : 'leave.cancelled', $leave, array_filter([
            'employee' => $leave->member?->user?->name,
            'category' => $leave->category,
            'dates' => $leave->date_from->toDateString(),
            'days' => (float) $leave->days,
            'days_refunded' => $wasApproved ? (float) $leave->days : null,
        ]));

        return response()->json(['message' => 'Leave request withdrawn.']);
    }

    private function scoped(Request $request): Builder
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Leave::where('organization_id', $org->id);

        $seesAll = in_array($me->crm_role, ['admin', 'subadmin'], true) || $me->can('leaves', 'view');
        if (! $seesAll) {
            // Team Heads see their subtree's requests, not just their own.
            $query->whereIn('member_id', $me->teamMemberIds());
        }

        return $query;
    }

    private function serialize(Leave $l): array
    {
        return [
            'uuid' => $l->uuid,
            'member' => $l->member ? ['uuid' => $l->member->uuid, 'name' => $l->member->user?->name] : null,
            'category' => $l->category,
            'duration' => $l->duration,
            'date_from' => $l->date_from->toDateString(),
            'date_to' => $l->date_to->toDateString(),
            'days' => $l->days,
            'paid_days' => $l->paid_days,
            'unpaid_days' => $l->unpaid_days,
            'reason' => $l->reason,
            'status' => $l->status,
            'decided_by' => $l->decider?->user?->name,
            'decided_at' => $l->decided_at?->toDateTimeString(),
            'decision_note' => $l->decision_note,
            'created_at' => $l->created_at?->toDateTimeString(),
        ];
    }
}
