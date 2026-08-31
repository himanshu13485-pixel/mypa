<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\IncentiveHold;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\SalarySlip;
use App\Services\Crm\IncentiveCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Incentives ledger: every sale's incentive schedule, client by client.
 *
 * A payslip shows one number; this screen shows where it came from and
 * where it is going — which months of which client's run have been paid,
 * which are upcoming, which are held, which are gone. An employee with
 * fifty clients has fifty schedules side by side here, and can see exactly
 * what next month's payroll will bring.
 *
 * Admins rule on individual runs: hold one month (it pays next month as an
 * arrear, automatically), hold all remaining (pays out whenever released),
 * or cancel outright (regainable — future months resume, the cancelled
 * months are gone). Every ruling is on the trail.
 */
class IncentiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $member = $this->target($request, $me);
        $calc = new IncentiveCalculator($org);
        $plan = $calc->planFor($member, now());

        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);
        $offset = max(0, (int) ($plan?->release_offset_months ?? 1));

        // What next month's payroll will bring — the number the employee
        // wants to know without asking anyone.
        $nextPayroll = now()->addMonthNoOverflow()->startOfMonth();
        $nextAnchor = $nextPayroll->copy()->subMonthsNoOverflow($offset);
        $next = $plan && $plan->kind !== 'none'
            ? $calc->compute($member, $nextAnchor)
            : null;

        // Between months: each month's own figure over a span, so two or
        // more months can be checked side by side.
        $monthsRange = [];
        $fromQ = $request->query('month_from');
        $toQ = $request->query('month_to');
        if ($plan && $plan->kind !== 'none' && $fromQ && $toQ && $toQ >= $fromQ) {
            $cursor = Carbon::parse($fromQ . '-01');
            $stop = Carbon::parse($toQ . '-01');
            $guard = 0;
            $slipMonths = SalarySlip::where('member_id', $member->id)
                ->get(['year', 'month', 'status'])
                ->keyBy(fn ($s) => sprintf('%04d-%02d', $s->year, $s->month));
            while ($cursor->lte($stop) && $guard++ < 24) {
                $result = $calc->compute($member, $cursor->copy());
                $payroll = $cursor->copy()->addMonthsNoOverflow($offset)->format('Y-m');
                $slip = $slipMonths[$payroll] ?? null;
                $monthsRange[] = [
                    'earned_month' => $cursor->format('Y-m'),
                    'payroll_month' => $payroll,
                    'total' => $result['total'],
                    'arrear_total' => $result['arrear_total'] ?? 0,
                    'recovery_total' => $result['recovery_total'] ?? 0,
                    'installments' => count($result['installments'] ?? []),
                    'status' => $slip
                        ? ($slip->status === 'paid' ? 'paid' : 'on_slip')
                        : ($payroll <= now()->format('Y-m') ? 'due' : 'upcoming'),
                ];
                $cursor->addMonthNoOverflow();
            }
        }

        $payload = [
            'member' => ['uuid' => $member->uuid, 'name' => $member->user?->name],
            'plan' => $plan?->kind ?? 'none',
            'plan_config' => $plan?->config ?? (object) [],
            'release_offset_months' => $offset,
            'manages' => $manages,
            'next_month' => $next ? [
                'payroll_month' => $nextPayroll->format('Y-m'),
                'earned_month' => $nextAnchor->format('Y-m'),
                'total' => $next['total'],
                'arrear_total' => $next['arrear_total'] ?? 0,
            ] : null,
            'rows' => [],
            'recent' => [],
            'months' => $monthsRange,
        ];

        if (! $plan || $plan->kind === 'none') {
            return response()->json(['data' => $payload]);
        }

        if ($plan->kind !== 'spread') {
            // One-go plans have no schedule — show the recent month totals
            // so the tab still answers "what did I earn?".
            $payload['recent'] = collect(range(5, 0))->map(function ($back) use ($calc, $member) {
                $month = now()->subMonthsNoOverflow($back);
                $result = $calc->compute($member, $month);

                return [
                    'earned_month' => $month->format('Y-m'),
                    'total' => $result['total'],
                ];
            })->values();

            return response()->json(['data' => $payload]);
        }

        // ---- The client-wise ledger ---------------------------------------
        $config = $plan->config ?? [];
        // Anchored at today: every sale still inside its run, plus a little
        // history so finished runs stay readable.
        $sales = $calc->spreadSales($member, now()->startOfMonth(), $config);

        $holds = IncentiveHold::with('creator:id,name')
            ->where('member_id', $member->id)
            ->whereIn('invoice_id', $sales->pluck('invoice_id'))
            ->orderBy('id')
            ->get()
            ->groupBy('invoice_id');

        // Which payroll months already have a slip, and its status.
        $slips = SalarySlip::where('member_id', $member->id)
            ->get(['year', 'month', 'status'])
            ->keyBy(fn ($s) => sprintf('%04d-%02d', $s->year, $s->month));

        $gate = $calc->paymentGate($member);
        $payload['rows'] = $sales->map(function (array $sale) use ($holds, $slips, $calc, $offset, $manages, $gate) {
            $rowHolds = $holds[$sale['invoice_id']] ?? collect();
            $schedule = [];
            $startFrom = $gate ? max($sale['paid_month'] ?? '9999-99', $sale['sale_month']) : $sale['sale_month'];

            // Each run keeps the length its OWN vintage promised — an old
            // 24-month sale finishes at 24 even after the plan moved to 12.
            for ($k = 1; $k <= $sale['months']; $k++) {
                $anchor = Carbon::parse($sale['sale_month'] . '-01')
                    ->addMonthsNoOverflow($k - 1)->format('Y-m');
                $payroll = Carbon::parse($anchor . '-01')
                    ->addMonthsNoOverflow($offset)->format('Y-m');

                $status = 'upcoming';
                $paysAt = null;
                $blocking = $rowHolds->first(fn (IncentiveHold $h) => $h->blocks($anchor));
                if ($gate && ! $sale['paid']) {
                    // The whole run waits for the client's money. A cancel
                    // still shows as cancelled — it outranks the wait.
                    $status = $blocking?->kind === 'cancel' ? 'cancelled' : 'awaiting_payment';
                } elseif ($gate && $anchor < $startFrom && ! $blocking) {
                    // Months that waited: they release with the payment.
                    $status = 'arrear';
                    $paysAt = Carbon::parse($startFrom . '-01')
                        ->addMonthsNoOverflow($offset)->format('Y-m');
                } elseif ($blocking) {
                    if ($blocking->kind === 'cancel') {
                        $status = 'cancelled';
                    } else {
                        $release = $calc->holdReleaseMonth($blocking);
                        $status = 'held';
                        // A one-month hold already knows when it pays out.
                        if ($release !== null) {
                            $status = 'arrear';
                            $paysAt = Carbon::parse($release . '-01')
                                ->addMonthsNoOverflow($offset)->format('Y-m');
                        }
                    }
                } else {
                    $slip = $slips[$payroll] ?? null;
                    if ($slip) {
                        $status = $slip->status === 'paid' ? 'paid' : 'on_slip';
                    } elseif ($payroll <= now()->format('Y-m')) {
                        $status = 'due';       // payroll month passed, no slip yet
                    }
                }

                $schedule[] = [
                    'number' => $k,
                    'earned_month' => $anchor,
                    'payroll_month' => $payroll,
                    'amount' => $sale['installment'],
                    'status' => $status,
                    'pays_at' => $paysAt,
                ];
            }

            $activeHold = $rowHolds->first(fn (IncentiveHold $h) => $h->status === 'active' && $h->only_month === null);

            return [
                'invoice_id' => $sale['invoice_id'],
                'invoice_uuid' => $sale['invoice_uuid'],
                'invoice_no' => $sale['invoice_no'],
                'client' => $sale['client'],
                // Team rows: a teammate's sale paying the leader's team
                // percent — labelled with whose desk sold it.
                'team' => (bool) ($sale['team'] ?? false),
                'seller' => $sale['seller'] ?? null,
                // The remark when the Team Workspace access behind this row
                // was withdrawn — the run itself finishes its term.
                'withdrawn_month' => $sale['withdrawn_month'] ?? null,
                'sale_month' => $sale['sale_month'],
                'total' => $sale['total'],
                'costs' => $sale['costs'],
                'tds' => $sale['tds'],
                'effective' => $sale['effective'],
                'payment_status' => $sale['payment_status'],
                'awaiting_payment' => $gate && ! $sale['paid'],
                // Each row's own vintage terms, so a rate change reads
                // honestly: old runs at the old percent, new at the new.
                'percent' => $sale['percent'],
                'months' => $sale['months'],
                'pool' => $sale['pool'],
                'installment' => $sale['installment'],
                'paid_so_far' => round(collect($schedule)->where('status', 'paid')->sum('amount'), 2),
                'schedule' => $schedule,
                'hold' => $activeHold ? [
                    'uuid' => $activeHold->uuid,
                    'kind' => $activeHold->kind,
                    'from_month' => $activeHold->from_month,
                    'note' => $activeHold->note,
                    'by' => $manages ? $activeHold->creator?->name : null,
                ] : null,
            ];
        })->values();

        return response()->json(['data' => $payload]);
    }

    /** Stop one sale's incentive: this month, all remaining, or for good. */
    public function hold(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'Holding an incentive is the Admin’s or a Subadmin’s.');

        $data = $request->validate([
            'member_uuid' => ['required', 'string'],
            'invoice_uuid' => ['required', 'string'],
            // once = only this month (pays next month as an arrear on its
            // own); remaining = until released; cancel = gone, regainable.
            'scope' => ['required', Rule::in(['once', 'remaining', 'cancel'])],
            'month' => ['required', 'date_format:Y-m'],
            // Cancel only: the sale came back in full, so the installments
            // already paid come back too — as a minus on the next slip.
            'recover' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $member = Member::with('user:id,name')->where('organization_id', $org->id)
            ->where('uuid', $data['member_uuid'])->firstOrFail();
        $invoice = Invoice::with('client:id,company_name')
            ->where('organization_id', $org->id)
            ->where('uuid', $data['invoice_uuid'])->firstOrFail();

        // One active ruling per sale — release the standing one first.
        $standing = IncentiveHold::where('invoice_id', $invoice->id)
            ->where('member_id', $member->id)
            ->where('status', 'active')
            ->whereNull('only_month')
            ->exists();
        if ($standing && $data['scope'] !== 'once') {
            abort(422, 'This sale already has a standing hold or cancellation. Release it first.');
        }

        $hold = IncentiveHold::create([
            'organization_id' => $org->id,
            'member_id' => $member->id,
            'invoice_id' => $invoice->id,
            'kind' => $data['scope'] === 'cancel' ? 'cancel' : 'hold',
            'from_month' => $data['month'],
            'only_month' => $data['scope'] === 'once' ? $data['month'] : null,
            'recover' => $data['scope'] === 'cancel' && ($data['recover'] ?? false),
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($me, $org->id, 'incentive.' . ($data['scope'] === 'cancel' ? 'cancelled' : 'held'), $invoice, array_filter([
            'employee' => $member->user?->name,
            'client' => $invoice->client?->company_name,
            'invoice' => $invoice->number,
            'scope' => $data['scope'] === 'once' ? 'this month only' : ($data['scope'] === 'cancel' ? 'all remaining' : 'until released'),
            'recover_paid' => ($data['scope'] === 'cancel' && ($data['recover'] ?? false)) ? 'yes' : null,
            'from' => $data['month'],
            'note' => $data['note'] ?? null,
        ]));

        return response()->json(['message' => match ($data['scope']) {
            'once' => 'Held for ' . $data['month'] . ' — it pays next month as an arrear, automatically.',
            'remaining' => 'Held from ' . $data['month'] . '. Releasing it pays everything withheld as one arrear.',
            default => 'Cancelled from ' . $data['month'] . '.'
                . (($data['recover'] ?? false)
                    ? ' The installments already paid come back as a minus on ' . $data['month'] . '’s incentive.'
                    : '')
                . ' Regain it to resume future months; the cancelled months are gone.',
        }, 'data' => ['uuid' => $hold->uuid]], 201);
    }

    /**
     * The emergency brake: one ruling over EVERY run on a member's ledger —
     * hold all remaining, or cancel — instead of thirty one-by-one clicks.
     * Runs already under a standing ruling are left as they are.
     */
    public function holdAll(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'Holding incentives is the Admin’s or a Subadmin’s.');

        $data = $request->validate([
            'member_uuid' => ['required', 'string'],
            'scope' => ['required', Rule::in(['remaining', 'cancel'])],
            'month' => ['required', 'date_format:Y-m'],
            'recover' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $member = Member::with('user:id,name')->where('organization_id', $org->id)
            ->where('uuid', $data['member_uuid'])->firstOrFail();

        $calc = new IncentiveCalculator($org);
        $plan = $calc->planFor($member, now());
        abort_unless($plan && $plan->kind === 'spread', 422, 'This member has no spread runs to rule on.');

        $sales = $calc->spreadSales($member, now()->startOfMonth(), $plan->config ?? []);
        $standing = IncentiveHold::where('member_id', $member->id)
            ->where('status', 'active')->whereNull('only_month')
            ->pluck('invoice_id')->flip();

        $made = 0;
        foreach ($sales as $sale) {
            if (isset($standing[$sale['invoice_id']])) {
                continue;   // already ruled — one active ruling per sale
            }
            IncentiveHold::create([
                'organization_id' => $org->id,
                'member_id' => $member->id,
                'invoice_id' => $sale['invoice_id'],
                'kind' => $data['scope'] === 'cancel' ? 'cancel' : 'hold',
                'from_month' => $data['month'],
                'only_month' => null,
                'recover' => $data['scope'] === 'cancel' && ($data['recover'] ?? false),
                'note' => $data['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $made++;
        }

        if ($made === 0) {
            abort(422, 'Every run already has a standing hold or cancellation.');
        }

        ActivityLog::record($me, $org->id,
            'incentive.' . ($data['scope'] === 'cancel' ? 'bulk_cancelled' : 'bulk_held'), $member, array_filter([
                'employee' => $member->user?->name,
                'runs' => $made,
                'from' => $data['month'],
                'recover_paid' => ($data['scope'] === 'cancel' && ($data['recover'] ?? false)) ? 'yes' : null,
                'note' => $data['note'] ?? null,
            ]));

        return response()->json(['message' => $made . ' run' . ($made === 1 ? '' : 's') . ' '
            . ($data['scope'] === 'cancel' ? 'cancelled' : 'held') . ' from ' . $data['month']
            . '. Each can be released or regained one by one.'], 201);
    }

    /**
     * Lift a hold (the withheld months pay as an arrear from this month) or
     * regain a cancellation (future months resume; the gap stays lost).
     */
    public function release(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'Releasing an incentive is the Admin’s or a Subadmin’s.');

        $hold = IncentiveHold::with(['invoice.client:id,company_name', 'member.user:id,name'])
            ->where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        if ($hold->status !== 'active') {
            abort(422, 'Already released.');
        }

        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $hold->update([
            'status' => 'released',
            'released_month' => $data['month'] ?? now()->format('Y-m'),
            'released_by' => $request->user()->id,
        ]);

        ActivityLog::record($me, $org->id, $hold->kind === 'cancel' ? 'incentive.regained' : 'incentive.released', $hold->invoice, [
            'employee' => $hold->member?->user?->name,
            'client' => $hold->invoice?->client?->company_name,
            'invoice' => $hold->invoice?->number,
            'from' => $hold->released_month,
        ]);

        return response()->json(['message' => $hold->kind === 'cancel'
            ? 'Regained — installments resume from ' . $hold->released_month . '; the cancelled months stay lost.'
            : 'Released — the withheld months pay as an arrear with ' . $hold->released_month . '’s incentive, remarked on the slip.']);
    }

    /** Whose ledger: their own by default; someone else's needs authority. */
    private function target(Request $request, Member $me): Member
    {
        $uuid = $request->query('member');
        if (! $uuid || $uuid === $me->uuid) {
            return $me->loadMissing('user:id,name');
        }

        // Earnings stay individual: only the Admin/Subadmin read another
        // person's ledger — a Team Workspace leader sees their own only.
        abort_unless(
            in_array($me->crm_role, ['admin', 'subadmin'], true),
            403,
            'Another person’s incentive ledger is the Admin’s or a Subadmin’s to read.',
        );

        return Member::with('user:id,name')
            ->where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }
}
