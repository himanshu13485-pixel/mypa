<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Target;
use App\Support\QueryList;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Monthly sales targets. The manager sets the numbers; achievement comes
 * straight out of the invoice ledger (tax invoices, not cancelled, dated in
 * the period, attributed to the salesperson), split New vs Existing by the
 * invoice's client category — so the screen can never disagree with billing.
 *
 * The screen reads one month by default but any run of months can be asked
 * for — a quarter, a half, a full year. Targets are still SET one month at a
 * time; a span is a reading of months already set, never a number of its own.
 */
class TargetController extends Controller
{
    /** Client categories that count as "existing" business. */
    private const EXISTING = ['existing', 'global_existing', 'sez_existing'];

    /** How the growth chart may bucket time, in months per bucket. */
    private const PERIODS = ['month' => 1, 'quarter' => 3, 'half' => 6, 'year' => 12];

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        abort_unless($month >= 1 && $month <= 12, 422, 'Month must be 1-12.');

        // The far end of the span. Absent, it is the same month — the old
        // single-month screen, unchanged.
        $endYear = (int) $request->query('end_year', $year);
        $endMonth = (int) $request->query('end_month', $month);
        abort_unless($endMonth >= 1 && $endMonth <= 12, 422, 'Month must be 1-12.');

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = Carbon::create($endYear, $endMonth, 1)->endOfMonth();
        // Months counted as whole numbers, not as a difference between two
        // instants — a month-end is a fraction short of the next month.
        $startCode = $year * 12 + $month;
        $endCode = $endYear * 12 + $endMonth;
        abort_if($endCode < $startCode, 422, 'The last month cannot come before the first.');
        $span = $endCode - $startCode + 1;
        abort_if($span > 24, 422, 'A span of more than 24 months is too wide to read.');

        $isManager = in_array($me->crm_role, ['admin', 'subadmin'], true);

        // Everyone flagged as a salesperson gets a row, target set or not —
        // the old screen auto-created rows so nobody could hide by having
        // no target. Non-managers see only themselves.
        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->when(! $isManager, fn ($q) => $q->whereIn('id', $me->teamMemberIds()))
            ->where(fn ($q) => $q->where('is_salesperson', true)->orWhereHas('targets', fn ($t) => $t
                ->whereRaw('(year * 12 + month) between ? and ?', [$startCode, $endCode])))
            ->orderBy('id')
            ->get();

        // Over a span the target is the sum of those months' own targets, so
        // the two readings can never drift apart.
        $targets = Target::selectRaw('member_id, sum(target_amount) as target_sum, sum(client_target) as client_target_sum, count(*) as months_set')
            ->where('organization_id', $org->id)
            ->whereRaw('(year * 12 + month) between ? and ?', [$startCode, $endCode])
            ->groupBy('member_id')
            ->get()
            ->keyBy('member_id');

        /*
         * Which way a desk is judged, over these months.
         *
         * The row a manager saved carries the kind it was set as, so a month
         * already gone reads the way it was judged at the time. A desk with
         * no row in the period falls back to the kind the company gives that
         * person now.
         */
        $kinds = Target::where('organization_id', $org->id)
            ->whereRaw('(year * 12 + month) between ? and ?', [$startCode, $endCode])
            ->orderBy('year')->orderBy('month')
            ->pluck('kind', 'member_id');

        // A note belongs to a single month; a span has many, so it goes quiet.
        $notes = $span === 1
            ? Target::where('organization_id', $org->id)
                ->where('year', $year)->where('month', $month)
                ->pluck('note', 'member_id')
            : collect();

        $achieved = $this->achievementBetween($org, $start, $end);

        /*
         * The same span, immediately before this one.
         *
         * A target answers "how are we doing"; this answers "compared with
         * what", which is the question people actually argue about. Three
         * months against the three before them, a year against the year
         * before - the same length, so the comparison is fair, and the same
         * query, so it cannot disagree with itself.
         */
        $beforeEnd = $start->copy()->subDay()->endOfDay();
        $beforeStart = $start->copy()->subMonthsNoOverflow($span)->startOfMonth();
        $previous = $this->achievementBetween($org, $beforeStart, $beforeEnd);

