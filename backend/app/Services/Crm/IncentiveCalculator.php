<?php

namespace App\Services\Crm;

use App\Http\Controllers\Api\V1\Crm\CommissionController;
use App\Models\Crm\Expense;
use App\Models\Crm\IncentivePlan;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a month of selling earned, under this employee's own plan.
 *
 * The sale the incentive is paid on is never the invoiced figure raw: the
 * company's sheet nets off what the sale cost to make — commission paid to
 * the client and the gateway's cut — both of which this system already
 * books against the invoice. What is left is the "effective sale", and the
 * plan is applied to that.
 *
 * Four shapes of plan:
 *   flat_percent        — 0.5% of everything (the sales-bonus kind)
 *   slab                — 0–10L→1%, 10–15L→2%… the band the TOTAL falls in
 *                         prices the whole amount (or marginally, per band,
 *                         when the plan says so)
 *   percent_minus_base  — 25% of sale minus a base amount, floored at zero
 *                         (the senior-seller kind)
 *   spread              — the subscription-safe kind: each sale-month's
 *                         incentive pool is divided over N months, one
 *                         installment a month. Because every installment is
 *                         recomputed live from the ledger, a cancelled sale
 *                         simply stops paying — the money never went out in
 *                         one go, so there is nothing to claw back.
 *
 * TDS is never a plan setting: each invoice carries the TDS its client
 * actually deducted (2%, 10%, or none) as a deduction line, and the
 * document's TOTAL is already net of it — so the effective sale simply
 * inherits the truth from the paper.
 *
 * A Team Head's plan may add a team percent on the team's effective sale,
 * exactly as the sheet pays Satish and Garima.
 */
class IncentiveCalculator
{
    public function __construct(private Organization $org)
    {
    }