        /*
         * How many clients a desk has in all, for the client-oriented board.
         *
         * Not what the target measures - that is this month's new business -
         * but the figure somebody wants beside their name: the portfolio they
         * have built since they started. A record still waiting for the
         * Admin's nod is not a client yet.
         */
        $portfolio = Client::approved()
            ->where('organization_id', $org->id)
            ->whereNotNull('assigned_member_id')
            ->selectRaw('assigned_member_id, count(*) as held')
            ->groupBy('assigned_member_id')
            ->pluck('held', 'assigned_member_id');

        $rows = $members->map(function (Member $m) use ($targets, $achieved, $previous, $notes, $kinds, $portfolio) {
            $target = (float) ($targets[$m->id]->target_sum ?? 0);
            $total = (float) ($achieved[$m->id]['base_sum'] ?? 0);
            $existing = (float) ($achieved[$m->id]['base_existing'] ?? 0);
            $clients = (int) ($achieved[$m->id]['client_count'] ?? 0);

            $kind = in_array($kinds[$m->id] ?? null, Target::KINDS, true)
                ? $kinds[$m->id]
                : (in_array($m->target_kind, Target::KINDS, true) ? $m->target_kind : 'sales');

            /*
             * A client target is a number of NEW clients for the month.
             *
             * Which is the same ledger the money side reads: a client closed
             * is a client billed, and the document says whether that was new
             * business or a client coming back. Counting records created
             * instead made a desk's whole portfolio look like one month's
             * work the day it was set up.
             */
            $newClients = (int) ($achieved[$m->id]['client_new'] ?? 0);
            $existingClients = (int) ($achieved[$m->id]['client_existing'] ?? 0);
            $clientTarget = (int) ($targets[$m->id]->client_target_sum ?? 0);

            return [
                'member_uuid' => $m->uuid,
                'name' => $m->user?->name,
                'employee_code' => $m->employee_code,
                // Which table this desk belongs in, and what it is judged by.
                'kind' => $kind,
                'target' => round($target, 2),
                'achieved' => round($total, 2),
                'achieved_new' => round($total - $existing, 2),
                'achieved_existing' => round($existing, 2),
                /*
                 * Two different shortfalls, and they were one column.
                 *
                 * What is left of the target is work still to do; what is due
                 * is money a client has not paid. The screen called both
                 * "Due", so a desk that had billed its whole target read as
                 * owing it.
                 */
                'pending_target' => round(max(0, $target - $total), 2),
                'payment_due' => round((float) ($achieved[$m->id]['payment_due'] ?? 0), 2),
                'percent' => $target > 0 ? round($total / $target * 100, 1) : null,
                'clients' => $clients,
                // The head count split the way the categories read: New,
                // Global-New and SEZ-New are all new business.
                'clients_new' => (int) ($achieved[$m->id]['client_new'] ?? 0),
                'clients_existing' => (int) ($achieved[$m->id]['client_existing'] ?? 0),
                'invoices' => (int) ($achieved[$m->id]['invoice_count'] ?? 0),
                // What one client was worth on average to this desk.
                'per_client' => $clients > 0 ? round($total / $clients, 2) : null,
                // The client-oriented side: new clients against the number
                // asked for, with the month's whole head count beside it.
                'client_target' => $clientTarget,
                'clients_closed' => $clients,
                'client_sales' => round($total, 2),
                'client_sales_new' => round($total - $existing, 2),
                'client_sales_existing' => round($existing, 2),
                'clients_due' => max(0, $clientTarget - $newClients),
                'client_percent' => $clientTarget > 0 ? round($newClients / $clientTarget * 100, 1) : null,
                // The portfolio behind the month: everything on this desk.
                'clients_total' => (int) ($portfolio[$m->id] ?? 0),
                /*
                 * The same desk over the span before this one.
                 *
                 * Beside the target rather than instead of it: a target says
                 * whether somebody is where they were asked to be, and this
                 * says which way they are travelling. A desk at 80% and
                 * climbing is a different conversation from a desk at 80%
                 * and falling.
                 */
                'previous_achieved' => round((float) ($previous[$m->id]['base_sum'] ?? 0), 2),
                'previous_clients_new' => (int) ($previous[$m->id]['client_new'] ?? 0),
                'growth_percent' => self::growthOf($total, (float) ($previous[$m->id]['base_sum'] ?? 0)),
                'client_growth_percent' => self::growthOf(
                    (float) $newClients,
                    (float) ($previous[$m->id]['client_new'] ?? 0),
                ),
                'note' => $notes[$m->id] ?? null,
            ];
        })->sortByDesc(fn ($row) => $row['kind'] === 'clients' ? $row['clients_new'] : $row['achieved'])->values();

        /*
         * Where each desk stands against the others.
         *
         * Rank and share are the comparison people actually make out loud,
         * and they were being made by eye off a sorted column - which works
         * until the sort changes. Two ladders, because the two floors are
         * judged on different things: money for one, new clients for the
         * other. Share is of the whole floor, so it adds to a hundred.
         */
        $floorSales = (float) $rows->sum('achieved');
        $floorNew = (int) $rows->sum('clients_new');
        $salesLadder = $rows->sortByDesc('achieved')->pluck('member_uuid')->values()->flip();
        $clientLadder = $rows->sortByDesc('clients_new')->pluck('member_uuid')->values()->flip();

        $rows = $rows->map(function (array $row) use ($salesLadder, $clientLadder, $floorSales, $floorNew) {
            $row['sales_rank'] = (int) $salesLadder[$row['member_uuid']] + 1;
            $row['client_rank'] = (int) $clientLadder[$row['member_uuid']] + 1;
            $row['sales_share'] = $floorSales > 0 ? round($row['achieved'] / $floorSales * 100, 1) : null;
            $row['client_share'] = $floorNew > 0 ? round($row['clients_new'] / $floorNew * 100, 1) : null;

            return $row;
        })->values();

        // A client billed by two salespeople is still one client to the
        // company, so the head count is taken again over the whole floor
        // rather than summed down the column.
        $clientTotal = (int) Invoice::where('organization_id', $org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $start->toDateString())
            ->whereDate('invoice_date', '<=', $end->toDateString())
            ->whereIn('member_id', $members->pluck('id'))
            ->distinct()
            ->count('client_id');

        $clientRows = $rows->where('kind', 'clients')->values();
        $salesRows = $rows->where('kind', 'sales')->values();