    /** The plan standing for this member in a given month, if any. */
    public function planFor(Member $member, Carbon $month): ?IncentivePlan
    {
        return IncentivePlan::where('member_id', $member->id)
            ->whereDate('effective_from', '<=', $month->copy()->endOfMonth()->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * The whole story for one member and one incentive month.
     *
     * @return array<string, mixed>
     */
    public function compute(Member $member, Carbon $month): array
    {
        $plan = $this->planFor($member, $month);
        $label = $month->format('Y-m');

        $empty = [
            'incentive_month' => $label,
            'plan' => $plan?->kind ?? 'none',
            'plan_note' => $plan?->note,
            'self' => null,
            'team' => null,
            'self_incentive' => 0.0,
            'team_incentive' => 0.0,
            'total' => 0.0,
        ];

        if (! $plan || $plan->kind === 'none') {
            return $empty;
        }

        $config = $plan->config ?? [];

        if ($plan->kind === 'spread') {
            return $this->spread($member, $month, $config) + [
                'plan' => 'spread',
                'plan_note' => $plan->note,
                'config' => $config,
            ];
        }

        // Two team shapes for a Team Head, chosen on the plan:
        //   combined — self + team sale run through the member's OWN
        //              structure as one figure
        //   separate — self through the structure, plus a flat team percent
        //              on the team's sale beside it (the default)
        $combined = ($config['team_mode'] ?? 'separate') === 'combined';
        $selfIds = $combined ? $member->teamMemberIds() : [$member->id];

        $gate = $this->paymentGate($member);
        $self = $this->effectiveSale($selfIds, $month, $config, $gate);
        $selfIncentive = $this->apply($plan->kind, $config, $self['effective']);

        $team = null;
        $teamIncentive = 0.0;
        $teamPercent = $combined ? 0.0 : (float) ($config['team_percent'] ?? 0);
        if ($teamPercent > 0) {
            $teamIds = array_values(array_diff($member->teamMemberIds(), [$member->id]));
            if ($teamIds !== []) {
                $team = $this->effectiveSale($teamIds, $month, $config, $gate);
                $teamIncentive = round($team['effective'] * $teamPercent / 100, 2);
            }
        }

        return [
            'incentive_month' => $label,
            'plan' => $plan->kind,
            'plan_note' => $plan->note,
            'config' => $config,
            'self' => $self,
            'team' => $team,
            'self_incentive' => $selfIncentive,
            'team_incentive' => $teamIncentive,
            'total' => round($selfIncentive + $teamIncentive, 2),
        ];
    }

    /**
     * Sales attributed to these desks in the month, and what they cost:
     * total − client commission − collection charges = effective sale.
     *
     * TDS is already inside the totals: an invoice's TOTAL is net of the
     * TDS line its client deducted, so nothing is deducted twice here —
     * the figure is only reported so the screen can show it.
     *
     * @param  array<int, int>  $memberIds
     * @param  array<string, mixed>  $config
     * @return array{total: float, commission: float, charges: float, tds: float, effective: float, invoices: int}
     */
    private function effectiveSale(array $memberIds, Carbon $month, array $config = [], bool $gate = true): array
    {
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        $invoices = Invoice::where('organization_id', $this->org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereIn('member_id', $memberIds)
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            // The payment gate: until the client has paid IN FULL, the sale
            // earns nothing. (One-go plans count it in its own month once
            // paid — recalculate that month's slip if it was already made.)
            ->when($gate, fn ($q) => $q->where('payment_status', 'paid'))
            ->get(['id', 'total', 'tds']);

        $costs = Expense::whereIn('invoice_id', $invoices->pluck('id'))
            ->whereIn('category', [CommissionController::CATEGORY, GatewayCharge::CATEGORY])
            ->get(['category', 'total_amount']);

        $total = round((float) $invoices->sum('total'), 2);
        $commission = round((float) $costs->where('category', CommissionController::CATEGORY)->sum('total_amount'), 2);
        $charges = round((float) $costs->where('category', GatewayCharge::CATEGORY)->sum('total_amount'), 2);

        // The invoices' own TDS, already inside their totals — reported
        // for the screen, never deducted a second time.
        $tds = round((float) $invoices->sum('tds'), 2);

        return [
            'total' => $total,
            'commission' => $commission,
            'charges' => $charges,
            'tds' => $tds,
            'effective' => round(max(0, $total - $commission - $charges), 2),
            'invoices' => $invoices->count(),
        ];
    }

    /**
     * The spread plan, per sale — and per VINTAGE.
     *
     * Every invoice runs under the plan that stood on its own sale date, so
     * changing the percent applies from the next invoice onwards and every
     * run already in flight finishes on its old terms. Nothing is stored for
     * the normal flow; holds and cancellations are the exception state, and
     * a cancellation marked `recover` claws the already-paid installments
     * back as a NEGATIVE line — the sale came back, so its incentive does.
     *
     * @param  array<string, mixed>  $config  the anchor month's plan config
     * @return array<string, mixed>
     */
    private function spread(Member $member, Carbon $anchor, array $config): array
    {
        $anchorKey = $anchor->format('Y-m');
        $sales = $this->spreadSales($member, $anchor, $config);
        $holds = \App\Models\Crm\IncentiveHold::where('member_id', $member->id)
            ->get()
            ->groupBy('invoice_id');

        $installments = [];
        $arrears = [];
        $selfTotal = 0.0;
        $teamTotal = 0.0;

        $gate = $this->paymentGate($member);

        foreach ($sales as $sale) {
            // Team rows (a teammate's sale at the leader's team percent) run
            // the same gate, hold and arrear machinery as the leader's own.
            $isTeam = (bool) ($sale['team'] ?? false);
            $rowHolds = $holds[$sale['invoice_id']] ?? collect();
            $months = $sale['months'];
            $number = $this->monthDiff($sale['sale_month'], $anchorKey) + 1;

            // The payment gate: nothing moves until the client has paid in
            // full. The moment they have, the months that waited release
            // themselves as one arrear — no button, no ruling.
            if ($gate && ! $sale['paid']) {
                continue;
            }
            $startFrom = $gate ? max($sale['paid_month'] ?? $sale['sale_month'], $sale['sale_month']) : $sale['sale_month'];

            if ($gate && $startFrom === $anchorKey && $startFrom > $sale['sale_month']) {
                $amount = 0.0;
                $count = 0;
                for ($m = $sale['sale_month']; $m < $startFrom; $m = $this->nextMonth($m)) {
                    $n = $this->monthDiff($sale['sale_month'], $m) + 1;
                    if ($n >= 1 && $n <= $months && ! $rowHolds->contains(fn ($h) => $h->blocks($m))) {
                        $amount += $sale['installment'];
                        $count++;
                    }
                }
                if ($amount > 0) {
                    $isTeam ? $teamTotal += $amount : $selfTotal += $amount;
                    $arrears[] = [
                        'invoice_id' => $sale['invoice_id'],
                        'invoice_no' => $sale['invoice_no'],
                        'client' => $sale['client'],
                        'sale_month' => $sale['sale_month'],
                        'months' => $count,
                        'amount' => round($amount, 2),
                        'reason' => 'payment_received',
                        'team' => $isTeam,
                        'seller' => $sale['seller'] ?? null,
                    ];
                }
            }

            if ($number >= 1 && $number <= $months && $sale['installment'] > 0
                && (! $gate || $anchorKey >= $startFrom)) {
                $blocked = $rowHolds->contains(fn ($h) => $h->blocks($anchorKey));
                if (! $blocked) {
                    $isTeam ? $teamTotal += $sale['installment'] : $selfTotal += $sale['installment'];
                    $installments[] = [
                        'invoice_id' => $sale['invoice_id'],
                        'invoice_no' => $sale['invoice_no'],
                        'client' => $sale['client'],
                        'sale_month' => $sale['sale_month'],
                        'effective_sale' => $sale['effective'],
                        'pool' => $sale['pool'],
                        'installment' => $sale['installment'],
                        'team_installment' => null,
                        'number' => $number,
                        'of' => $months,
                        'team' => $isTeam,
                        'seller' => $sale['seller'] ?? null,
                    ];
                }
            }

            // Arrears: what a hold withheld, paying out this month.
            foreach ($rowHolds as $hold) {
                $releaseAt = $this->holdReleaseMonth($hold);
                if ($releaseAt !== $anchorKey || $hold->kind !== 'hold') {
                    continue;
                }
                $fromKey = $hold->only_month ?? $hold->from_month;
                $upto = $hold->only_month ?? $this->prevMonth($anchorKey);
                $amount = 0.0;
                $count = 0;
                for ($m = $fromKey; $m <= $upto; $m = $this->nextMonth($m)) {
                    $n = $this->monthDiff($sale['sale_month'], $m) + 1;
                    if ($n >= 1 && $n <= $months) {
                        $amount += $sale['installment'];
                        $count++;
                    }
                }
                if ($amount > 0) {
                    $isTeam ? $teamTotal += $amount : $selfTotal += $amount;
                    $arrears[] = [
                        'invoice_id' => $sale['invoice_id'],
                        'invoice_no' => $sale['invoice_no'],
                        'client' => $sale['client'],
                        'sale_month' => $sale['sale_month'],
                        'months' => $count,
                        'amount' => round($amount, 2),
                        'team' => $isTeam,
                        'seller' => $sale['seller'] ?? null,
                    ];
                }
            }
        }

        // Recoveries: sales returned in full, their paid incentive coming
        // back. The invoice may be cancelled by now, so these are found from
        // the ruling, not the ledger — and the amount is computed from what
        // the payroll actually paid, never typed.
        $recoveryTotal = 0.0;
        $recoveries = \App\Models\Crm\IncentiveHold::with('invoice.client:id,company_name')
            ->where('member_id', $member->id)
            ->where('kind', 'cancel')
            ->where('recover', true)
            ->where('from_month', $anchorKey)
            ->get();
        if ($recoveries->isNotEmpty()) {
            $offset = max(0, (int) ($this->planFor($member, $anchor)?->release_offset_months ?? 1));
            $slipMonths = \App\Models\Crm\SalarySlip::where('member_id', $member->id)
                ->get(['year', 'month'])
                ->map(fn ($s) => sprintf('%04d-%02d', $s->year, $s->month))
                ->flip();

            foreach ($recoveries as $ruling) {
                $invoice = $ruling->invoice;
                if (! $invoice) {
                    continue;
                }
                $saleMonth = $invoice->invoice_date->format('Y-m');
                $vintage = $this->planAt($member, $saleMonth);
                if (! $vintage || $vintage->kind !== 'spread') {
                    continue;
                }
                $row = $this->saleRow($invoice, $vintage->config ?? []);

                // Under the payment gate, months before full payment paid
                // nothing — there is nothing to claw back from them.
                $gateFloor = $this->paymentGate($member)
                    ? ($row['paid_month'] ?? null)
                    : $saleMonth;
                if ($gateFloor === null) {
                    continue;
                }

                $paid = 0.0;
                $count = 0;
                for ($m = $saleMonth; $m < $anchorKey; $m = $this->nextMonth($m)) {
                    if ($m < $gateFloor) {
                        continue;
                    }
                    $n = $this->monthDiff($saleMonth, $m) + 1;
                    if ($n < 1 || $n > $row['months']) {
                        continue;
                    }
                    $payroll = Carbon::parse($m . '-01')->addMonthsNoOverflow($offset)->format('Y-m');
                    if (isset($slipMonths[$payroll])) {
                        $paid += $row['installment'];
                        $count++;
                    }
                }
                if ($paid > 0) {
                    $selfTotal -= $paid;
                    $recoveryTotal += $paid;
                    $arrears[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_no' => $invoice->number,
                        'client' => $invoice->client?->company_name,
                        'sale_month' => $saleMonth,
                        'months' => $count,
                        'amount' => round(-$paid, 2),
                    ];
                }
            }
        }

        return [
            'incentive_month' => $anchorKey,
            'self' => null,
            'team' => null,
            'spread_months' => $this->spreadMonths($config),
            'installments' => $installments,
            'arrears' => $arrears,
            'arrear_total' => round(collect($arrears)->where('amount', '>', 0)->sum('amount'), 2),
            'recovery_total' => round($recoveryTotal, 2),
            'self_incentive' => round($selfTotal, 2),
            'team_incentive' => round($teamTotal, 2),
            'total' => round($selfTotal + $teamTotal, 2),
        ];
    }

    /**
     * Every sale that could still be paying at the anchor — each row under
     * the plan that stood on its OWN sale date, so a percent change applies
     * from the next invoice onwards. Public: the Incentives screen builds
     * its client-wise ledger from it.
     *
     * @param  array<string, mixed>  $config  the anchor plan, for team_mode
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function spreadSales(Member $member, Carbon $anchor, array $config)
    {
        // Look back far enough for the longest run possible: a Work Order
        // validity can promise more months than any plan setting, so the
        // window is generous and the per-row month count does the limiting.
        $window = max($this->memberPlans($member)
            ->where('kind', 'spread')
            ->map(fn ($p) => $this->spreadMonths($p->config ?? []))
            ->max() ?? 1, $this->spreadMonths($config), 36);

        // Combined team mode: the team's sales run through the Head's own
        // ledger as if their own.
        $memberIds = ($config['team_mode'] ?? 'separate') === 'combined'
            ? $member->teamMemberIds()
            : [$member->id];

        $windowStart = $anchor->copy()->subMonthsNoOverflow($window - 1)->startOfMonth();

        $invoices = Invoice::with('client:id,uuid,company_name')
            ->where('organization_id', $this->org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereIn('member_id', $memberIds)
            ->whereDate('invoice_date', '>=', $windowStart->toDateString())
            ->whereDate('invoice_date', '<=', $anchor->copy()->endOfMonth()->toDateString())
            ->orderBy('invoice_date')
            ->get(['id', 'uuid', 'number', 'client_id', 'invoice_date', 'total', 'tds', 'payment_status']);

        $rows = $invoices->map(function (Invoice $invoice) use ($member) {
            // The invoice's own vintage: the plan standing on its sale date.
            $vintage = $this->planAt($member, $invoice->invoice_date->format('Y-m'));
            if (! $vintage || $vintage->kind !== 'spread') {
                return null;
            }

            return $this->saleRow($invoice, $vintage->config ?? []);
        })->filter(fn ($row) => $row !== null && $row['pool'] > 0)->values();

        // Separate team mode: the team's sales ride beside the leader's own
        // rows, priced at the plan's team percent over the same spread shape
        // — the "Team — Employee2" lines on the leader's ledger.
        if (($config['team_mode'] ?? 'separate') !== 'combined') {
            $activeIds = array_values(array_diff($member->teamMemberIds(), [$member->id]));

            // Withdrawing access ends nothing already earned: runs started
            // while the grant stood are the leader's RIGHT and finish their
            // scheduled term, marked "access withdrawn". Only sales made
            // after the withdrawal are no longer theirs.
            $withdrawn = DB::table('crm_team_access')
                ->where('leader_id', $member->id)
                ->whereNotNull('revoked_at')
                ->pluck('revoked_at', 'member_id');

            $teamIds = array_values(array_unique(array_merge($activeIds, $withdrawn->keys()->all())));
            $anyTeamPercent = $this->memberPlans($member)->contains(fn (IncentivePlan $p) => $p->kind === 'spread'
                && (float) (($p->config ?? [])['team_percent'] ?? 0) > 0);

            if ($teamIds !== [] && $anyTeamPercent) {
                $teamInvoices = Invoice::with(['client:id,uuid,company_name', 'member.user:id,name'])
                    ->where('organization_id', $this->org->id)
                    ->where('kind', 'invoice')
                    ->where('status', '!=', 'cancelled')
                    ->whereIn('member_id', $teamIds)
                    ->whereDate('invoice_date', '>=', $windowStart->toDateString())
                    ->whereDate('invoice_date', '<=', $anchor->copy()->endOfMonth()->toDateString())
                    ->orderBy('invoice_date')
                    ->get(['id', 'uuid', 'number', 'client_id', 'member_id', 'invoice_date', 'total', 'tds', 'payment_status']);

                $teamRows = $teamInvoices->map(function (Invoice $invoice) use ($member, $activeIds, $withdrawn) {
                    // The LEADER's vintage on the sale date decides the team
                    // percent — a rate change applies from the next invoice.
                    $vintage = $this->planAt($member, $invoice->invoice_date->format('Y-m'));
                    $vc = $vintage?->config ?? [];
                    $teamPercent = (float) ($vc['team_percent'] ?? 0);
                    if (! $vintage || $vintage->kind !== 'spread' || $teamPercent <= 0
                        || ($vc['team_mode'] ?? 'separate') === 'combined') {
                        return null;
                    }

                    // Under a withdrawn grant, only sales dated BEFORE the
                    // withdrawal belong to the leader; those runs then pay
                    // to their scheduled end.
                    $active = in_array($invoice->member_id, $activeIds, true);
                    $revokedAt = $withdrawn[$invoice->member_id] ?? null;
                    if (! $active
                        && ($revokedAt === null || $invoice->invoice_date->gte(Carbon::parse($revokedAt)->startOfDay()->addDay()))) {
                        return null;
                    }

                    return $this->saleRow($invoice, [
                        'percent' => $teamPercent,
                        'spread_months' => $this->spreadMonths($vc),
                    ]) + [
                        'team' => true,
                        'seller' => $invoice->member?->user?->name,
                        // The comment the ledger carries: when the access
                        // behind this run was withdrawn. Purely a remark —
                        // the run itself finishes its term.
                        'withdrawn_month' => $active ? null : Carbon::parse($revokedAt)->format('Y-m'),
                    ];
                })->filter(fn ($row) => $row !== null && $row['pool'] > 0);

                $rows = $rows->concat($teamRows)->values();
            }
        }

        return $rows;
    }

    /**
     * One sale priced under one config: costs off, TDS off, pool and
     * installment out.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function saleRow(Invoice $invoice, array $config): array
    {
        // The run's length is the SALE's own promise: the first Work Order
        // line's validity span. A 26 Aug 2026 → 26 Nov 2026 plan spreads
        // over 3 months; → 26 Aug 2027 over 12. Only a sale with no
        // validity dates falls back to the plan's months (then HR Policy).
        $months = $this->validityMonths($invoice) ?? $this->spreadMonths($config);
        $percent = (float) ($config['percent'] ?? 0);

        $costs = Expense::where('invoice_id', $invoice->id)
            ->whereIn('category', [CommissionController::CATEGORY, GatewayCharge::CATEGORY])
            ->sum('total_amount');
        // The total is already net of the TDS the client deducted on THIS
        // invoice — 2%, 10%, or none — because TDS is a deduction line on
        // the document itself. Nothing to configure, nothing to double-count.
        $effective = round(max(0, (float) $invoice->total - (float) $costs), 2);
        $pool = round($effective * $percent / 100, 2);

        return [
            'invoice_id' => $invoice->id,
            'invoice_uuid' => $invoice->uuid,
            'invoice_no' => $invoice->number,
            'client' => $invoice->client?->company_name,
            'client_uuid' => $invoice->client?->uuid,
            'sale_month' => $invoice->invoice_date->format('Y-m'),
            'total' => (float) $invoice->total,
            'costs' => (float) $costs,
            'tds' => (float) ($invoice->tds ?? 0),
            'payment_status' => $invoice->payment_status,
            'paid' => $invoice->payment_status === 'paid',
            'paid_month' => $this->paidMonth($invoice),
            'effective' => $effective,
            'percent' => $percent,
            'months' => $months,
            'pool' => $pool,
            'installment' => $pool > 0 ? round($pool / $months, 2) : 0.0,
        ];
    }

    /**
     * Whether incentive waits for the client's full payment. The HR Policy
     * sets the house rule; a member's own record may override it either way
     * — the escape hatch for the one employee whose incentive must flow (or
     * must wait) whatever the policy says.
     */
    public function paymentGate(?Member $member = null): bool
    {
        if ($member !== null && $member->incentive_needs_payment !== null) {
            return (bool) $member->incentive_needs_payment;
        }

        return (bool) ($this->org->hrPolicy()['incentive_needs_full_payment'] ?? true);
    }

    /**
     * The month the invoice became fully paid, read from its receipts —
     * the month the waiting installments release themselves in.
     */
    public function paidMonth(Invoice $invoice): ?string
    {
        if ($invoice->payment_status !== 'paid') {
            return null;
        }

        $last = \App\Models\Crm\InvoicePayment::where('invoice_id', $invoice->id)->max('received_at');

        // Marked paid with no receipt rows (imported history): treat as
        // paid from its own month, so nothing waits.
        return $last ? Carbon::parse($last)->format('Y-m') : $invoice->invoice_date->format('Y-m');
    }

    /**
     * How many months the sale's own paper promises: the first Work Order
     * line's validity span, rounded up so a part month still counts.
     */
    private function validityMonths(Invoice $invoice): ?int
    {
        $item = \App\Models\Crm\InvoiceItem::where('invoice_id', $invoice->id)
            ->whereNotNull('validity_from')
            ->whereNotNull('validity_to')
            ->orderBy('sort')->orderBy('id')
            ->first(['validity_from', 'validity_to']);

        if (! $item || $item->validity_to->lte($item->validity_from)) {
            return null;
        }

        $months = $item->validity_from->diffInMonths($item->validity_to);
        if ($item->validity_from->copy()->addMonthsNoOverflow($months)->lt($item->validity_to)) {
            $months++;
        }

        return max(1, min(60, (int) $months));
    }

    /** The plan standing in a given 'YYYY-MM' month, from one cached read. */
    private function planAt(Member $member, string $month): ?IncentivePlan
    {
        return $this->memberPlans($member)
            ->first(fn (IncentivePlan $p) => $p->effective_from->format('Y-m') <= $month);
    }

    /** @var array<int, \Illuminate\Support\Collection<int, IncentivePlan>> */
    private array $planCache = [];

    /** @return \Illuminate\Support\Collection<int, IncentivePlan> */
    private function memberPlans(Member $member)
    {
        return $this->planCache[$member->id] ??= IncentivePlan::where('member_id', $member->id)
            ->orderByDesc('effective_from')
            ->get();
    }

    /** @param array<string, mixed> $config */
    public function spreadMonths(array $config): int
    {
        return max(1, (int) ($config['spread_months']
            ?? $this->org->hrPolicy()['incentive_spread_months']));
    }

    /**
     * The anchor month a hold's withheld money pays out in, or null while it
     * is still holding. A one-month hold releases itself the month after —
     * "only this month" means exactly that.
     */
    public function holdReleaseMonth(\App\Models\Crm\IncentiveHold $hold): ?string
    {
        if ($hold->kind !== 'hold') {
            return null;
        }
        if ($hold->only_month !== null) {
            return $hold->released_month ?? $this->nextMonth($hold->only_month);
        }

        return $hold->status === 'released' ? $hold->released_month : null;
    }

    private function monthDiff(string $from, string $to): int
    {
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));

        return ($ty - $fy) * 12 + ($tm - $fm);
    }

    private function nextMonth(string $month): string
    {
        return Carbon::parse($month . '-01')->addMonthNoOverflow()->format('Y-m');
    }

    private function prevMonth(string $month): string
    {
        return Carbon::parse($month . '-01')->subMonthNoOverflow()->format('Y-m');
    }

    /** @param array<string, mixed> $config */
    private function apply(string $kind, array $config, float $sale): float
    {
        if ($sale <= 0) {
            return 0.0;
        }

        return match ($kind) {
            'flat_percent' => round($sale * (float) ($config['percent'] ?? 0) / 100, 2),
            // The senior-seller shape: a cut of everything, less the salary
            // already guaranteed — never negative.
            'percent_minus_base' => round(max(
                0,
                $sale * (float) ($config['percent'] ?? 0) / 100 - (float) ($config['base_amount'] ?? 0),
            ), 2),
            'slab' => $this->slab($config, $sale),
            default => 0.0,
        };
    }

    /**
     * Slab rates. The sheet prices the WHOLE sale at the band the total
     * lands in (19L in a 15–20L→2.5% band pays 2.5% on all of it); marginal
     * mode prices each band's own slice, for the companies that want it.
     *
     * @param array<string, mixed> $config
     */
    private function slab(array $config, float $sale): float
    {
        // [{upto: 1000000, percent: 1}, …] sorted; the last band may leave
        // `upto` empty, meaning "and beyond".
        $slabs = collect($config['slabs'] ?? [])
            ->map(fn ($s) => [
                'upto' => isset($s['upto']) && $s['upto'] !== null && $s['upto'] !== '' ? (float) $s['upto'] : null,
                'percent' => (float) ($s['percent'] ?? 0),
            ])
            ->sortBy(fn ($s) => $s['upto'] ?? PHP_FLOAT_MAX)
            ->values();

        if ($slabs->isEmpty()) {
            return 0.0;
        }

        if (($config['slab_mode'] ?? 'whole') === 'marginal') {
            $paid = 0.0;
            $floor = 0.0;
            foreach ($slabs as $band) {
                $ceiling = $band['upto'] ?? $sale;
                $slice = max(0, min($sale, $ceiling) - $floor);
                $paid += $slice * $band['percent'] / 100;
                $floor = $ceiling;
                if ($sale <= $ceiling) {
                    break;
                }
            }

            return round($paid, 2);
        }

        // Whole-amount: find the band the total falls in and price it all.
        foreach ($slabs as $band) {
            if ($band['upto'] === null || $sale <= $band['upto']) {
                return round($sale * $band['percent'] / 100, 2);
            }
        }

        return round($sale * $slabs->last()['percent'] / 100, 2);
    }
}