        return response()->json([
            'data' => $rows,
            /*
             * The two floors are counted apart.
             *
             * One is judged on the money it bills and the other on the clients
             * it brings in; a single figure over both would be a number nobody
             * is measured by.
             */
            'client_totals' => [
                'people' => $clientRows->count(),
                'client_target' => (int) $clientRows->sum('client_target'),
                'clients_new' => (int) $clientRows->sum('clients_new'),
                'clients_existing' => (int) $clientRows->sum('clients_existing'),
                'clients_closed' => (int) $clientRows->sum('clients_closed'),
                'clients_due' => (int) $clientRows->sum('clients_due'),
                'clients_total' => (int) $clientRows->sum('clients_total'),
                'client_sales' => round($clientRows->sum('client_sales'), 2),
                'client_sales_new' => round($clientRows->sum('client_sales_new'), 2),
                'client_sales_existing' => round($clientRows->sum('client_sales_existing'), 2),
                'percent' => $clientRows->sum('client_target') > 0
                    ? round($clientRows->sum('clients_new') / $clientRows->sum('client_target') * 100, 1)
                    : null,
            ],
            /*
             * The whole floor against the span before it.
             *
             * The same length of time, immediately before: three months read
             * against the three before them, a year against the year before.
             * Growth is null rather than a number when there was nothing to
             * grow from - "up 100%" from zero is arithmetic, not news.
             */
            'previous' => [
                'achieved' => round((float) $previous->sum('base_sum'), 2),
                'clients_new' => (int) $previous->sum('client_new'),
                'clients_existing' => (int) $previous->sum('client_existing'),
                'invoices' => (int) $previous->sum('invoice_count'),
                'label' => $span === 1
                    ? $beforeStart->format('F Y')
                    : $beforeStart->format('M Y') . ' — ' . $beforeEnd->format('M Y'),
                'growth_percent' => self::growthOf(
                    (float) $rows->sum('achieved'),
                    (float) $previous->sum('base_sum'),
                ),
                'client_growth_percent' => self::growthOf(
                    (float) $rows->sum('clients_new'),
                    (float) $previous->sum('client_new'),
                ),
            ],
            'totals' => [
                'people' => $salesRows->count(),
                'target' => $rows->sum('target'),
                'achieved' => $rows->sum('achieved'),
                'achieved_new' => $rows->sum('achieved_new'),
                'achieved_existing' => $rows->sum('achieved_existing'),
                'pending_target' => $rows->sum('pending_target'),
                'payment_due' => $rows->sum('payment_due'),
                'clients' => $clientTotal,
                'clients_new' => $rows->sum('clients_new'),
                'clients_existing' => $rows->sum('clients_existing'),
                'invoices' => $rows->sum('invoices'),
                'per_client' => $clientTotal > 0 ? round($rows->sum('achieved') / $clientTotal, 2) : null,
            ],
            'year' => $year,
            'month' => $month,
            'end_year' => $endYear,
            'end_month' => $endMonth,
            'months' => $span,
            'label' => $span === 1
                ? $start->format('F Y')
                : $start->format('M Y') . ' — ' . $end->format('M Y'),
            // Numbers are typed into one month at a time; a span is read-only.
            'editable' => $span === 1,
        ]);
    }

    /**
     * The growth map: sales bucketed by month, quarter, half-year or year,
     * each bucket carrying what the same bucket did a year earlier — so the
     * trend and the year-on-year comparison are one answer, for the whole
     * floor or for one salesperson.
     */
    public function growth(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $asked = (string) $request->query('period', 'month');
        $period = array_key_exists($asked, self::PERIODS) ? $asked : 'month';
        $size = self::PERIODS[$period];
        $points = min(24, max(2, (int) $request->query('points', match ($period) {
            'month' => 12,
            'quarter' => 8,
            'half' => 6,
            default => 5,
        })));

        // The same two ledgers as every other money screen.
        $scope = $request->query('scope') === 'mine' ? 'mine' : 'team';
        $window = $me->salesWindow($scope);
        $optionsWindow = $window;
        $picked = null;

        if ($uuids = QueryList::of($request, 'salesperson')) {
            $members = Member::where('organization_id', $org->id)->whereIn('uuid', $uuids)->get(['id', 'uuid']);
            // Narrowing only: nobody reaches outside their own window here.
            $inside = $members->filter(fn (Member $m) => $optionsWindow === null || in_array($m->id, $optionsWindow, true));
            $window = $inside->isEmpty() ? [0] : $inside->pluck('id')->all();
            // The response names the one person picked; several have no one name.
            $picked = $inside->count() === 1 && count($uuids) === 1 ? $inside->first()->uuid : null;
        }

        // Buckets run forward to the one today sits in.
        $last = $this->bucketStart(now(), $size);
        $starts = collect(range($points - 1, 0))
            ->map(fn ($back) => $last->copy()->subMonths($back * $size))
            ->values();

        // Read a year further back so every bucket has last year's twin.
        $from = $starts->first()->copy()->subYear();
        $to = $last->copy()->addMonths($size)->subDay();

        $userWindow = $window === null ? null : Member::whereIn('id', $window)->pluck('user_id')->all();
        $invoices = Invoice::where('organization_id', $org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            // Older paperwork predates automatic attribution, so the person
            // who wrote it still stands in for the salesperson.
            ->when($window !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('member_id', $window)
                ->orWhere(fn ($x) => $x->whereNull('member_id')->whereIn('created_by', $userWindow))))
            ->get(['invoice_date', 'total', 'client_id']);

        $sums = [];
        foreach ($invoices as $invoice) {
            $key = $this->bucketKey($invoice->invoice_date, $size);
            $sums[$key] ??= ['achieved' => 0.0, 'invoices' => 0, 'clients' => []];
            $sums[$key]['achieved'] += (float) $invoice->total;
            $sums[$key]['invoices']++;
            $sums[$key]['clients'][$invoice->client_id] = true;
        }

        $targetByBucket = [];
        $targetRows = Target::selectRaw('year, month, sum(target_amount) as target_sum')
            ->where('organization_id', $org->id)
            ->when($window !== null, fn ($q) => $q->whereIn('member_id', $window))
            ->groupBy('year', 'month')
            ->get();
        foreach ($targetRows as $t) {
            $key = $this->bucketKey(Carbon::create($t->year, $t->month, 1), $size);
            $targetByBucket[$key] = ($targetByBucket[$key] ?? 0) + (float) $t->target_sum;
        }

        $buckets = $starts->map(function (Carbon $bucketStart) use ($size, $sums, $targetByBucket) {
            $key = $this->bucketKey($bucketStart, $size);
            $lastYearKey = $this->bucketKey($bucketStart->copy()->subYear(), $size);
            $achieved = round($sums[$key]['achieved'] ?? 0, 2);
            $lastYear = round($sums[$lastYearKey]['achieved'] ?? 0, 2);

            return [
                'key' => $key,
                'label' => $this->bucketLabel($bucketStart, $size),
                'achieved' => $achieved,
                'target' => round($targetByBucket[$key] ?? 0, 2),
                'clients' => count($sums[$key]['clients'] ?? []),
                'invoices' => $sums[$key]['invoices'] ?? 0,
                'last_year' => $lastYear,
                // A rise from nothing has no percentage worth printing.
                'yoy' => $lastYear > 0 ? round(($achieved - $lastYear) / $lastYear * 100, 1) : null,
            ];
        })->values();

        // Growth against the bucket before it — the trend itself.
        $buckets = $buckets->map(function (array $bucket, int $i) use ($buckets) {
            $prev = $i > 0 ? (float) $buckets[$i - 1]['achieved'] : null;
            $bucket['previous'] = $prev;
            $bucket['growth'] = $prev !== null && $prev > 0
                ? round(($bucket['achieved'] - $prev) / $prev * 100, 1)
                : null;

            return $bucket;
        })->values();

        // One client billed in three buckets is one client over the window.
        $clientsSeen = [];
        foreach ($buckets as $bucket) {
            $clientsSeen += $sums[$bucket['key']]['clients'] ?? [];
        }

        $totalThis = round($buckets->sum('achieved'), 2);
        $totalLast = round($buckets->sum('last_year'), 2);
        $best = $buckets->sortByDesc('achieved')->first();

        return response()->json(['data' => [
            'period' => $period,
            'points' => $points,
            'scope' => $scope,
            'salesperson' => $picked,
            'salespeople' => $scope === 'team'
                ? Member::visible()->with('user:id,name')
                    ->where('organization_id', $org->id)
                    ->where('status', 'active')
                    ->when($optionsWindow !== null, fn ($q) => $q->whereIn('id', $optionsWindow))
                    ->get()
                    ->map(fn (Member $m) => ['uuid' => $m->uuid, 'name' => $m->user?->name, 'is_me' => $m->id === $me->id])
                    ->values()
                : null,
            'buckets' => $buckets,
            'totals' => [
                'achieved' => $totalThis,
                'last_year' => $totalLast,
                'yoy' => $totalLast > 0 ? round(($totalThis - $totalLast) / $totalLast * 100, 1) : null,
                'clients' => count($clientsSeen),
                'best' => ($best && $best['achieved'] > 0) ? $best['label'] : null,
            ],
        ]]);
    }

    /** What a document sold, before tax, in rupees. */
    /**
     * What each desk billed between two dates, and to how many clients.
     *
     * Lifted out so the same reading can be taken of the span before this
     * one. A comparison drawn from a second, slightly different query is a
     * comparison nobody can trust: the two halves have to be the same
     * question asked twice.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function achievementBetween($org, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        /*
         * Summed in PHP rather than by the database, because a document in
         * another currency counts at the INR equivalent frozen on it - the
         * rule every other money screen keeps. sum(total) in SQL would add a
         * dollar to a rupee and call the answer two.
         */
        $invoices = Invoice::where('organization_id', $org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $start->toDateString())
            ->whereDate('invoice_date', '<=', $end->toDateString())
            ->whereNotNull('member_id')
            ->withSum('payments as received', 'amount')
            ->get(['id', 'member_id', 'client_id', 'client_category', 'subtotal', 'discount', ...Invoice::RUPEE_COLUMNS]);

        return $invoices->groupBy('member_id')->map(function ($group) {
            $existing = $group->filter(fn ($i) => in_array($i->client_category, self::EXISTING, true));
            $clients = fn ($rows) => $rows->pluck('client_id')->filter()->unique()->count();

            return [
                // A sale is what was sold, not what the government adds to
                // it: the target is judged on the taxable value, and the tax
                // only shows up in what the client still owes.
                'base_sum' => round((float) $group->sum(fn ($i) => self::baseOf($i)), 2),
                'base_existing' => round((float) $existing->sum(fn ($i) => self::baseOf($i)), 2),
                'payment_due' => round((float) $group->sum(fn ($i) => self::dueOf($i)), 2),
                'invoice_count' => $group->count(),
                'client_count' => $clients($group),
                'client_existing' => $clients($existing),
                'client_new' => $clients($group->reject(fn ($i) => in_array($i->client_category, self::EXISTING, true))),
            ];
        });
    }

    /**
     * One number against the one before it, as a percentage.
     *
     * Null rather than a figure when there is nothing to compare against:
     * "up 100%" from zero is arithmetic, not news, and a first month reading
     * as a triumph teaches people to ignore the column.
     */
    private static function growthOf(float $now, float $before): ?float
    {
        if ($before <= 0) {
            return null;
        }

        return round(($now - $before) / $before * 100, 1);
    }

    private static function baseOf(Invoice $invoice): float
    {
        return $invoice->inRupees((float) $invoice->subtotal - (float) ($invoice->discount ?? 0));
    }

    /** What is still owed on a document, tax included, in rupees. */
    private static function dueOf(Invoice $invoice): float
    {
        return $invoice->inRupees(max(0, (float) $invoice->total - (float) ($invoice->received ?? 0)));
    }

    /**
     * One salesperson's own standing, this month and the three before it.
     *
     * Read by the strip that rides the top of every CRM screen, so somebody
     * carrying a target never has to go and look for it. Quiet - no target in
     * any of the four months means nothing to show, and the strip draws
     * nothing at all.
     */
    public function mine(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $months = collect(range(3, 0))->map(fn ($back) => now()->startOfMonth()->subMonthsNoOverflow($back));

        $rows = $months->map(function (Carbon $month) use ($org, $me) {
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $target = Target::where('organization_id', $org->id)
                ->where('member_id', $me->id)
                ->where('year', $month->year)->where('month', $month->month)
                ->first();

            $invoices = Invoice::where('organization_id', $org->id)
                ->where('kind', 'invoice')
                ->where('status', '!=', 'cancelled')
                ->where('member_id', $me->id)
                ->whereDate('invoice_date', '>=', $start->toDateString())
                ->whereDate('invoice_date', '<=', $end->toDateString())
                ->withSum('payments as received', 'amount')
                ->get(['id', 'client_id', 'client_category', 'subtotal', 'discount', ...Invoice::RUPEE_COLUMNS]);

            $isNew = fn ($i) => ! in_array($i->client_category, self::EXISTING, true);
            $heads = fn ($rows) => $rows->pluck('client_id')->filter()->unique()->count();

            $achieved = round((float) $invoices->sum(fn ($i) => self::baseOf($i)), 2);
            $clientTarget = (int) ($target?->client_target ?? 0);
            $kind = $target?->kind ?: ($me->target_kind ?: 'sales');
            $amount = (float) ($target?->target_amount ?? 0);

            return [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'is_current' => $month->isSameMonth(now()),
                'kind' => $kind,
                'target' => round($amount, 2),
                'achieved' => $achieved,
                'pending_target' => round(max(0, $amount - $achieved), 2),
                'payment_due' => round((float) $invoices->sum(fn ($i) => self::dueOf($i)), 2),
                'percent' => $amount > 0 ? round($achieved / $amount * 100, 1) : null,
                'clients' => $heads($invoices),
                'client_target' => $clientTarget,
                // A client target is this month's new clients; the rest of
                // the month's head count rides beside it.
                'clients_new' => $heads($invoices->filter($isNew)),
                'clients_existing' => $heads($invoices->reject($isNew)),
                'client_percent' => $clientTarget > 0
                    ? round($heads($invoices->filter($isNew)) / $clientTarget * 100, 1)
                    : null,
            ];
        })->values();

        $current = $rows->last();
        $best = $rows->sortByDesc(fn ($r) => $r['kind'] === 'clients' ? $r['clients_new'] : $r['achieved'])->first();

        return response()->json(['data' => [
            // Nothing asked of this desk in four months: the strip stays away.
            'has_target' => $rows->contains(fn ($r) => $r['target'] > 0 || $r['client_target'] > 0),
            'kind' => $current['kind'],
            'months' => $rows,
            'current' => $current,
            'best' => $best && ($best['achieved'] > 0 || $best['clients_new'] > 0) ? $best['label'] : null,
        ]]);
    }

    /** The first day of the bucket a date falls in. */
    private function bucketStart(Carbon $date, int $size): Carbon
    {
        $index = intdiv($date->month - 1, $size);

        return Carbon::create($date->year, $index * $size + 1, 1)->startOfMonth();
    }

    private function bucketKey(Carbon $date, int $size): string
    {
        $start = $this->bucketStart($date, $size);

        return $size === 12 ? (string) $start->year : $start->format('Y-m');
    }

    private function bucketLabel(Carbon $start, int $size): string
    {
        if ($size === 1) {
            return $start->format('M y');
        }
        if ($size === 12) {
            return (string) $start->year;
        }

        return $start->format('M') . '–' . $start->copy()->addMonths($size - 1)->format('M y');
    }

    /** Set or update many targets for one month in a single save. */
    public function upsert(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.member_uuid' => ['required', 'string'],
            'targets.*.target_amount' => ['required', 'numeric', 'min:0'],
            // A client-oriented desk is given a number of clients instead.
            'targets.*.kind' => ['nullable', Rule::in(Target::KINDS)],
            'targets.*.client_target' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'targets.*.note' => ['nullable', 'string', 'max:512'],
        ]);

        DB::transaction(function () use ($org, $data, $request) {
            foreach ($data['targets'] as $row) {
                $member = Member::where('organization_id', $org->id)
                    ->where('uuid', $row['member_uuid'])
                    ->firstOrFail();

                $kind = $row['kind'] ?? ($member->target_kind ?: 'sales');

                Target::updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'member_id' => $member->id,
                        'year' => $data['year'],
                        'month' => $data['month'],
                    ],
                    [
                        'target_amount' => $row['target_amount'],
                        'kind' => $kind,
                        'client_target' => (int) ($row['client_target'] ?? 0),
                        'note' => $row['note'] ?? null,
                        'created_by' => $request->user()->id,
                    ],
                );

                // What this desk is judged on from now on, so next month's
                // row starts the way this one was set.
                if ($member->target_kind !== $kind) {
                    $member->update(['target_kind' => $kind]);
                }
            }
        });

        return response()->json(['message' => 'Targets saved.']);
    }

    /** Start a month by copying the previous month's numbers. */
    public function copyPrevious(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $prev = Carbon::create($data['year'], $data['month'], 1)->subMonth();
        $source = Target::where('organization_id', $org->id)
            ->where('year', $prev->year)->where('month', $prev->month)
            ->get();

        if ($source->isEmpty()) {
            abort(422, 'The previous month has no targets to copy.');
        }

        $copied = 0;
        DB::transaction(function () use ($source, $org, $data, $request, &$copied) {
            foreach ($source as $t) {
                $created = Target::firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'member_id' => $t->member_id,
                        'year' => $data['year'],
                        'month' => $data['month'],
                    ],
                    [
                        'target_amount' => $t->target_amount,
                        'kind' => $t->kind,
                        'client_target' => $t->client_target,
                        'created_by' => $request->user()->id,
                    ],
                );
                if ($created->wasRecentlyCreated) {
                    $copied++;
                }
            }
        });

        return response()->json(['message' => $copied . ' targets copied from ' . $prev->format('F Y') . '.']);
    }
}
